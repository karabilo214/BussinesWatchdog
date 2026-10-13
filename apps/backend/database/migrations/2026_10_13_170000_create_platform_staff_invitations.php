<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('email');
            $table->text('role');
            $table->char('token_hash', 64)->unique();
            $table->uuid('invited_by');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->uuid('accepted_staff_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['email', 'accepted_at', 'revoked_at']);
            $table->foreign('invited_by')->references('id')->on('platform_staff');
            $table->foreign('accepted_staff_id')->references('id')->on('platform_staff');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE platform_staff_invitations ADD CONSTRAINT platform_staff_invitations_role_check CHECK (role IN ('platform_owner','platform_admin','platform_support','platform_billing'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_staff_invitations');
    }
};
