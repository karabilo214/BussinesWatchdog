<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The permissions the fixed roles had before (ADR 0079): every member keeps exactly what it could do. */
    private const PRESETS = [
        'admin' => ['stores.read', 'stores.manage', 'integrations.manage', 'checks.run', 'reconciliation.read', 'reconciliation.run', 'allocations.manage', 'incidents.read', 'incidents.manage', 'incidents.snooze', 'notifications.manage', 'team.manage'],
        'operator' => ['stores.read', 'reconciliation.read', 'reconciliation.run', 'incidents.read', 'incidents.manage'],
        'viewer' => ['stores.read', 'reconciliation.read', 'incidents.read'],
    ];

    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->jsonb('permissions')->nullable();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->jsonb('permissions')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE memberships DROP CONSTRAINT IF EXISTS memberships_role_check');
            DB::statement('ALTER TABLE invitations DROP CONSTRAINT IF EXISTS invitations_role_check');
        }

        $moneyByTenant = DB::table('tenants')->pluck('show_money_amounts_to_team', 'id');

        foreach (self::PRESETS as $role => $permissions) {
            DB::table('memberships')->where('role', $role)->orderBy('tenant_id')->get(['tenant_id', 'user_id'])->each(function ($member) use ($permissions, $moneyByTenant): void {
                DB::table('memberships')->where('tenant_id', $member->tenant_id)->where('user_id', $member->user_id)
                    ->update(['permissions' => json_encode($this->withMoney($permissions, (bool) ($moneyByTenant[$member->tenant_id] ?? true)))]);
            });

            DB::table('invitations')->where('role', $role)->get(['id', 'tenant_id'])->each(function ($invitation) use ($permissions, $moneyByTenant): void {
                DB::table('invitations')->where('id', $invitation->id)
                    ->update(['permissions' => json_encode($this->withMoney($permissions, (bool) ($moneyByTenant[$invitation->tenant_id] ?? true)))]);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('owner','admin','operator','viewer','custom'))");
            DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('admin','operator','viewer','custom'))");
            DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_permissions_check CHECK ((role = 'owner') = (permissions IS NULL))");
            DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_permissions_check CHECK (permissions IS NOT NULL)');
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('show_money_amounts_to_team');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('show_money_amounts_to_team')->default(true);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['memberships', 'invitations'] as $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_permissions_check");
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_role_check");
            }
        }

        DB::table('memberships')->where('role', 'custom')->update(['role' => 'viewer']);
        DB::table('invitations')->where('role', 'custom')->update(['role' => 'viewer']);

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('owner','admin','operator','viewer'))");
            DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('admin','operator','viewer'))");
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function withMoney(array $permissions, bool $money): array
    {
        return $money ? [...$permissions, 'money.view'] : $permissions;
    }
};
