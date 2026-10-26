<?php

namespace App\Support\Disputes;

use App\Models\Incident;
use App\Models\Integration;
use App\Models\NotificationDelivery;
use App\Models\ProviderDispute;
use App\Models\Signal;
use App\Support\Incidents\IncidentRecorder;
use App\Support\Notifications\IncidentNotificationRequester;
use Illuminate\Support\Carbon;

class DisputeIncidents
{
    public const FAMILY = 'dispute';

    public const COMPONENT = 'dispute';

    public const RULE_OPEN = 'DISPUTE_OPEN';

    public const RULE_INQUIRY = 'DISPUTE_INQUIRY';

    public const RULE_VERSION = 'v1';

    public const CONFIG_VERSION = 1;

    public const REMIND_DAYS_BEFORE_DUE = 3;

    public function __construct(
        private readonly IncidentRecorder $incidents,
        private readonly IncidentNotificationRequester $notifications,
    ) {}

    public function sync(ProviderDispute $dispute): void
    {
        $fingerprint = self::fingerprint($dispute);

        if ($dispute->isOpen()) {
            $rule = str_starts_with($dispute->status, 'warning_') ? self::RULE_INQUIRY : self::RULE_OPEN;
            $this->incidents->openOrAttach($dispute->tenant_id, $dispute->store_id, $fingerprint, [
                'family' => self::FAMILY,
                'component' => self::COMPONENT,
                'title_code' => $rule,
                'severity' => Incident::SEVERITY_WARNING,
                'first_bad_at' => $dispute->opened_at,
                'last_good_at' => null,
            ], $this->signal($dispute, $rule, Signal::SEVERITY_WARNING), 'dispute_opened');

            return;
        }

        if ($this->incidents->active($dispute->tenant_id, $dispute->store_id, $fingerprint) === null) {
            return;
        }

        $reason = match ($dispute->status) {
            'won' => 'auto_resolved_dispute_won',
            'lost' => 'auto_resolved_dispute_lost',
            default => 'auto_resolved_dispute_closed',
        };
        $this->incidents->resolve($dispute->tenant_id, $dispute->store_id, $fingerprint, $reason, $this->signal($dispute, self::RULE_OPEN, Signal::SEVERITY_INFO), Carbon::now());
    }

    public function remindDue(): int
    {
        $now = Carbon::now();
        $reminded = 0;

        ProviderDispute::query()
            ->whereIn('status', ProviderDispute::AWAITING_RESPONSE_STATUSES)
            ->whereNull('reminded_at')
            ->whereNotNull('evidence_due_by')
            ->where('evidence_due_by', '>', $now)
            ->where('evidence_due_by', '<=', $now->copy()->addDays(self::REMIND_DAYS_BEFORE_DUE))
            ->orderBy('evidence_due_by')
            ->limit(500)
            ->get()
            ->each(function (ProviderDispute $dispute) use ($now, &$reminded): void {
                $incident = $this->incidents->active($dispute->tenant_id, $dispute->store_id, self::fingerprint($dispute));

                if ($incident !== null) {
                    $this->notifications->request($incident, NotificationDelivery::KIND_INCIDENT_REMINDER);
                    $reminded++;
                }

                $dispute->forceFill(['reminded_at' => $now, 'updated_at' => $now])->save();
            });

        return $reminded;
    }

    public static function fingerprint(ProviderDispute $dispute): string
    {
        return self::fingerprintFor($dispute->integration_id, $dispute->external_id);
    }

    public static function fingerprintFor(string $integrationId, string $externalId): string
    {
        return hash('sha256', implode('|', [self::FAMILY, $integrationId, $externalId]));
    }

    private function signal(ProviderDispute $dispute, string $rule, string $severity): Signal
    {
        $now = Carbon::now();

        return Signal::query()->firstOrCreate(
            ['tenant_id' => $dispute->tenant_id, 'dedupe_key' => implode(':', ['dispute', $dispute->id, $dispute->status, $dispute->source_revision])],
            [
                'store_id' => $dispute->store_id,
                'signal_type' => Signal::TYPE_DISPUTE,
                'family' => self::FAMILY,
                'component' => $rule,
                'severity' => $severity,
                'confidence' => Signal::CONFIDENCE_OBSERVED,
                'rule_version' => self::RULE_VERSION,
                'config_version' => self::CONFIG_VERSION,
                'observed_start' => $dispute->opened_at,
                'observed_end' => $now,
                'evidence' => array_filter([
                    'dispute_id' => $dispute->external_id,
                    'status' => $dispute->status,
                    'reason' => $dispute->reason,
                    'evidence_due_by' => $dispute->evidence_due_by?->toJSON(),
                    'provider' => Integration::query()->whereKey($dispute->integration_id)->value('provider'),
                ], fn (mixed $value): bool => $value !== null),
                'data_quality' => ['source' => 'provider_dispute'],
                'detected_at' => $now,
            ],
        );
    }
}
