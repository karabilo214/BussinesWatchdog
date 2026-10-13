<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_request_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contact_request_id');
            $table->uuid('staff_id')->nullable();
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['contact_request_id', 'created_at']);
            $table->foreign('contact_request_id')->references('id')->on('contact_requests')->cascadeOnDelete();
            $table->foreign('staff_id')->references('id')->on('platform_staff')->nullOnDelete();
        });

        foreach (DB::table('contact_requests')->whereNotNull('note')->where('note', '<>', '')->get(['id', 'note', 'handled_by', 'updated_at', 'created_at']) as $row) {
            DB::table('contact_request_notes')->insert([
                'id' => (string) Str::uuid7(),
                'contact_request_id' => $row->id,
                'staff_id' => $row->handled_by,
                'body' => $row->note,
                'created_at' => $row->updated_at ?? $row->created_at,
            ]);
        }

        Schema::table('contact_requests', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }

    public function down(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->text('note')->nullable();
        });

        Schema::dropIfExists('contact_request_notes');
    }
};
