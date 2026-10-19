<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Stripe App OAuth path was removed (ADR 0020, 2026-10-19); environments that ran its migration drop its tables.
     */
    public function up(): void
    {
        Schema::dropIfExists('stripe_app_oauth_connections');
        Schema::dropIfExists('stripe_app_oauth_attempts');
    }

    public function down(): void {}
};
