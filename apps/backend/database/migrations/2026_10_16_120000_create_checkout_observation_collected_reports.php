<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_observation_collected_reports', function (Blueprint $table) {
            $table->uuid('integration_id');
            $table->string('report_id', 32);
            $table->uuid('tenant_id');
            $table->timestamp('collected_at');
            $table->primary(['integration_id', 'report_id']);
            $table->index('collected_at');
            $table->foreign('integration_id')->references('id')->on('integrations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_observation_collected_reports');
    }
};
