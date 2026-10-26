<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->text('new_email');
            $table->char('confirm_token_hash', 64)->nullable();
            $table->char('cancel_token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE email_change_requests ADD CONSTRAINT email_change_requests_final_check CHECK (confirmed_at IS NULL OR cancelled_at IS NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_requests');
    }
};
