<?php

namespace App\Support\Notifications;

use App\Exceptions\Notifications\NotificationChannelRejected;
use App\Mail\NotificationMessageMail;
use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\NotificationChannelVerification;
use App\Models\NotificationDelivery;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Security\Keyring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationChannelService
{
    public function __construct(
        private readonly NotificationRenderer $renderer,
        private readonly IncidentNotificationContentBuilder $contentBuilder,
        private readonly Keyring $keyring,
    ) {}

    /**
     * @param  array<string, mixed>  $preferences
     */
    public function createEmailChannel(Tenant $tenant, string $label, string $email, array $preferences, ?string $actorId): NotificationChannel
    {
        $preferences = $this->normalizePreferences($tenant->id, $preferences);
        $now = Carbon::now();

        $encrypted = $this->keyring->encrypt($email);

        [$channel, $code] = DB::transaction(function () use ($tenant, $label, $encrypted, $preferences, $actorId, $now): array {
            $channel = NotificationChannel::query()->create([
                'tenant_id' => $tenant->id,
                'kind' => NotificationChannel::KIND_EMAIL,
                'destination_ciphertext' => $encrypted['ciphertext'],
                'key_version' => $encrypted['key_version'],
                'label' => $label,
                'enabled' => false,
                'preferences' => $preferences,
                'health' => [],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $code = $this->issueVerification($channel);
            $this->audit($channel, $actorId, AuditLog::ACTION_NOTIFICATION_CHANNEL_CREATED, [
                'kind' => NotificationChannel::KIND_EMAIL,
                'label' => $label,
            ]);

            return [$channel, $code];
        });

        $this->sendVerificationCode($channel, $email, $code, $tenant);

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(NotificationChannel $channel, Tenant $tenant, array $changes, ?string $actorId): NotificationChannel
    {
        $newEmail = $changes['destination_email'] ?? null;

        if (array_key_exists('preferences', $changes)) {
            $changes['preferences'] = $this->normalizePreferences(
                $tenant->id,
                array_merge($channel->preferences ?? [], $changes['preferences'] ?? []),
            );
        }

        [$updated, $code] = DB::transaction(function () use ($channel, $changes, $newEmail, $actorId): array {
            /** @var NotificationChannel $locked */
            $locked = NotificationChannel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();
            $audit = [];
            $code = null;

            if (isset($changes['label']) && $changes['label'] !== $locked->label) {
                $audit['label'] = ['from' => $locked->label, 'to' => $changes['label']];
                $locked->label = $changes['label'];
            }

            if (isset($changes['preferences'])) {
                $audit['preferences'] = ['from' => $locked->preferences, 'to' => $changes['preferences']];
                $locked->preferences = $changes['preferences'];
            }

            if (is_string($newEmail)) {
                $encrypted = $this->keyring->encrypt($newEmail);
                $locked->destination_ciphertext = $encrypted['ciphertext'];
                $locked->key_version = $encrypted['key_version'];
                $locked->verified_at = null;
                $locked->enabled = false;
                $audit['destination'] = 'replaced_requires_verification';
                $code = $this->issueVerification($locked);
            }

            if (array_key_exists('enabled', $changes) && ! is_string($newEmail)) {
                $enabled = (bool) $changes['enabled'];

                if ($enabled && $locked->verified_at === null) {
                    throw new NotificationChannelRejected('channel_not_verified');
                }

                if ($enabled !== $locked->enabled) {
                    $audit['enabled'] = ['from' => $locked->enabled, 'to' => $enabled];
                    $locked->enabled = $enabled;
                }
            }

            $locked->updated_at = Carbon::now();
            $locked->save();

            if ($audit !== []) {
                $this->audit($locked, $actorId, AuditLog::ACTION_NOTIFICATION_CHANNEL_UPDATED, $audit);
            }

            return [$locked, $code];
        });

        if (is_string($newEmail) && is_string($code)) {
            $this->sendVerificationCode($updated, $newEmail, $code, $tenant);
        }

        return $updated->refresh();
    }

    public function verify(NotificationChannel $channel, string $code, ?string $actorId): NotificationChannel
    {
        $result = DB::transaction(function () use ($channel, $code, $actorId): NotificationChannel|NotificationChannelRejected {
            /** @var NotificationChannel $locked */
            $locked = NotificationChannel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();

            if ($locked->verified_at !== null) {
                return new NotificationChannelRejected('channel_already_verified');
            }

            /** @var NotificationChannelVerification|null $verification */
            $verification = NotificationChannelVerification::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('channel_id', $locked->id)
                ->whereNull('consumed_at')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($verification === null || $verification->expires_at->isPast()) {
                return new NotificationChannelRejected('verification_code_expired');
            }

            if ($verification->attempts >= NotificationChannelVerification::MAX_ATTEMPTS) {
                return new NotificationChannelRejected('verification_attempts_exceeded');
            }

            if (! hash_equals($verification->code_hash, $this->hashCode($code))) {
                $verification->forceFill(['attempts' => $verification->attempts + 1])->save();

                return new NotificationChannelRejected('verification_code_invalid');
            }

            $now = Carbon::now();
            $verification->forceFill(['consumed_at' => $now])->save();
            $locked->forceFill([
                'verified_at' => $now,
                'enabled' => true,
                'updated_at' => $now,
            ])->save();

            $this->audit($locked, $actorId, AuditLog::ACTION_NOTIFICATION_CHANNEL_VERIFIED, [
                'enabled' => ['from' => false, 'to' => true],
            ]);

            return $locked->refresh();
        });

        if ($result instanceof NotificationChannelRejected) {
            throw $result;
        }

        return $result;
    }

    public function sendTest(NotificationChannel $channel, Tenant $tenant): NotificationDelivery
    {
        if (! $channel->isDeliverable()) {
            throw new NotificationChannelRejected('channel_not_verified');
        }

        $now = Carbon::now();
        $preferences = NotificationPreferences::fromArray($channel->preferences ?? [], (string) $tenant->locale, (string) $tenant->timezone);

        $delivery = NotificationDelivery::query()->firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                'channel_id' => $channel->id,
                'dedupe_key' => 'test:'.$now->copy()->setTimezone('UTC')->format('YmdHi'),
            ],
            [
                'incident_id' => null,
                'notification_kind' => NotificationDelivery::KIND_TEST,
                'incident_revision' => null,
                'template_version' => IncidentNotificationContentBuilder::TEST_TEMPLATE_VERSION,
                'sanitized_content' => $this->contentBuilder->forTest($channel->label, $preferences),
                'status' => NotificationDelivery::STATUS_QUEUED,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
            ],
        );

        if (! $delivery->wasRecentlyCreated) {
            throw new NotificationChannelRejected('test_rate_limited');
        }

        return $delivery;
    }

    public static function maskDestination(NotificationChannel $channel): ?string
    {
        try {
            $destination = app(Keyring::class)->decrypt($channel->destination_ciphertext, $channel->key_version);
        } catch (\Throwable) {
            return null;
        }

        if ($channel->kind !== NotificationChannel::KIND_EMAIL || ! str_contains($destination, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $destination, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    private function normalizePreferences(string $tenantId, array $preferences): array
    {
        foreach (['critical_bypasses_quiet_hours', 'notify_recovery'] as $flag) {
            if (array_key_exists($flag, $preferences)) {
                $preferences[$flag] = (bool) $preferences[$flag];
            }
        }

        if (isset($preferences['store_ids']) && is_array($preferences['store_ids'])) {
            $storeIds = array_values(array_unique(array_map('strval', $preferences['store_ids'])));
            $known = Store::query()->where('tenant_id', $tenantId)->whereIn('id', $storeIds)->count();

            if ($known !== count($storeIds)) {
                throw new NotificationChannelRejected('store_not_found');
            }

            $preferences['store_ids'] = $storeIds;
        }

        return $preferences;
    }

    private function issueVerification(NotificationChannel $channel): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = Carbon::now();

        NotificationChannelVerification::query()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->whereNull('consumed_at')
            ->update(['expires_at' => $now]);

        NotificationChannelVerification::query()->create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'code_hash' => $this->hashCode($code),
            'attempts' => 0,
            'expires_at' => $now->copy()->addMinutes(NotificationChannelVerification::TTL_MINUTES),
            'created_at' => $now,
        ]);

        return $code;
    }

    private function sendVerificationCode(NotificationChannel $channel, string $email, string $code, Tenant $tenant): void
    {
        $preferences = NotificationPreferences::fromArray($channel->preferences ?? [], (string) $tenant->locale, (string) $tenant->timezone);
        $message = $this->renderer->renderVerification($code, $preferences->locale);

        Mail::to($email)->send(new NotificationMessageMail($message->subject, $message->body));
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(NotificationChannel $channel, ?string $actorId, string $action, array $changes): void
    {
        AuditLog::query()->create([
            'tenant_id' => $channel->tenant_id,
            'store_id' => null,
            'actor_user_id' => $actorId,
            'actor_type' => AuditLog::ACTOR_USER,
            'action' => $action,
            'entity_type' => AuditLog::ENTITY_NOTIFICATION_CHANNEL,
            'entity_id' => $channel->id,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
