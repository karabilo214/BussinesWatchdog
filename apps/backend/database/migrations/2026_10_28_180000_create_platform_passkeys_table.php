<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_passkeys', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('staff_id')->constrained('platform_staff')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_passkeys');
    }
};
