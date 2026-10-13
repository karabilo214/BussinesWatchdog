<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_pages', function (Blueprint $table) {
            $table->text('policy_type')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX public_pages_policy_unique ON public_pages(policy_type) WHERE policy_type IS NOT NULL AND archived_at IS NULL');

        Schema::create('policy_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('tenant_id')->nullable();
            $table->text('policy_type');
            $table->uuid('page_version_id');
            $table->text('locale');
            $table->unsignedInteger('version');
            $table->char('content_hash', 64);
            $table->text('purpose');
            $table->uuid('context_id')->nullable();
            $table->timestampTz('accepted_at');

            $table->index(['tenant_id', 'accepted_at']);
            $table->index(['user_id', 'accepted_at']);
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('page_version_id')->references('id')->on('public_page_versions');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE public_pages ADD CONSTRAINT public_pages_policy_check CHECK (policy_type IS NULL OR (policy_type IN ('service_terms','privacy_notice','recurring_payment') AND kind = 'page'))");
            DB::statement("ALTER TABLE policy_acceptances ADD CONSTRAINT policy_acceptances_type_check CHECK (policy_type IN ('service_terms','privacy_notice','recurring_payment'))");
            DB::statement("ALTER TABLE policy_acceptances ADD CONSTRAINT policy_acceptances_purpose_check CHECK (purpose IN ('signup','trial','subscription_purchase','plan_change'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acceptances');
        DB::statement('DROP INDEX IF EXISTS public_pages_policy_unique');
        Schema::table('public_pages', function (Blueprint $table) {
            $table->dropColumn('policy_type');
        });
    }
};
