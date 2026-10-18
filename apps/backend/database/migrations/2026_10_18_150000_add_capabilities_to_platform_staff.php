<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The permissions the fixed roles had before (ADR 0064): every such member keeps exactly what it could do. */
    private const PRESETS = [
        'platform_admin' => ['plans.read', 'tenants.read', 'customers.mfa_reset', 'audit.read', 'contacts.read', 'service.read'],
        'platform_support' => ['tenants.read', 'customers.mfa_reset', 'contacts.read', 'contacts.manage'],
        'platform_billing' => ['plans.read', 'tenants.read', 'billing.read', 'refunds.request'],
    ];

    public function up(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->jsonb('capabilities')->nullable();
        });
        Schema::table('platform_staff_invitations', function (Blueprint $table) {
            $table->jsonb('capabilities')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE platform_staff DROP CONSTRAINT IF EXISTS platform_staff_role_check');
            DB::statement('ALTER TABLE platform_staff_invitations DROP CONSTRAINT IF EXISTS platform_staff_invitations_role_check');
        }

        foreach (self::PRESETS as $role => $capabilities) {
            foreach (['platform_staff', 'platform_staff_invitations'] as $table) {
                DB::table($table)->where('role', $role)->update(['role' => 'platform_member', 'capabilities' => json_encode($capabilities)]);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_role_check CHECK (role IN ('platform_owner','platform_member'))");
            DB::statement("ALTER TABLE platform_staff_invitations ADD CONSTRAINT platform_staff_invitations_role_check CHECK (role IN ('platform_owner','platform_member'))");
            DB::statement("ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_capabilities_check CHECK ((role = 'platform_owner') = (capabilities IS NULL))");
            DB::statement("ALTER TABLE platform_staff_invitations ADD CONSTRAINT platform_staff_invitations_capabilities_check CHECK ((role = 'platform_owner') = (capabilities IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['platform_staff', 'platform_staff_invitations'] as $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_capabilities_check");
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_role_check");
            }
        }

        foreach (['platform_staff', 'platform_staff_invitations'] as $table) {
            DB::table($table)->where('role', 'platform_member')->update(['role' => 'platform_admin']);
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('capabilities');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_role_check CHECK (role IN ('platform_owner','platform_admin','platform_support','platform_billing'))");
            DB::statement("ALTER TABLE platform_staff_invitations ADD CONSTRAINT platform_staff_invitations_role_check CHECK (role IN ('platform_owner','platform_admin','platform_support','platform_billing'))");
        }
    }
};
