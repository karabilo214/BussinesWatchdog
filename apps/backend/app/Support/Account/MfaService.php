<?php

namespace App\Support\Account;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\MfaRecoveryCode;
use App\Models\User;
use App\Support\Security\Keyring;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Authenticator-app second factor (ADR 0021): the secret is keyring-encrypted, enrollment needs a confirming code,
 * every code works once, and recovery codes are stored only as hashes and shown once.
 */
class MfaService
{
    public const ISSUER = 'Business Watchdog';

    public const RECOVERY_CODE_COUNT = 10;

    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(
        private readonly Totp $totp,
        private readonly Keyring $keyring,
    ) {}

    public function enabled(User $user): bool
    {
        return $user->mfa_confirmed_at !== null && $user->mfa_secret_ciphertext !== null;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return MfaRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count();
    }

    /**
     * Starts (or restarts) enrollment with a fresh secret; nothing changes for sign-in until it is confirmed.
     *
     * @return array{secret: string, otpauth_uri: string, qr_svg: string}
     */
    public function beginSetup(User $user): array
    {
        if ($this->enabled($user)) {
            throw new AccountRejected('mfa_already_enabled', 409);
        }

        $secret = $this->totp->generateSecret();
        $encrypted = $this->keyring->encrypt($secret);
        $user->forceFill([
            'mfa_secret_ciphertext' => $encrypted['ciphertext'],
            'mfa_key_version' => $encrypted['key_version'],
            'mfa_confirmed_at' => null,
            'mfa_last_used_step' => null,
        ])->save();

        $uri = $this->totp->uri($secret, self::ISSUER, $user->email);

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $this->qr($uri)];
    }

    /**
     * @return list<string> the recovery codes, shown to the user once
     */
    public function confirm(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code): array {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($this->enabled($locked)) {
                throw new AccountRejected('mfa_already_enabled', 409);
            }

            if ($locked->mfa_secret_ciphertext === null) {
                throw new AccountRejected('mfa_setup_missing');
            }

            $step = $this->totp->verify($this->secret($locked), trim($code), Carbon::now()->getTimestamp(), null);

            if ($step === null) {
                throw new AccountRejected('mfa_code_invalid');
            }

            $locked->forceFill(['mfa_confirmed_at' => Carbon::now(), 'mfa_last_used_step' => $step])->save();
            $codes = $this->replaceRecoveryCodes($locked);
            $this->audit($locked, 'user.mfa_enabled');
            $user->setRawAttributes($locked->getAttributes(), true);

            return $codes;
        });
    }

    /** A current authenticator code or an unused recovery code; either is consumed. */
    public function verify(User $user, ?string $code): bool
    {
        $code = trim((string) $code);

        if ($code === '') {
            return false;
        }

        return DB::transaction(function () use ($user, $code): bool {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $this->enabled($locked)) {
                return false;
            }

            $step = $this->totp->verify($this->secret($locked), $code, Carbon::now()->getTimestamp(), $locked->mfa_last_used_step === null ? null : (int) $locked->mfa_last_used_step);

            if ($step !== null) {
                $locked->forceFill(['mfa_last_used_step' => $step])->save();
                $user->setRawAttributes($locked->getAttributes(), true);

                return true;
            }

            $used = MfaRecoveryCode::query()
                ->where('user_id', $locked->id)
                ->where('code_hash', $this->hashRecoveryCode($code))
                ->whereNull('used_at')
                ->update(['used_at' => Carbon::now()]);

            if ($used === 1) {
                $this->audit($locked, 'user.mfa_recovery_code_used', ['remaining' => $this->remainingRecoveryCodes($locked)]);
            }

            return $used === 1;
        });
    }

    public function disable(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'mfa_secret_ciphertext' => null,
                'mfa_key_version' => null,
                'mfa_confirmed_at' => null,
                'mfa_last_used_step' => null,
            ])->save();
            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
            $this->audit($user, 'user.mfa_disabled');
        });
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $codes = $this->replaceRecoveryCodes($user);
            $this->audit($user, 'user.mfa_recovery_codes_regenerated');

            return $codes;
        });
    }

    /**
     * @return list<string>
     */
    private function replaceRecoveryCodes(User $user): array
    {
        MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = '';

            for ($j = 0; $j < 10; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }

            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
            MfaRecoveryCode::query()->create(['user_id' => $user->id, 'code_hash' => $this->hashRecoveryCode($raw)]);
        }

        return $codes;
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', preg_replace('/[^a-z0-9]/', '', mb_strtolower($code)) ?? '');
    }

    private function secret(User $user): string
    {
        return $this->keyring->decrypt((string) $user->mfa_secret_ciphertext, (int) $user->mfa_key_version);
    }

    private function qr(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /**
     * MFA belongs to the person, so the change is recorded in every team they are a member of.
     *
     * @param  array<string, mixed>  $changes
     */
    private function audit(User $user, string $action, array $changes = []): void
    {
        $requestId = (string) Str::uuid();

        foreach (Membership::query()->where('user_id', $user->id)->pluck('tenant_id') as $tenantId) {
            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => null,
                'actor_user_id' => $user->id,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'changes' => $changes,
                'request_id' => $requestId,
                'created_at' => Carbon::now(),
            ]);
        }
    }
}
