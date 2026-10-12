<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_discount_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('checkout_session_id');
            $table->uuid('contract_id')->nullable();
            $table->uuid('discount_id');
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('periods_total')->nullable();
            $table->unsignedSmallInteger('periods_used')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['checkout_session_id', 'discount_id']);
            $table->index(['contract_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('checkout_session_id')->references('id')->on('billing_checkout_sessions');
            $table->foreign('contract_id')->references('id')->on('billing_subscription_contracts');
            $table->foreign('discount_id')->references('id')->on('billing_discounts');
        });

        Schema::create('billing_discount_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('checkout_session_id');
            $table->uuid('contract_id')->nullable();
            $table->unsignedSmallInteger('period_number');
            $table->timestampTz('period_start')->nullable();
            $table->string('currency', 3);
            $table->smallInteger('currency_exponent');
            $table->bigInteger('amount_before_minor');
            $table->bigInteger('saving_minor');
            $table->bigInteger('amount_after_minor');
            $table->text('provider_discount_ref')->nullable();
            $table->text('status');
            $table->text('error_code')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('applied_at')->nullable();

            $table->unique(['checkout_session_id', 'period_number']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('checkout_session_id')->references('id')->on('billing_checkout_sessions');
            $table->foreign('contract_id')->references('id')->on('billing_subscription_contracts');
        });

        Schema::create('billing_discount_application_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('discount_id');
            $table->unsignedSmallInteger('position');
            $table->bigInteger('amount_before_minor');
            $table->bigInteger('saving_minor');
            $table->bigInteger('amount_after_minor');

            $table->unique(['application_id', 'discount_id']);
            $table->foreign('application_id')->references('id')->on('billing_discount_applications');
            $table->foreign('discount_id')->references('id')->on('billing_discounts');
        });

        Schema::table('billing_checkout_sessions', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
            $table->dropColumn('discount_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE billing_discount_grants ADD CONSTRAINT billing_discount_grants_position_check CHECK (position BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE billing_discount_grants ADD CONSTRAINT billing_discount_grants_periods_check CHECK (periods_total IS NULL OR periods_used <= periods_total)');
            DB::statement("ALTER TABLE billing_discount_applications ADD CONSTRAINT billing_discount_applications_status_check CHECK (status IN ('planned','applied','failed'))");
            DB::statement('ALTER TABLE billing_discount_applications ADD CONSTRAINT billing_discount_applications_amount_check CHECK (saving_minor >= 0 AND amount_after_minor >= 0 AND amount_before_minor = saving_minor + amount_after_minor)');
            DB::statement('ALTER TABLE billing_discount_application_lines ADD CONSTRAINT billing_discount_lines_amount_check CHECK (saving_minor >= 0 AND amount_after_minor >= 0 AND amount_before_minor = saving_minor + amount_after_minor)');
        }
    }

    public function down(): void
    {
        Schema::table('billing_checkout_sessions', function (Blueprint $table) {
            $table->uuid('discount_id')->nullable();
            $table->foreign('discount_id')->references('id')->on('billing_discounts');
        });
        Schema::dropIfExists('billing_discount_application_lines');
        Schema::dropIfExists('billing_discount_applications');
        Schema::dropIfExists('billing_discount_grants');
    }
};
