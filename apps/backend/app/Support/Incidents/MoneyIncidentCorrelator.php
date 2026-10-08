<?php

namespace App\Support\Incidents;

use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\IncidentSignal;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Signal;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MoneyIncidentCorrelator
{
    public const FAMILY = 'money';

    public const REOPEN_WINDOW_HOURS = 24;

    /**
     * Rule codes are grouped into a coarser component before fingerprinting, because fixing
     * one rule's problem can make the finding reappear under a sibling rule (a capture arriving
     * turns MONEY_CAPTURE_MISSING into MONEY_CAPTURE_AMOUNT) — the incident is about "capture
     * correctness for this order", not about one exact rule code staying constant.
     *
     * @var array<string, string>
     */
    private const COMPONENT_MAP = [
        ReconciliationFinding::RULE_CAPTURE_MISSING => 'capture',
        ReconciliationFinding::RULE_CAPTURE_AMOUNT => 'capture',
        ReconciliationFinding::RULE_REFUND_MISSING => 'refund',
        ReconciliationFinding::RULE_REFUND_EXTRA => 'refund',
        ReconciliationFinding::RULE_MULTIPLE_CAPTURES => 'multiple_captures',
        ReconciliationFinding::RULE_CURRENCY_MISMATCH => 'currency_mismatch',
        ReconciliationFinding::RULE_ORDER_CHANGED => 'order_changed',
        ReconciliationFinding::RULE_PAYMENT_WITHOUT_ORDER => 'payment_without_order',
    ];

    /**
     * @return list<Incident>
     */
    public function correlate(ReconciliationRun $run): array
    {
        $findings = ReconciliationFinding::query()->where('run_id', $run->id)->get();
        $affected = [];

        foreach ($findings as $finding) {
            if ($finding->status === ReconciliationFinding::STATUS_MISMATCH) {
                $affected[] = $this->openOrAttach($finding);
            } elseif ($finding->status === ReconciliationFinding::STATUS_OK) {
                $resolved = $this->maybeAutoResolve($finding);

                if ($resolved !== null) {
                    $affected[] = $resolved;
                }
            }
        }

        return $affected;
    }

    private function openOrAttach(ReconciliationFinding $finding): Incident
    {
        return DB::transaction(function () use ($finding): Incident {
            $entityId = $finding->order_id ?? $finding->payment_id;
            $component = $this->component($finding->rule_code);
            $fingerprint = $this->fingerprint($component, $entityId);
            $now = $finding->evaluated_at ?? Carbon::now();

            $incident = Incident::query()
                ->where('tenant_id', $finding->tenant_id)
                ->where('store_id', $finding->store_id)
                ->where('fingerprint', $fingerprint)
                ->whereIn('state', Incident::ACTIVE_STATES)
                ->lockForUpdate()
                ->first();

            if ($incident === null) {
                $recentlyResolved = Incident::query()
                    ->where('tenant_id', $finding->tenant_id)
                    ->where('store_id', $finding->store_id)
                    ->where('fingerprint', $fingerprint)
                    ->where('state', Incident::STATE_RESOLVED)
                    ->where('resolved_at', '>=', $now->copy()->subHours(self::REOPEN_WINDOW_HOURS))
                    ->orderByDesc('resolved_at')
                    ->lockForUpdate()
                    ->first();

                if ($recentlyResolved !== null) {
                    $incident = $this->reopen($recentlyResolved, $now);
                }
            }

            $signal = $this->createSignal($finding, $now);

            if ($incident === null) {
                $incident = Incident::query()->create([
                    'tenant_id' => $finding->tenant_id,
                    'store_id' => $finding->store_id,
                    'family' => self::FAMILY,
                    'component' => $component,
                    'fingerprint' => $fingerprint,
                    'state' => Incident::STATE_OPEN,
                    'severity' => Incident::SEVERITY_WARNING,
                    'title_code' => $finding->rule_code,
                    'currency' => $finding->currency,
                    'verified_discrepancy_minor' => $this->verifiedDiscrepancy($finding),
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'first_bad_at' => $now,
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->writeActivity($incident, IncidentActivity::KIND_CREATED, null, []);
            } else {
                $incident->forceFill([
                    'last_seen_at' => $now,
                    'title_code' => $finding->rule_code,
                    'verified_discrepancy_minor' => $this->verifiedDiscrepancy($finding) ?? $incident->verified_discrepancy_minor,
                    'revision' => $incident->revision + 1,
                    'updated_at' => $now,
                ])->save();

                $this->writeActivity($incident, IncidentActivity::KIND_SIGNAL_LINKED, null, []);
            }

            $this->linkSignal($incident, $signal, 'same_rule_and_entity_mismatch', $now);

            return $incident;
        });
    }

    private function maybeAutoResolve(ReconciliationFinding $finding): ?Incident
    {
        $entityId = $finding->order_id ?? $finding->payment_id;

        if ($entityId === null) {
            return null;
        }

        $fingerprint = $this->fingerprint($this->component($finding->rule_code), $entityId);

        return DB::transaction(function () use ($finding, $fingerprint): ?Incident {
            $incident = Incident::query()
                ->where('tenant_id', $finding->tenant_id)
                ->where('store_id', $finding->store_id)
                ->where('fingerprint', $fingerprint)
                ->whereIn('state', Incident::ACTIVE_STATES)
                ->lockForUpdate()
                ->first();

            if ($incident === null) {
                return null;
            }

            $now = $finding->evaluated_at ?? Carbon::now();
            $signal = $this->createSignal($finding, $now);

            $incident->forceFill([
                'state' => Incident::STATE_RESOLVED,
                'resolved_at' => $now,
                'resolution_reason' => 'auto_resolved_fresh_reconciliation_ok',
                'last_good_at' => $now,
                'last_seen_at' => $now,
                'revision' => $incident->revision + 1,
                'updated_at' => $now,
            ])->save();

            $this->linkSignal($incident, $signal, 'fresh_reconciliation_ok', $now);
            $this->writeActivity($incident, IncidentActivity::KIND_RESOLVED, null, [
                'reason' => 'auto_resolved_fresh_reconciliation_ok',
            ]);

            return $incident;
        });
    }

    private function reopen(Incident $incident, Carbon $now): Incident
    {
        $incident->forceFill([
            'state' => Incident::STATE_OPEN,
            'resolved_at' => null,
            'resolution_reason' => null,
            'last_seen_at' => $now,
            'revision' => $incident->revision + 1,
            'updated_at' => $now,
        ])->save();

        $this->writeActivity($incident, IncidentActivity::KIND_REOPENED, null, []);

        return $incident;
    }

    private function createSignal(ReconciliationFinding $finding, Carbon $now): Signal
    {
        $severity = $finding->status === ReconciliationFinding::STATUS_MISMATCH
            ? Signal::SEVERITY_WARNING
            : Signal::SEVERITY_INFO;

        return Signal::query()->firstOrCreate(
            [
                'tenant_id' => $finding->tenant_id,
                'dedupe_key' => 'reconciliation_finding:'.$finding->id,
            ],
            [
                'store_id' => $finding->store_id,
                'signal_type' => Signal::TYPE_RECONCILIATION_FINDING,
                'family' => self::FAMILY,
                'component' => $finding->rule_code,
                'severity' => $severity,
                'confidence' => Signal::CONFIDENCE_OBSERVED,
                'rule_version' => OrderReconciliationService::ALGORITHM_VERSION,
                'config_version' => OrderReconciliationService::CONFIG_VERSION,
                'currency' => $finding->currency,
                'finding_id' => $finding->id,
                'evidence' => [
                    'finding_id' => $finding->id,
                    'reason_code' => $finding->reason_code,
                ],
                'data_quality' => [],
                'detected_at' => $now,
            ],
        );
    }

    private function linkSignal(Incident $incident, Signal $signal, string $associationReason, Carbon $now): void
    {
        IncidentSignal::query()->firstOrCreate(
            [
                'incident_id' => $incident->id,
                'signal_id' => $signal->id,
            ],
            [
                'tenant_id' => $incident->tenant_id,
                'store_id' => $incident->store_id,
                'association_reason' => $associationReason,
                'linked_at' => $now,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function writeActivity(Incident $incident, string $kind, ?string $actorId, array $extra): void
    {
        IncidentActivity::query()->create([
            'tenant_id' => $incident->tenant_id,
            'store_id' => $incident->store_id,
            'incident_id' => $incident->id,
            'kind' => $kind,
            'actor_id' => $actorId,
            'incident_revision' => $incident->revision,
            'sanitized_data' => $extra,
            'created_at' => Carbon::now(),
        ]);
    }

    private function verifiedDiscrepancy(ReconciliationFinding $finding): ?int
    {
        return $finding->difference_minor === null ? null : abs($finding->difference_minor);
    }

    private function fingerprint(string $component, ?string $entityId): string
    {
        return hash('sha256', implode('|', [self::FAMILY, $component, $entityId ?? 'none']));
    }

    private function component(string $ruleCode): string
    {
        return self::COMPONENT_MAP[$ruleCode] ?? mb_strtolower($ruleCode);
    }
}
