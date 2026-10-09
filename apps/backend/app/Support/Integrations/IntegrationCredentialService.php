<?php

namespace App\Support\Integrations;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Support\Security\Keyring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationCredentialService
{
    public const HEALTH_ROTATION_REQUESTED_AT = 'credential_rotation_requested_at';

    public function __construct(
        private readonly Keyring $keyring,
    ) {}

    /**
     * @return array{credential: IntegrationCredential, secret: string}
     */
    public function issuePluginCredential(Integration $integration): array
    {
        $secret = random_bytes(32);
        $secretBase64 = base64_encode($secret);
        $encrypted = $this->keyring->encrypt($secretBase64);

        $credential = IntegrationCredential::query()->create([
            'tenant_id' => $integration->tenant_id,
            'store_id' => $integration->store_id,
            'integration_id' => $integration->id,
            'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
            'key_id' => 'bwk_'.Str::lower(Str::random(32)),
            'ciphertext' => $encrypted['ciphertext'],
            'key_version' => $encrypted['key_version'],
            'fingerprint' => hash('sha256', $secret),
            'status' => IntegrationCredential::STATUS_ACTIVE,
            'created_at' => Carbon::now(),
        ]);

        return ['credential' => $credential, 'secret' => $secretBase64];
    }

    public function secretFor(IntegrationCredential $credential): string
    {
        return $this->keyring->decrypt($credential->ciphertext, $credential->key_version);
    }

    public function requestRotation(Integration $integration, ?string $actorId): Integration
    {
        return DB::transaction(function () use ($integration, $actorId): Integration {
            /** @var Integration $locked */
            $locked = Integration::query()->whereKey($integration->id)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();

            $locked->forceFill([
                'health' => array_merge($locked->health ?? [], [self::HEALTH_ROTATION_REQUESTED_AT => $now->toJSON()]),
                'updated_at' => $now,
            ])->save();

            $this->audit($locked, AuditLog::ACTOR_USER, $actorId, AuditLog::ACTION_INTEGRATION_CREDENTIAL_ROTATION_REQUESTED, [
                'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @return array{credential: IntegrationCredential, secret: string}
     */
    public function rotatePluginCredential(Integration $integration, IntegrationCredential $signer): array
    {
        return DB::transaction(function () use ($integration, $signer): array {
            /** @var Integration $locked */
            $locked = Integration::query()->whereKey($integration->id)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();

            IntegrationCredential::query()
                ->where('integration_id', $locked->id)
                ->where('kind', IntegrationCredential::KIND_PLUGIN_HMAC)
                ->where('status', IntegrationCredential::STATUS_ACTIVE)
                ->whereKeyNot($signer->id)
                ->update(['status' => IntegrationCredential::STATUS_REVOKED, 'rotated_at' => $now]);

            /** @var IntegrationCredential $lockedSigner */
            $lockedSigner = IntegrationCredential::query()->whereKey($signer->id)->lockForUpdate()->firstOrFail();

            if ($lockedSigner->status === IntegrationCredential::STATUS_ACTIVE) {
                $lockedSigner->forceFill([
                    'status' => IntegrationCredential::STATUS_DRAINING,
                    'expires_at' => $now->copy()->addHours((int) config('watchdog.credentials.draining_hours', 24)),
                    'rotated_at' => $now,
                ])->save();
            }

            $issued = $this->issuePluginCredential($locked);

            $health = $locked->health ?? [];
            unset($health[self::HEALTH_ROTATION_REQUESTED_AT]);
            $locked->forceFill(['health' => $health, 'updated_at' => $now])->save();

            $this->audit($locked, AuditLog::ACTOR_CONNECTOR, null, AuditLog::ACTION_INTEGRATION_CREDENTIAL_ROTATED, [
                'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
                'new_key_version' => $issued['credential']->key_version,
                'draining_until' => $lockedSigner->expires_at?->toJSON(),
            ]);

            return $issued;
        });
    }

    public function confirmActiveCredential(IntegrationCredential $active): void
    {
        if ($active->status !== IntegrationCredential::STATUS_ACTIVE) {
            return;
        }

        $hasDraining = IntegrationCredential::query()
            ->where('integration_id', $active->integration_id)
            ->where('status', IntegrationCredential::STATUS_DRAINING)
            ->exists();

        if (! $hasDraining) {
            return;
        }

        DB::transaction(function () use ($active): void {
            $revoked = IntegrationCredential::query()
                ->where('integration_id', $active->integration_id)
                ->where('status', IntegrationCredential::STATUS_DRAINING)
                ->update(['status' => IntegrationCredential::STATUS_REVOKED, 'rotated_at' => Carbon::now()]);

            if ($revoked > 0) {
                /** @var Integration $integration */
                $integration = Integration::query()->whereKey($active->integration_id)->firstOrFail();
                $this->audit($integration, AuditLog::ACTOR_CONNECTOR, null, AuditLog::ACTION_INTEGRATION_CREDENTIAL_DRAINED, [
                    'confirmed_by_key_version' => $active->key_version,
                    'revoked_count' => $revoked,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(Integration $integration, string $actorType, ?string $actorId, string $action, array $changes): void
    {
        AuditLog::query()->create([
            'tenant_id' => $integration->tenant_id,
            'store_id' => $integration->store_id,
            'actor_user_id' => $actorId,
            'actor_type' => $actorType,
            'action' => $action,
            'entity_type' => AuditLog::ENTITY_INTEGRATION,
            'entity_id' => $integration->id,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
