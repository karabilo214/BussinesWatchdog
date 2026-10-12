<?php

use App\Support\Reconciliation\ReconciliationResultRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_subject_states', function (Blueprint $table) {
            $table->unsignedSmallInteger('recheck_slot')->default(0);
        });

        DB::table('reconciliation_subject_states')->select(['id', 'subject_id'])->orderBy('id')->chunkById(1000, function ($states): void {
            foreach ($states as $state) {
                DB::table('reconciliation_subject_states')->where('id', $state->id)->update(['recheck_slot' => ReconciliationResultRecorder::recheckSlot($state->subject_id)]);
            }
        });

        Schema::table('reconciliation_subject_states', function (Blueprint $table) {
            $table->index(['store_id', 'subject_type', 'recheck_slot'], 'reconciliation_subject_states_store_slot_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_subject_states', function (Blueprint $table) {
            $table->dropIndex('reconciliation_subject_states_store_slot_idx');
            $table->dropColumn('recheck_slot');
        });
    }
};
