<?php

namespace App\Support\Stores;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Support\Network\DnsClient;
use App\Support\Network\SafeHttpFetcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreVerificationService
{
    public const ERROR_DNS_RECORD_MISSING = 'dns_record_missing';

    public const ERROR_CHALLENGE_MISMATCH = 'challenge_mismatch';

    public const ERROR_STORE_URL_CHANGED = 'store_url_changed';

    public function __construct(
        private readonly DnsClient $dns,
        private readonly SafeHttpFetcher $fetcher,
    ) {}

    public function start(Store $store, string $method): StoreVerification
    {
        $now = Carbon::now();
        $expiresAt = $method === StoreVerification::METHOD_DNS
            ? $now->copy()->addHours((int) config('watchdog.store_verification.dns_ttl_hours'))
            : $now->copy()->addMinutes((int) config('watchdog.store_verification.plugin_ttl_minutes'));
        $id = (string) Str::uuid7();

        return StoreVerification::query()->forceCreate([
            'id' => $id,
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'method' => $method,
            'challenge_hash' => hash('sha256', $this->challenge($id)),
            'verified_origin' => $store->base_url,
            'status' => StoreVerification::STATUS_PENDING,
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'created_at' => $now,
        ]);
    }

    public function challenge(string $verificationId): string
    {
        return 'bw-'.substr(hash_hmac('sha256', 'store-verification:'.$verificationId, (string) config('app.key')), 0, 40);
    }

    /**
     * @return array<string, string>
     */
    public function instructions(StoreVerification $verification, Store $store): array
    {
        $challenge = $this->challenge($verification->id);

        if ($verification->method === StoreVerification::METHOD_DNS) {
            return [
                'type' => 'dns_txt',
                'record_name' => $this->dnsRecordName($store),
                'txt_value' => $challenge,
            ];
        }

        return [
            'type' => 'plugin_challenge',
            'url' => $this->challengeUrl($verification, $store),
            'body' => $challenge,
        ];
    }

    public function challengeUrl(StoreVerification $verification, Store $store): string
    {
        $paths = (array) config('watchdog.store_verification.challenge_paths');
        $provider = Integration::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('source_authority', Integration::SOURCE_STORE_REPORTED)
            ->where('status', Integration::STATUS_ACTIVE)
            ->value('provider');
        $path = $paths[$provider ?? ''] ?? $paths['default'];

        return rtrim($verification->verified_origin, '/').str_replace('{id}', $verification->id, $path);
    }

    public function check(StoreVerification $verification): StoreVerification
    {
        if ($verification->status !== StoreVerification::STATUS_PENDING) {
            return $verification;
        }

        $now = Carbon::now();

        if ($verification->expires_at->lessThanOrEqualTo($now)) {
            $verification->forceFill(['status' => StoreVerification::STATUS_EXPIRED])->save();

            return $verification;
        }

        /** @var Store $store */
        $store = Store::query()->where('tenant_id', $verification->tenant_id)->whereKey($verification->store_id)->firstOrFail();

        if ($store->base_url !== $verification->verified_origin) {
            $verification->forceFill([
                'status' => StoreVerification::STATUS_FAILED,
                'last_error_code' => self::ERROR_STORE_URL_CHANGED,
                'last_checked_at' => $now,
            ])->save();

            return $verification;
        }

        $errorCode = $verification->method === StoreVerification::METHOD_DNS
            ? $this->checkDns($verification, $store)
            : $this->checkPluginChallenge($verification, $store);

        if ($errorCode !== null) {
            $verification->forceFill([
                'attempts' => $verification->attempts + 1,
                'last_checked_at' => $now,
                'last_error_code' => $errorCode,
            ])->save();

            return $verification;
        }

        return $this->markVerified($verification, $store, $now);
    }

    private function checkDns(StoreVerification $verification, Store $store): ?string
    {
        $expected = $this->challenge($verification->id);

        foreach ($this->dns->txtRecords($this->dnsRecordName($store)) as $value) {
            if (hash_equals($expected, trim($value, " \t\n\r\0\x0B\""))) {
                return null;
            }
        }

        return self::ERROR_DNS_RECORD_MISSING;
    }

    private function checkPluginChallenge(StoreVerification $verification, Store $store): ?string
    {
        $result = $this->fetcher->getSmallText($this->challengeUrl($verification, $store));

        if (! $result->ok) {
            return $result->errorCode;
        }

        return hash_equals($verification->challenge_hash, hash('sha256', trim((string) $result->body)))
            ? null
            : self::ERROR_CHALLENGE_MISMATCH;
    }

    private function markVerified(StoreVerification $verification, Store $store, Carbon $now): StoreVerification
    {
        return DB::transaction(function () use ($verification, $store, $now): StoreVerification {
            /** @var Store $lockedStore */
            $lockedStore = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            /** @var StoreVerification $locked */
            $locked = StoreVerification::query()->whereKey($verification->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StoreVerification::STATUS_PENDING || $lockedStore->base_url !== $locked->verified_origin) {
                return $locked;
            }

            $locked->forceFill([
                'status' => StoreVerification::STATUS_VERIFIED,
                'verified_at' => $now,
                'attempts' => $locked->attempts + 1,
                'last_checked_at' => $now,
                'last_error_code' => null,
            ])->save();

            StoreVerification::query()
                ->where('tenant_id', $lockedStore->tenant_id)
                ->where('store_id', $lockedStore->id)
                ->where('status', StoreVerification::STATUS_PENDING)
                ->whereKeyNot($locked->id)
                ->update(['status' => StoreVerification::STATUS_EXPIRED]);

            $previousStatus = $lockedStore->status;
            $lockedStore->forceFill([
                'verified_at' => $now,
                'status' => $lockedStore->status === 'onboarding' ? 'active' : $lockedStore->status,
            ])->save();

            AuditLog::query()->create([
                'tenant_id' => $lockedStore->tenant_id,
                'store_id' => $lockedStore->id,
                'actor_user_id' => null,
                'actor_type' => 'system',
                'action' => AuditLog::ACTION_STORE_VERIFIED,
                'entity_type' => AuditLog::ENTITY_STORE,
                'entity_id' => $lockedStore->id,
                'changes' => [
                    'method' => $locked->method,
                    'verified_origin' => $locked->verified_origin,
                    'status' => ['from' => $previousStatus, 'to' => $lockedStore->status],
                ],
                'request_id' => (string) Str::uuid(),
                'created_at' => $now,
            ]);

            return $locked->refresh();
        });
    }

    private function dnsRecordName(Store $store): string
    {
        return config('watchdog.store_verification.dns_record_prefix').'.'.strtolower((string) parse_url($store->base_url, PHP_URL_HOST));
    }
}
