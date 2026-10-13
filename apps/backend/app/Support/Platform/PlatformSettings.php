<?php

namespace App\Support\Platform;

use App\Models\MaintenanceNotice;
use App\Models\PlatformStaff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PlatformSettings
{
    public const LOCALES = ['ru', 'en', 'de'];

    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $paddle = (array) config('watchdog.billing.paddle');
        $notices = MaintenanceNotice::query()->orderByDesc('version')->limit(20)->get();
        $staff = PlatformStaff::query()->with('user')->whereIn('id', $notices->pluck('created_by')->merge($notices->pluck('cancelled_by'))->filter()->unique())->get()->keyBy('id');

        return [
            'configuration' => [
                'environment' => (string) config('app.env'),
                'release' => (string) config('watchdog.release'),
                'app_url' => (string) config('app.url'),
                'billing' => [
                    'enforcement' => (string) config('watchdog.billing.enforcement'),
                    'paddle_environment' => (string) ($paddle['environment'] ?? ''),
                    'paddle_account' => (string) ($paddle['account_ref'] ?? ''),
                    'api_key' => $this->fingerprint($paddle['api_key'] ?? null),
                    'client_token' => $this->fingerprint($paddle['client_token'] ?? null),
                    'webhook_secret' => $this->fingerprint($paddle['webhook_secret'] ?? null),
                ],
                'mail' => [
                    'mailer' => (string) config('mail.default'),
                    'from' => (string) config('mail.from.address'),
                ],
                'telegram' => [
                    'bot' => (string) (config('services.telegram.bot_username') ?? ''),
                    'token' => $this->fingerprint(config('services.telegram.bot_token')),
                    'webhook_secret' => $this->fingerprint(config('services.telegram.webhook_secret')),
                ],
                'sessions' => ['idle_minutes' => (int) config('watchdog.platform.idle_minutes'), 'absolute_hours' => (int) config('watchdog.platform.absolute_hours')],
                'retention' => [
                    'screenshots_days' => (int) config('watchdog.artifacts.retention_days'),
                    'exports_days' => (int) config('watchdog.exports.retention_days'),
                    'contact_days' => (int) config('watchdog.site.contact_retention_days'),
                ],
                'site' => ['contact_notify' => $this->recipients((string) config('watchdog.site.contact_notify')), 'cache_seconds' => (int) config('watchdog.site.cache_seconds')],
            ],
            'maintenance' => [
                'current' => ($current = $this->current()) === null ? null : $this->notice($current, $staff),
                'history' => $notices->map(fn (MaintenanceNotice $notice): array => $this->notice($notice, $staff))->values()->all(),
                'limits' => ['max_hours' => MaintenanceNotice::MAX_HOURS, 'max_days_ahead' => MaintenanceNotice::MAX_DAYS_AHEAD],
            ],
        ];
    }

    public function current(?Carbon $now = null): ?MaintenanceNotice
    {
        $now ??= Carbon::now();

        /** @var MaintenanceNotice|null $notice */
        $notice = MaintenanceNotice::query()->whereNull('cancelled_at')->where('ends_at', '>', $now)->orderByDesc('version')->first();

        return $notice;
    }

    /**
     * @param  array<string, string>  $messages
     */
    public function schedule(PlatformStaff $actor, Carbon $startsAt, Carbon $endsAt, array $messages, string $reason): MaintenanceNotice
    {
        $now = Carbon::now();

        if ($endsAt->lessThanOrEqualTo($startsAt) || $endsAt->lessThanOrEqualTo($now) || $startsAt->diffInMinutes($endsAt) > MaintenanceNotice::MAX_HOURS * 60 || $startsAt->greaterThan($now->copy()->addDays(MaintenanceNotice::MAX_DAYS_AHEAD))) {
            throw new StaffRejected('maintenance_window_invalid');
        }

        $clean = [];

        foreach (self::LOCALES as $locale) {
            $text = trim((string) ($messages[$locale] ?? ''));

            if (mb_strlen($text) < 3 || mb_strlen($text) > 300) {
                throw new StaffRejected('maintenance_message_invalid');
            }

            $clean[$locale] = $text;
        }

        return DB::transaction(function () use ($actor, $startsAt, $endsAt, $clean, $reason, $now): MaintenanceNotice {
            $previous = MaintenanceNotice::query()->lockForUpdate()->orderByDesc('version')->first();
            MaintenanceNotice::query()->whereNull('cancelled_at')->where('ends_at', '>', $now)->update(['cancelled_at' => $now, 'cancelled_by' => $actor->id]);
            $notice = MaintenanceNotice::query()->create([
                'version' => ($previous?->version ?? 0) + 1,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'messages' => $clean,
                'created_by' => $actor->id,
                'reason' => $reason,
            ]);
            $this->audit->record($actor->id, 'settings.maintenance_scheduled', 'maintenance_notice', $notice->id, PlatformAudit::RESULT_SUCCEEDED, ['version' => $notice->version, 'starts_at' => $startsAt->toJSON(), 'ends_at' => $endsAt->toJSON()], $reason);

            return $notice;
        });
    }

    public function cancel(PlatformStaff $actor, string $reason): void
    {
        $notice = $this->current();

        if ($notice === null) {
            throw new StaffRejected('maintenance_not_scheduled', 409);
        }

        $notice->forceFill(['cancelled_at' => Carbon::now(), 'cancelled_by' => $actor->id])->save();
        $this->audit->record($actor->id, 'settings.maintenance_cancelled', 'maintenance_notice', $notice->id, PlatformAudit::RESULT_SUCCEEDED, ['version' => $notice->version], $reason);
    }

    /**
     * @return array{starts_at: string, ends_at: string, message: string, active: bool}|null
     */
    public function forCustomer(string $locale): ?array
    {
        $notice = $this->current();

        if ($notice === null) {
            return null;
        }

        $messages = $notice->messages;

        return [
            'starts_at' => $notice->starts_at->toJSON(),
            'ends_at' => $notice->ends_at->toJSON(),
            'message' => (string) ($messages[$locale] ?? $messages['en'] ?? reset($messages)),
            'active' => $notice->starts_at->isPast(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, PlatformStaff>  $staff
     * @return array<string, mixed>
     */
    private function notice(MaintenanceNotice $notice, $staff): array
    {
        return [
            'id' => $notice->id,
            'version' => $notice->version,
            'starts_at' => $notice->starts_at->toJSON(),
            'ends_at' => $notice->ends_at->toJSON(),
            'messages' => $notice->messages,
            'reason' => $notice->reason,
            'created_by' => $staff->get($notice->created_by)?->user?->name,
            'created_at' => $notice->created_at?->toJSON(),
            'cancelled_at' => $notice->cancelled_at?->toJSON(),
            'cancelled_by' => $notice->cancelled_by === null ? null : $staff->get($notice->cancelled_by)?->user?->name,
        ];
    }

    /**
     * @return array{configured: bool, fingerprint: ?string}
     */
    private function fingerprint(mixed $secret): array
    {
        $value = is_string($secret) ? trim($secret) : '';

        return ['configured' => $value !== '', 'fingerprint' => $value === '' ? null : 'sha256:'.substr(hash('sha256', $value), 0, 12)];
    }

    private function recipients(string $value): int
    {
        return count(array_filter(array_map('trim', explode(',', $value))));
    }
}
