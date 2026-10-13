<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_request_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contact_request_id');
            $table->uuid('staff_id')->nullable();
            $table->text('template');
            $table->text('subject');
            $table->text('body');
            $table->timestampTz('sent_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['contact_request_id', 'sent_at']);
            $table->foreign('contact_request_id')->references('id')->on('contact_requests')->cascadeOnDelete();
            $table->foreign('staff_id')->references('id')->on('platform_staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_request_replies');
    }
};
