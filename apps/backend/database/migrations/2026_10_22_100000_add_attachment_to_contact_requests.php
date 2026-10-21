<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->text('attachment_key')->nullable();
            $table->text('attachment_name')->nullable();
            $table->text('attachment_content_type')->nullable();
            $table->bigInteger('attachment_bytes')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE contact_requests ADD CONSTRAINT contact_requests_attachment_check CHECK ((attachment_key IS NULL AND attachment_name IS NULL AND attachment_content_type IS NULL AND attachment_bytes IS NULL) OR (attachment_key IS NOT NULL AND attachment_name IS NOT NULL AND attachment_content_type IN ('image/jpeg','image/png','image/webp','application/pdf') AND attachment_bytes > 0))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contact_requests DROP CONSTRAINT IF EXISTS contact_requests_attachment_check');
        }

        Schema::table('contact_requests', function (Blueprint $table) {
            $table->dropColumn(['attachment_key', 'attachment_name', 'attachment_content_type', 'attachment_bytes']);
        });
    }
};
