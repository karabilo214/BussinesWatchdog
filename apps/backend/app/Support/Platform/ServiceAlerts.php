<?php

namespace App\Support\Platform;

use App\Support\Account\AccountMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E-mail to the person responsible when the overall state of «Сервис» becomes "problem" and when it recovers (ADR 0051).
 * Only the change is written about, never every check: the last state an e-mail was sent for is kept, and a failed
 * e-mail is tried again on the next run. No address configured — nothing is sent.
 */
class ServiceAlerts
{
    public const PROBLEM = 'crit';

    public function __construct(
        private readonly ServiceHealth $health,
        private readonly AccountMailer $mailer,
        private readonly PlatformAudit $audit,
    ) {}

    /**
     * @return array{state: string, sent: string|null}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $report = $this->health->build($now);
        $problem = $report['state'] === self::PROBLEM;
        $last = DB::table('service_alert_state')->where('id', 1)->value('notified_state') ?? 'ok';
        $recipients = $this->recipients();

        if ($recipients === [] || $problem === ($last === self::PROBLEM)) {
            return ['state' => $report['state'], 'sent' => null];
        }

        $kind = $problem ? 'problem' : 'recovered';
        $parts = $problem ? $this->problems($report) : [];
        $locale = in_array(config('watchdog.service_alerts.locale'), AccountMailer::LOCALES, true) ? (string) config('watchdog.service_alerts.locale') : 'en';
        $replace = [
            'parts' => $parts === [] ? '—' : implode("\n", array_map(fn (string $part): string => '— '.trans("account.service_alert.parts.{$part}", [], $locale), $parts)),
            'time' => $now->copy()->utc()->format('Y-m-d H:i').' UTC',
            'link' => rtrim((string) config('watchdog.site.owner_url'), '/').'/service',
            'environment' => (string) config('app.env'),
        ];

        try {
            foreach ($recipients as $email) {
                $this->mailer->deliver($email, trans("account.service_alert.{$kind}.subject", $replace, $locale), trans("account.service_alert.{$kind}.body", $replace, $locale));
            }
        } catch (Throwable $exception) {
            report($exception);

            return ['state' => $report['state'], 'sent' => null];
        }

        DB::table('service_alert_state')->updateOrInsert(['id' => 1], ['notified_state' => $problem ? self::PROBLEM : 'ok', 'problems' => json_encode($parts), 'notified_at' => $now]);
        $this->audit->record(null, 'service.alert_sent', 'service', $kind, PlatformAudit::RESULT_SUCCEEDED, ['kind' => $kind, 'problems' => $parts, 'recipients' => count($recipients)]);

        return ['state' => $report['state'], 'sent' => $kind];
    }

    /**
     * The parts that are a problem, by name only (no tenant data).
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function problems(array $report): array
    {
        $parts = [];

        foreach ($report['components'] as $name => $state) {
            if ($state === self::PROBLEM) {
                $parts[] = 'component_'.$name;
            }
        }

        foreach ($report['queues'] as $name => $queue) {
            if ($queue['state'] === self::PROBLEM) {
                $parts[] = 'queue_'.$name;
            }
        }

        if ($report['browser_checks']['state'] === self::PROBLEM) {
            $parts[] = 'browser_checks';
        }

        return $parts;
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('watchdog.service_alerts.email'))), fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
    }
}
