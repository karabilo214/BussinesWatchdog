<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('object_key');
            $table->text('content_type');
            $table->text('extension');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->text('original_name');
            $table->uuid('uploaded_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();

            $table->index(['deleted_at', 'created_at']);
            $table->foreign('uploaded_by')->references('id')->on('platform_staff');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_media');
    }
};
