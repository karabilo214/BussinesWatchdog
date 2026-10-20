<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\IncidentSignal;
use App\Models\ReconciliationFinding;
use App\Models\Signal;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Reconciliation\DirtySubjectMarker;
use Illuminate\Console\Command;

class RecheckOpenMoneyIncidents extends Command
{
    protected $signature = 'reconciliation:recheck-open-incidents';

    protected $description = 'Queue every order behind an open money incident for reconciliation under the current rules (once after a rule change, e.g. ADR 0068).';

    public function handle(DirtySubjectMarker $marker): int
    {
        $orders = ReconciliationFinding::query()
            ->whereNotNull('order_id')
            ->whereIn('id', Signal::query()
                ->whereNotNull('finding_id')
                ->whereIn('id', IncidentSignal::query()
                    ->whereIn('incident_id', Incident::query()
                        ->where('family', MoneyIncidentCorrelator::FAMILY)
                        ->whereIn('state', Incident::ACTIVE_STATES)
                        ->select('id'))
                    ->select('signal_id'))
                ->select('finding_id'))
            ->distinct()
            ->get(['tenant_id', 'store_id', 'order_id']);

        $marked = 0;

        foreach ($orders->groupBy(fn (ReconciliationFinding $finding): string => $finding->tenant_id.'|'.$finding->store_id) as $group) {
            $first = $group->first();
            $marked += $marker->markOrdersIfAbsent($first->tenant_id, $first->store_id, $group->pluck('order_id')->unique()->values()->all(), 'rule_change_recheck');
        }

        $this->components->info(sprintf('Orders queued for recheck: %d of %d behind open money incidents.', $marked, $orders->pluck('order_id')->unique()->count()));

        return self::SUCCESS;
    }
}
