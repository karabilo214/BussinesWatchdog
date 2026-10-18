<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_templates', function (Blueprint $table) {
            $table->text('topic');
            $table->text('locale');
            $table->text('subject');
            $table->text('body');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['topic', 'locale']);
            $table->foreign('updated_by')->references('id')->on('platform_staff');
        });

        Schema::create('tenant_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('topic');
            $table->uuid('sent_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('sent_by')->references('id')->on('platform_staff');
        });

        Schema::create('tenant_reminder_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reminder_id');
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->text('email');
            $table->text('locale');
            $table->text('subject');
            $table->text('body');
            $table->timestampTz('sent_at')->useCurrent();

            $table->index('reminder_id');
            $table->foreign('reminder_id')->references('id')->on('tenant_reminders')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_reminder_messages');
        Schema::dropIfExists('tenant_reminders');
        Schema::dropIfExists('reminder_templates');
    }
};
