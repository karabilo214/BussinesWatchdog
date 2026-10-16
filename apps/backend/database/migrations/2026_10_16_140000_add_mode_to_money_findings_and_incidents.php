<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_findings', function (Blueprint $table): void {
            $table->string('mode', 8)->nullable();
        });
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('mode', 8)->nullable();
        });

        DB::table('reconciliation_findings')->orderBy('id')->chunk(500, function ($findings): void {
            foreach ($findings as $finding) {
                $mode = null;
                if ($finding->order_id !== null) {
                    $order = DB::table('orders')->where('tenant_id', $finding->tenant_id)->where('store_id', $finding->store_id)->where('id', $finding->order_id)->first(['mode', 'metadata']);
                    $metadata = json_decode($order->metadata ?? '{}', true) ?: [];
                    $mode = ($metadata['mode_reported'] ?? true) === false ? null : ($order->mode ?? null);
                } elseif ($finding->payment_id !== null) {
                    $mode = DB::table('payments')->where('tenant_id', $finding->tenant_id)->where('store_id', $finding->store_id)->where('id', $finding->payment_id)->value('mode');
                }
                DB::table('reconciliation_findings')->where('id', $finding->id)->update(['mode' => $mode]);
            }
        });

        DB::table('incidents')->where('family', 'money')->orderBy('id')->chunk(500, function ($incidents): void {
            foreach ($incidents as $incident) {
                $findings = DB::table('incident_signals as links')
                    ->join('signals as signals', 'signals.id', '=', 'links.signal_id')
                    ->join('reconciliation_findings as findings', 'findings.id', '=', 'signals.finding_id')
                    ->where('links.tenant_id', $incident->tenant_id)->where('links.incident_id', $incident->id)
                    ->where('findings.tenant_id', $incident->tenant_id)
                    ->get(['findings.mode', 'findings.order_id', 'findings.payment_id']);
                if ($findings->isEmpty()) {
                    continue;
                }
                $modes = $findings->pluck('mode')->unique();
                $mode = $modes->count() === 1 ? $modes->first() : null;
                $entityId = $findings->first()->order_id ?? $findings->first()->payment_id ?? 'none';
                $fingerprint = hash('sha256', implode('|', ['money', $incident->component, $entityId, $mode ?? 'unknown']));
                DB::table('incidents')->where('id', $incident->id)->update(['mode' => $mode, 'fingerprint' => $fingerprint]);
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reconciliation_findings ADD CONSTRAINT reconciliation_findings_mode_check CHECK (mode IS NULL OR mode IN ('live','test'))");
            DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_mode_check CHECK (mode IS NULL OR mode IN ('live','test'))");
        }
    }

    public function down(): void
    {
        Schema::table('incidents', fn (Blueprint $table) => $table->dropColumn('mode'));
        Schema::table('reconciliation_findings', fn (Blueprint $table) => $table->dropColumn('mode'));
    }
};
