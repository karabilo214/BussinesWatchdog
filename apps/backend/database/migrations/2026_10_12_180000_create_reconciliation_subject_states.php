<?php

use App\Support\Reconciliation\ReconciliationResultRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_subject_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('subject_type');
            $table->uuid('subject_id');
            $table->uuid('run_id');
            $table->char('result_hash', 64);
            $table->text('worst_status')->nullable();
            $table->unsignedInteger('finding_count')->default(0);
            $table->text('algorithm_version');
            $table->unsignedBigInteger('config_version');
            $table->boolean('entitled')->nullable();
            $table->boolean('store_data_stale')->nullable();
            $table->timestampTz('evaluated_at');
            $table->timestampTz('changed_at');

            $table->unique(['tenant_id', 'subject_type', 'subject_id'], 'reconciliation_subject_states_subject_unique');
            $table->index(['store_id', 'subject_type', 'evaluated_at'], 'reconciliation_subject_states_store_checked_idx');
            $table->index(['store_id', 'subject_type', 'worst_status'], 'reconciliation_subject_states_store_status_idx');
            $table->foreign(['tenant_id', 'store_id', 'run_id'])->references(['tenant_id', 'store_id', 'id'])->on('reconciliation_runs');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reconciliation_subject_states ADD CONSTRAINT reconciliation_subject_states_type_check CHECK (subject_type IN ('order','store_unmatched_payments'))");
            DB::statement('CREATE INDEX reconciliation_findings_run_idx ON reconciliation_findings (run_id)');
        } else {
            Schema::table('reconciliation_findings', fn (Blueprint $table) => $table->index('run_id', 'reconciliation_findings_run_idx'));
        }

        $latest = [];

        DB::table('reconciliation_runs')->where('status', 'completed')->orderBy('created_at')->orderBy('id')->chunk(1000, function ($runs) use (&$latest): void {
            foreach ($runs as $run) {
                $scope = json_decode((string) $run->scope, true) ?: [];
                [$type, $subject] = isset($scope['order_id']) ? ['order', $scope['order_id']] : (($scope['rule_code'] ?? null) === 'MONEY_PAYMENT_WITHOUT_ORDER' ? ['store_unmatched_payments', $run->store_id] : [null, null]);

                if ($type !== null && Str::isUuid((string) $subject)) {
                    $latest[$run->tenant_id.'|'.$type.'|'.$subject] = [$run, $type, $subject];
                }
            }
        });

        foreach (array_chunk($latest, 500) as $chunk) {
            $rows = [];

            foreach ($chunk as [$run, $type, $subject]) {
                $findings = DB::table('reconciliation_findings')->where('run_id', $run->id)->get()->map(fn (object $finding): array => (array) $finding)->all();
                $at = Carbon::parse($run->finished_at ?? $run->created_at);
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $run->tenant_id,
                    'store_id' => $run->store_id,
                    'subject_type' => $type,
                    'subject_id' => $subject,
                    'run_id' => $run->id,
                    'result_hash' => ReconciliationResultRecorder::hash($run->algorithm_version, (int) $run->config_version, $findings),
                    'worst_status' => ReconciliationResultRecorder::worstStatus($findings),
                    'finding_count' => count($findings),
                    'algorithm_version' => $run->algorithm_version,
                    'config_version' => $run->config_version,
                    'entitled' => null,
                    'store_data_stale' => null,
                    'evaluated_at' => $at,
                    'changed_at' => $at,
                ];
            }

            DB::table('reconciliation_subject_states')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_subject_states');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS reconciliation_findings_run_idx');
        } else {
            Schema::table('reconciliation_findings', fn (Blueprint $table) => $table->dropIndex('reconciliation_findings_run_idx'));
        }
    }
};
