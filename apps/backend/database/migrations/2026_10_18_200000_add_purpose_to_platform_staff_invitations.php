<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_staff_invitations', function (Blueprint $table) {
            $table->text('purpose')->default('join');
            $table->uuid('staff_id')->nullable();
            $table->foreign('staff_id')->references('id')->on('platform_staff');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE platform_staff_invitations ADD CONSTRAINT platform_staff_invitations_purpose_check CHECK (purpose IN ('join','mfa_reset') AND ((purpose = 'mfa_reset') = (staff_id IS NOT NULL)))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE platform_staff_invitations DROP CONSTRAINT IF EXISTS platform_staff_invitations_purpose_check');
        }

        DB::table('platform_staff_invitations')->where('purpose', 'mfa_reset')->delete();

        Schema::table('platform_staff_invitations', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->dropColumn(['purpose', 'staff_id']);
        });
    }
};
