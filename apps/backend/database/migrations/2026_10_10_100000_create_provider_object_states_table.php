<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_object_states', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->text('object_type');
            $table->text('object_id');
            $table->char('content_hash', 64);
            $table->timestampTz('observed_at');

            $table->primary(['integration_id', 'object_type', 'object_id']);
            $table->foreign(['tenant_id', 'store_id', 'integration_id'])->references(['tenant_id', 'store_id', 'id'])->on('integrations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_object_states');
    }
};
