<?php

namespace App\Http\Controllers\Api\V1\Overview;

use App\Http\Controllers\Controller;
use App\Http\Dto\Checks\CheckDto;
use App\Http\Dto\Incidents\IncidentDto;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\ReconciliationFinding;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public const LATEST_LIMIT = 5;

    private const ACTIVE_STATES = [Incident::STATE_OPEN, Incident::STATE_ACKNOWLEDGED];

    public function __construct(
        private readonly IncidentDto $incidentDto,
        private readonly CheckDto $checkDto,
    ) {}

    public function show(TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read overview');

        $active = Incident::query()->where('tenant_id', $tenantId)->whereIn('state', self::ACTIVE_STATES);
        $bySeverity = (clone $active)->selectRaw('severity, COUNT(*) AS total')->groupBy('severity')->pluck('total', 'severity');

        return response()->json([
            'incidents' => [
                'active' => (int) $bySeverity->sum(),
                'by_severity' => [
                    Incident::SEVERITY_CRITICAL => (int) ($bySeverity[Incident::SEVERITY_CRITICAL] ?? 0),
                    Incident::SEVERITY_WARNING => (int) ($bySeverity[Incident::SEVERITY_WARNING] ?? 0),
                    Incident::SEVERITY_INFO => (int) ($bySeverity[Incident::SEVERITY_INFO] ?? 0),
                ],
                'latest' => $this->incidentDto->collection(
                    (clone $active)->orderByDesc('last_seen_at')->orderByDesc('id')->limit(self::LATEST_LIMIT)->get(),
                ),
            ],
            ...$this->discrepancies($tenantId),
            'recent_checks' => CheckRun::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('finished_at')
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->limit(self::LATEST_LIMIT)
                ->get()
                ->map(fn (CheckRun $run): array => $this->checkDto->run($run))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Verified discrepancies of active money incidents (ADR 0018): summed by the database per currency and component,
     * never across currencies or components; incidents without a verified amount are counted, not treated as zero.
     *
     * @return array{discrepancies: list<array<string, mixed>>, unknown_amount_incidents: int}
     */
    private function discrepancies(string $tenantId): array
    {
        $money = Incident::query()
            ->where('tenant_id', $tenantId)
            ->where('family', 'money')
            ->whereIn('state', self::ACTIVE_STATES);

        $rows = (clone $money)
            ->whereNotNull('verified_discrepancy_minor')
            ->whereNotNull('currency')
            ->selectRaw('currency, component, SUM(verified_discrepancy_minor) AS total_minor, COUNT(*) AS incident_count')
            ->groupBy('currency', 'component')
            ->orderBy('currency')
            ->orderBy('component')
            ->get();

        $exponents = ReconciliationFinding::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('currency', $rows->pluck('currency')->unique()->values())
            ->whereNotNull('currency_exponent')
            ->distinct()
            ->pluck('currency_exponent', 'currency');

        return [
            'discrepancies' => $rows->map(fn ($row): array => [
                'currency' => $row->currency,
                'currency_exponent' => isset($exponents[$row->currency]) ? (int) $exponents[$row->currency] : null,
                'component' => $row->component,
                'total_minor' => $this->minorString($row->total_minor),
                'incident_count' => (int) $row->incident_count,
            ])->values()->all(),
            'unknown_amount_incidents' => (clone $money)
                ->where(fn ($query) => $query->whereNull('verified_discrepancy_minor')->orWhereNull('currency'))
                ->count(),
        ];
    }

    /** SUM over bigint comes back as an integer (SQLite) or a numeric string (PostgreSQL); keep it a decimal string. */
    private function minorString(mixed $value): string
    {
        $string = is_string($value) ? $value : (string) (int) $value;

        return preg_replace('/\.0+$/', '', $string) ?? $string;
    }
}
