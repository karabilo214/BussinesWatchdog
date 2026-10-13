<?php

namespace App\Support\Billing;

use App\Models\Artifact;
use App\Models\Incident;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes history that has been outside the package's retention for `HistoryWindow::PURGE_AFTER_DAYS` (ADR 0041):
 * closed incidents with their activity, signal links and notification deliveries; signals no incident uses; past
 * reconciliation results (never a subject's current result, never a finding a remaining signal points to); browser
 * check runs with their attempts, steps and screenshots; payment-attempt windows; notification deliveries. Orders,
 * payments, refunds and allocations are the basis of reconciliation and are only hidden, not deleted.
 */
class HistoryPurger
{
    private const CHUNK = 500;

    public function __construct(private readonly HistoryWindow $window) {}

    /**
     * @return array<string, int>
     */
    public function purge(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $totals = ['tenants' => 0, 'incidents' => 0, 'signals' => 0, 'findings' => 0, 'runs' => 0, 'check_runs' => 0, 'attempt_windows' => 0, 'deliveries' => 0];

        Tenant::query()->whereIn('status', ['active', 'suspended'])->orderBy('id')->select('id')->chunkById(200, function ($tenants) use ($now, &$totals): void {
            foreach ($tenants as $tenant) {
                $before = $this->window->purgeBefore($tenant->id, $now);

                if ($before === null) {
                    continue;
                }

                $totals['tenants']++;

                foreach ($this->purgeTenant($tenant->id, $before) as $key => $count) {
                    $totals[$key] += $count;
                }
            }
        });

        return $totals;
    }

    /**
     * @return array<string, int>
     */
    public function purgeTenant(string $tenantId, Carbon $before): array
    {
        return [
            'incidents' => $this->incidents($tenantId, $before),
            'signals' => $this->signals($tenantId, $before),
            'findings' => $this->findings($tenantId, $before),
            'runs' => $this->runs($tenantId, $before),
            'check_runs' => $this->checkRuns($tenantId, $before),
            'attempt_windows' => $this->deleteChunked(DB::table('payment_attempt_windows')->where('tenant_id', $tenantId)->where('window_end', '<', $before)),
            'deliveries' => $this->deleteChunked(DB::table('notification_deliveries')->where('tenant_id', $tenantId)->whereNull('incident_id')->where('created_at', '<', $before)),
        ];
    }

    private function incidents(string $tenantId, Carbon $before): int
    {
        $deleted = 0;

        do {
            $ids = DB::table('incidents')
                ->where('tenant_id', $tenantId)
                ->whereNotIn('state', Incident::ACTIVE_STATES)
                ->where('last_seen_at', '<', $before)
                ->whereNotIn('id', DB::table('suppressions')->where('tenant_id', $tenantId)->whereNotNull('incident_id')->select('incident_id'))
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            DB::transaction(function () use ($tenantId, $ids): void {
                DB::table('notification_deliveries')->where('tenant_id', $tenantId)->whereIn('incident_id', $ids)->delete();
                DB::table('incident_activity')->where('tenant_id', $tenantId)->whereIn('incident_id', $ids)->delete();
                DB::table('incident_signals')->where('tenant_id', $tenantId)->whereIn('incident_id', $ids)->delete();
                DB::table('incidents')->where('tenant_id', $tenantId)->whereIn('id', $ids)->delete();
            });
            $deleted += count($ids);
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }

    private function signals(string $tenantId, Carbon $before): int
    {
        return $this->deleteChunked(DB::table('signals')
            ->where('tenant_id', $tenantId)
            ->where('detected_at', '<', $before)
            ->whereNotIn('id', DB::table('incident_signals')->where('tenant_id', $tenantId)->select('signal_id')));
    }

    private function findings(string $tenantId, Carbon $before): int
    {
        return $this->deleteChunked(DB::table('reconciliation_findings')
            ->where('tenant_id', $tenantId)
            ->where('evaluated_at', '<', $before)
            ->whereNotIn('run_id', DB::table('reconciliation_subject_states')->where('tenant_id', $tenantId)->select('run_id'))
            ->whereNotIn('id', DB::table('signals')->where('tenant_id', $tenantId)->whereNotNull('finding_id')->select('finding_id')));
    }

    private function runs(string $tenantId, Carbon $before): int
    {
        return $this->deleteChunked(DB::table('reconciliation_runs')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '<', $before)
            ->whereNotIn('id', DB::table('reconciliation_subject_states')->where('tenant_id', $tenantId)->select('run_id'))
            ->whereNotIn('id', DB::table('reconciliation_findings')->where('tenant_id', $tenantId)->select('run_id')));
    }

    private function checkRuns(string $tenantId, Carbon $before): int
    {
        $deleted = 0;

        do {
            $ids = DB::table('check_runs')
                ->where('tenant_id', $tenantId)
                ->whereNotIn('status', ['queued', 'running'])
                ->where('created_at', '<', $before)
                ->whereNotIn('id', DB::table('signals')->where('tenant_id', $tenantId)->whereNotNull('check_run_id')->select('check_run_id'))
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $attempts = DB::table('check_attempts')->where('tenant_id', $tenantId)->whereIn('run_id', $ids)->pluck('id')->all();
            $artifacts = DB::table('artifacts')->where('tenant_id', $tenantId)->whereIn('attempt_id', $attempts)->get(['id', 'state', 'object_key']);

            foreach ($artifacts as $artifact) {
                if ($artifact->state === Artifact::STATE_READY && is_string($artifact->object_key)) {
                    Storage::disk((string) config('watchdog.artifacts.disk'))->delete($artifact->object_key);
                }
            }

            DB::transaction(function () use ($tenantId, $ids, $attempts): void {
                DB::table('artifacts')->where('tenant_id', $tenantId)->whereIn('attempt_id', $attempts)->delete();
                DB::table('check_steps')->where('tenant_id', $tenantId)->whereIn('attempt_id', $attempts)->delete();
                DB::table('check_attempts')->where('tenant_id', $tenantId)->whereIn('id', $attempts)->delete();
                DB::table('check_runs')->where('tenant_id', $tenantId)->whereIn('id', $ids)->delete();
            });
            $deleted += count($ids);
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }

    private function deleteChunked(\Illuminate\Database\Query\Builder $query): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::table($query->from)->whereIn('id', $ids)->delete();
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }
}
