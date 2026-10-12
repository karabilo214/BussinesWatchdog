<?php

namespace App\Support\Exports;

use App\Models\ExportFile;
use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CsvExportService
{
    public const ERROR_STORAGE_UNAVAILABLE = 'export_storage_unavailable';

    public const ERROR_KIND_UNSUPPORTED = 'export_kind_unsupported';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function create(Store $store, string $tenantId, ?string $actorId, string $kind, string $format, array $filters): ExportFile
    {
        $export = ExportFile::query()->forceCreate([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'store_id' => $store->id,
            'created_by' => $actorId,
            'kind' => $kind,
            'format' => $format,
            'filters' => $filters,
            'state' => ExportFile::STATE_PROCESSING,
            'created_at' => Carbon::now(),
        ]);

        try {
            $body = match ($kind) {
                ExportFile::KIND_FINDINGS => $this->findingsCsv($tenantId, $store->id, $filters),
                ExportFile::KIND_INCIDENTS => $this->incidentsCsv($tenantId, $store->id, $filters),
                ExportFile::KIND_TRANSACTIONS => $this->transactionsCsv($tenantId, $store->id, $filters),
                default => throw new \InvalidArgumentException(self::ERROR_KIND_UNSUPPORTED),
            };

            $key = $this->objectKey($export);
            $this->disk()->put($key, $body, ['visibility' => 'private', 'ContentType' => 'text/csv; charset=UTF-8']);

            $export->forceFill([
                'state' => ExportFile::STATE_READY,
                'object_key' => $key,
                'content_type' => 'text/csv; charset=UTF-8',
                'size_bytes' => strlen($body),
                'sha256' => hash('sha256', $body),
                'expires_at' => Carbon::now()->addDays((int) config('watchdog.exports.retention_days')),
                'completed_at' => Carbon::now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $export->forceFill([
                'state' => ExportFile::STATE_FAILED,
                'error_code' => $exception->getMessage() === self::ERROR_KIND_UNSUPPORTED
                    ? self::ERROR_KIND_UNSUPPORTED
                    : self::ERROR_STORAGE_UNAVAILABLE,
                'completed_at' => Carbon::now(),
            ])->save();
        }

        return $export->fresh();
    }

    public function body(ExportFile $export): string
    {
        return $this->disk()->get((string) $export->object_key);
    }

    /**
     * Deletes expired exports from private storage first, then marks the database row deleted.
     * The operation is safe to retry if the object has already disappeared.
     */
    public function purgeExpired(int $limit = 500): int
    {
        $purged = 0;

        foreach (ExportFile::query()->where('state', ExportFile::STATE_READY)->where('expires_at', '<=', Carbon::now())->orderBy('expires_at')->limit($limit)->get() as $export) {
            $this->disk()->delete((string) $export->object_key);
            $export->forceFill(['state' => ExportFile::STATE_DELETED, 'deleted_at' => Carbon::now()])->save();
            $purged++;
        }

        return $purged;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function findingsCsv(string $tenantId, string $storeId, array $filters): string
    {
        $query = ReconciliationFinding::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId);

        if (($filters['current'] ?? false) === true) {
            $query->whereIn('id', $this->currentFindingIds($tenantId, $storeId));
        }

        if (isset($filters['from'])) {
            $query->where('evaluated_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('evaluated_at', '<', $filters['to']);
        }

        foreach (['status', 'rule_code', 'currency'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $findings = $query->orderByDesc('evaluated_at')->orderByDesc('id')->limit($this->rowLimit())->get();
        $orderNumbers = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $findings->pluck('order_id')->filter()->unique()->values())
            ->pluck('display_number', 'id');

        return $this->csv([
            'finding_id',
            'evaluated_at',
            'status',
            'rule_code',
            'reason_code',
            'order_id',
            'order_display_number',
            'payment_id',
            'currency',
            'currency_exponent',
            'expected_minor',
            'actual_minor',
            'difference_minor',
        ], $findings->map(fn (ReconciliationFinding $finding): array => [
            $finding->id,
            $finding->evaluated_at?->toJSON(),
            $finding->status,
            $finding->rule_code,
            $finding->reason_code,
            $finding->order_id,
            $finding->order_id === null ? null : ($orderNumbers[$finding->order_id] ?? null),
            $finding->payment_id,
            $finding->currency,
            $finding->currency_exponent,
            $finding->expected_minor,
            $finding->actual_minor,
            $finding->difference_minor,
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function incidentsCsv(string $tenantId, string $storeId, array $filters): string
    {
        $query = Incident::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId);

        if (isset($filters['state'])) {
            $query->whereIn('state', $filters['state']);
        }

        foreach (['severity', 'family', 'currency'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $incidents = $query->orderByDesc('last_seen_at')->orderByDesc('id')->limit($this->rowLimit())->get();

        return $this->csv([
            'incident_id',
            'state',
            'severity',
            'family',
            'component',
            'title_code',
            'currency',
            'verified_discrepancy_minor',
            'first_seen_at',
            'last_seen_at',
            'resolved_at',
        ], $incidents->map(fn (Incident $incident): array => [
            $incident->id,
            $incident->state,
            $incident->severity,
            $incident->family,
            $incident->component,
            $incident->title_code,
            $incident->currency,
            $incident->verified_discrepancy_minor,
            $incident->first_seen_at?->toJSON(),
            $incident->last_seen_at?->toJSON(),
            $incident->resolved_at?->toJSON(),
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function transactionsCsv(string $tenantId, string $storeId, array $filters): string
    {
        $query = FinancialTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId);

        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('occurred_at', '<', $filters['to']);
        }

        foreach (['kind', 'currency'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['transaction_status'])) {
            $query->where('status', $filters['transaction_status']);
        }

        $transactions = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($this->rowLimit())->get();
        $payments = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereIn('id', $transactions->pluck('payment_id')->filter()->unique()->values())
            ->get(['id', 'external_id', 'intent_ref', 'charge_ref', 'mode'])
            ->keyBy('id');

        return $this->csv([
            'transaction_id',
            'occurred_at',
            'payment_id',
            'payment_external_id',
            'payment_intent_ref',
            'payment_charge_ref',
            'mode',
            'external_operation_id',
            'kind',
            'status',
            'currency',
            'currency_exponent',
            'amount_minor',
            'source_authority',
        ], $transactions->map(function (FinancialTransaction $transaction) use ($payments): array {
            $payment = $transaction->payment_id === null ? null : ($payments[$transaction->payment_id] ?? null);

            return [
                $transaction->id,
                $transaction->occurred_at?->toJSON(),
                $transaction->payment_id,
                $payment?->external_id,
                $payment?->intent_ref,
                $payment?->charge_ref,
                $payment?->mode,
                $transaction->external_operation_id,
                $transaction->kind,
                $transaction->status,
                $transaction->currency,
                $transaction->currency_exponent,
                $transaction->amount_minor,
                $transaction->source_authority,
            ];
        })->all());
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->cell(...), $row));
        }

        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $cell = (string) $value;

        return preg_match('/^[=\-+@\t\r]/', $cell) === 1 ? "'".$cell : $cell;
    }

    private function currentFindingIds(string $tenantId, string $storeId): \Illuminate\Database\Query\Builder
    {
        return DB::table('reconciliation_findings')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereIn('run_id', DB::table('reconciliation_subject_states')->where('tenant_id', $tenantId)->where('store_id', $storeId)->select('run_id'))
            ->select('id');
    }

    private function objectKey(ExportFile $export): string
    {
        return implode('/', ['tenants', $export->tenant_id, 'stores', $export->store_id, 'exports', $export->id.'.csv']);
    }

    private function rowLimit(): int
    {
        return (int) config('watchdog.exports.max_rows');
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('watchdog.exports.disk'));
    }
}
