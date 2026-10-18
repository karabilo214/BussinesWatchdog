<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_reply_templates', function (Blueprint $table) {
            $table->text('code');
            $table->text('locale');
            $table->text('subject');
            $table->text('body');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['code', 'locale']);
            $table->foreign('updated_by')->references('id')->on('platform_staff')->nullOnDelete();
        });

        Schema::table('contact_request_notes', function (Blueprint $table) {
            $table->timestampTz('edited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_request_notes', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
        Schema::dropIfExists('contact_reply_templates');
    }
};
