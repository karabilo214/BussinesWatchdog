<?php

namespace App\Support\Reconciliation;

use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\ReconciliationSubjectState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Keeps the history of a reconciliation subject (an order, or a store's unmatched-payment scan) to the results that
 * changed (ADR 0040): an evaluation that gives the same findings under the same rules only moves `evaluated_at` of
 * the subject's current state; otherwise the run and its findings are written and become the current state.
 * Must run inside the caller's transaction, after the subject row is locked.
 */
class ReconciliationResultRecorder
{
    public const SUBJECT_ORDER = 'order';

    public const SUBJECT_STORE_UNMATCHED_PAYMENTS = 'store_unmatched_payments';

    public const RECHECK_DAYS = 30;

    private const STATUS_WEIGHT = [
        ReconciliationFinding::STATUS_OK => 0,
        ReconciliationFinding::STATUS_UNSUPPORTED => 1,
        ReconciliationFinding::STATUS_PENDING => 2,
        ReconciliationFinding::STATUS_UNKNOWN => 3,
        ReconciliationFinding::STATUS_MISMATCH => 4,
    ];

    private const HASHED = ['rule_code', 'status', 'reason_code', 'order_id', 'payment_id', 'mode', 'currency', 'currency_exponent', 'expected_minor', 'actual_minor', 'difference_minor', 'gross_minor', 'captured_minor', 'refund_expected_minor', 'refund_actual_minor', 'evidence'];

    /**
     * @param  array<string, mixed>  $run  attributes of the run (without status, counters, finished_at)
     * @param  list<array<string, mixed>>  $findings  attributes of the findings (run_id is filled in here)
     * @param  array{entitled?: ?bool, store_data_stale?: ?bool}  $inputs
     */
    public function record(string $subjectType, string $subjectId, array $run, array $findings, array $inputs = []): ReconciliationRun
    {
        $now = Carbon::now();
        $hash = self::hash((string) $run['algorithm_version'], (int) $run['config_version'], $findings);
        /** @var ReconciliationSubjectState|null $state */
        $state = ReconciliationSubjectState::query()
            ->where('tenant_id', $run['tenant_id'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->lockForUpdate()
            ->first();
        $flags = ['entitled' => $inputs['entitled'] ?? null, 'store_data_stale' => $inputs['store_data_stale'] ?? null];

        if ($state !== null && $state->result_hash === $hash) {
            /** @var ReconciliationRun|null $current */
            $current = ReconciliationRun::query()->whereKey($state->run_id)->first();

            if ($current !== null) {
                $state->forceFill($flags + ['evaluated_at' => $now])->save();
                $current->resultChanged = false;

                return $current;
            }
        }

        $model = ReconciliationRun::query()->create(array_merge($run, [
            'status' => ReconciliationRun::STATUS_COMPLETED,
            'finished_at' => $now,
            'counters' => ['findings' => count($findings)],
        ]));

        foreach ($findings as $finding) {
            ReconciliationFinding::query()->create(['run_id' => $model->id] + $finding);
        }

        $values = $flags + [
            'store_id' => $run['store_id'],
            'run_id' => $model->id,
            'result_hash' => $hash,
            'worst_status' => self::worstStatus($findings),
            'finding_count' => count($findings),
            'algorithm_version' => $run['algorithm_version'],
            'config_version' => $run['config_version'],
            'evaluated_at' => $now,
            'changed_at' => $now,
        ];

        if ($state === null) {
            ReconciliationSubjectState::query()->create($values + [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $run['tenant_id'],
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'recheck_slot' => self::recheckSlot($subjectId),
            ]);
        } else {
            $state->forceFill($values)->save();
        }

        $model->resultChanged = true;

        return $model;
    }

    /**
     * The night of the monthly re-check cycle (0 … RECHECK_DAYS − 1) a subject belongs to: fixed by its id and spread
     * evenly, so each night re-checks about one thirtieth of the subjects however many were checked on the same day.
     */
    public static function recheckSlot(string $subjectId): int
    {
        return crc32($subjectId) % self::RECHECK_DAYS;
    }

    public static function slotOf(\DateTimeInterface $night): int
    {
        return intdiv($night->getTimestamp(), 86400) % self::RECHECK_DAYS;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    public static function hash(string $algorithmVersion, int $configVersion, array $findings): string
    {
        $normalized = array_map(function (array $finding): array {
            $row = [];

            foreach (self::HASHED as $key) {
                $value = $finding[$key] ?? null;

                if ($key === 'evidence') {
                    $value = is_string($value) ? (json_decode($value, true) ?? []) : ($value ?? []);
                    $value = self::sortKeys($value);
                } elseif ($value !== null && ! is_string($value)) {
                    $value = (string) $value;
                }

                $row[$key] = $value;
            }

            return $row;
        }, $findings);

        usort($normalized, fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));

        return hash('sha256', json_encode([$algorithmVersion, $configVersion, $normalized], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    public static function worstStatus(array $findings): ?string
    {
        $worst = null;

        foreach ($findings as $finding) {
            $status = (string) ($finding['status'] ?? '');

            if ($worst === null || (self::STATUS_WEIGHT[$status] ?? 5) > (self::STATUS_WEIGHT[$worst] ?? 5)) {
                $worst = $status;
            }
        }

        return $worst;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_int($value) || is_float($value) ? (string) $value : $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => self::sortKeys($item), $value);
    }
}
