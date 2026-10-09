<?php

namespace App\Support\Notifications;

use App\Models\Incident;
use Illuminate\Support\Carbon;

final class NotificationPreferences
{
    public const LOCALES = ['ru', 'en', 'de'];

    public const SEVERITIES = [Incident::SEVERITY_INFO, Incident::SEVERITY_WARNING, Incident::SEVERITY_CRITICAL];

    private const SEVERITY_RANK = [
        Incident::SEVERITY_INFO => 0,
        Incident::SEVERITY_WARNING => 1,
        Incident::SEVERITY_CRITICAL => 2,
    ];

    /**
     * @param  array{start: string, end: string}|null  $quietHours
     * @param  list<string>|null  $storeIds
     */
    private function __construct(
        public readonly string $minSeverity,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly ?array $quietHours,
        public readonly bool $criticalBypassesQuietHours,
        public readonly bool $notifyRecovery,
        public readonly ?array $storeIds,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, string $defaultLocale, string $defaultTimezone): self
    {
        $quietHours = $raw['quiet_hours'] ?? null;

        return new self(
            minSeverity: in_array($raw['min_severity'] ?? null, self::SEVERITIES, true)
                ? $raw['min_severity']
                : Incident::SEVERITY_WARNING,
            locale: in_array($raw['locale'] ?? null, self::LOCALES, true)
                ? $raw['locale']
                : (in_array($defaultLocale, self::LOCALES, true) ? $defaultLocale : 'en'),
            timezone: is_string($raw['timezone'] ?? null) && in_array($raw['timezone'], timezone_identifiers_list(), true)
                ? $raw['timezone']
                : $defaultTimezone,
            quietHours: is_array($quietHours) && isset($quietHours['start'], $quietHours['end'])
                ? ['start' => (string) $quietHours['start'], 'end' => (string) $quietHours['end']]
                : null,
            criticalBypassesQuietHours: ($raw['critical_bypasses_quiet_hours'] ?? false) === true,
            notifyRecovery: ($raw['notify_recovery'] ?? true) !== false,
            storeIds: isset($raw['store_ids']) && is_array($raw['store_ids'])
                ? array_values(array_map('strval', $raw['store_ids']))
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'min_severity' => $this->minSeverity,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'quiet_hours' => $this->quietHours,
            'critical_bypasses_quiet_hours' => $this->criticalBypassesQuietHours,
            'notify_recovery' => $this->notifyRecovery,
            'store_ids' => $this->storeIds,
        ];
    }

    public function allowsSeverity(string $severity): bool
    {
        return (self::SEVERITY_RANK[$severity] ?? 0) >= self::SEVERITY_RANK[$this->minSeverity];
    }

    public function allowsStore(string $storeId): bool
    {
        return $this->storeIds === null || in_array($storeId, $this->storeIds, true);
    }

    public function nextAllowedAt(Carbon $now, string $severity): Carbon
    {
        if ($this->quietHours === null) {
            return $now->copy();
        }

        if ($severity === Incident::SEVERITY_CRITICAL && $this->criticalBypassesQuietHours) {
            return $now->copy();
        }

        $start = self::minutesOfDay($this->quietHours['start']);
        $end = self::minutesOfDay($this->quietHours['end']);

        if ($start === null || $end === null || $start === $end) {
            return $now->copy();
        }

        $local = $now->copy()->setTimezone($this->timezone);
        $minute = $local->hour * 60 + $local->minute;
        $inQuiet = $start < $end
            ? ($minute >= $start && $minute < $end)
            : ($minute >= $start || $minute < $end);

        if (! $inQuiet) {
            return $now->copy();
        }

        $windowEnd = $local->copy()->setTime(intdiv($end, 60), $end % 60);

        if ($windowEnd->lessThanOrEqualTo($local)) {
            $windowEnd = $local->copy()->addDay()->setTime(intdiv($end, 60), $end % 60);
        }

        return $windowEnd->setTimezone('UTC');
    }

    public static function minutesOfDay(string $value): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }
}
