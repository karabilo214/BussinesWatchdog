<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $jsonDefault = fn (string $value) => DB::getDriverName() === 'pgsql' ? DB::raw("'{$value}'::jsonb") : $value;

        Schema::create('public_storefront_checks', function (Blueprint $table) use ($jsonDefault) {
            $table->uuid('id')->primary();
            $table->char('result_token_hash', 64)->unique();
            $table->text('locale');
            $table->text('submitted_url');
            $table->text('normalized_origin');
            $table->text('normalized_host');
            $table->char('ip_hash', 64);
            $table->text('status')->default('queued');
            $table->text('verdict')->nullable();
            $table->text('error_code')->nullable();
            $table->jsonb('steps')->default($jsonDefault('[]'));
            $table->jsonb('diagnostics')->default($jsonDefault('{}'));
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->char('lease_token_hash', 64)->nullable();
            $table->timestampTz('lease_until')->nullable();
            $table->timestampTz('absolute_deadline_at')->nullable();
            $table->text('browser_version')->nullable();
            $table->text('location')->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->timestampTz('queued_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['status', 'queued_at'], 'public_storefront_checks_queue_idx');
            $table->index(['normalized_origin', 'created_at'], 'public_storefront_checks_origin_idx');
            $table->index(['ip_hash', 'created_at'], 'public_storefront_checks_ip_idx');
            $table->index(['expires_at'], 'public_storefront_checks_expiry_idx');
        });

        Schema::create('public_storefront_check_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('check_id');
            $table->text('kind');
            $table->text('object_key')->unique();
            $table->text('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('redaction_version');
            $table->text('state');
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('check_id')->references('id')->on('public_storefront_checks')->cascadeOnDelete();
            $table->index(['check_id', 'state'], 'public_storefront_check_artifacts_check_idx');
            $table->index(['state', 'expires_at'], 'public_storefront_check_artifacts_expiry_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE public_storefront_checks ADD CONSTRAINT public_storefront_checks_status_check CHECK (status IN ('queued','running','passed','failed','inconclusive','blocked','unsupported','cancelled','expired'))");
            DB::statement("ALTER TABLE public_storefront_checks ADD CONSTRAINT public_storefront_checks_verdict_check CHECK (verdict IS NULL OR verdict IN ('checkout_reached','needs_connection','public_issue','blocked','insufficient_data'))");
            DB::statement('ALTER TABLE public_storefront_checks ADD CONSTRAINT public_storefront_checks_deadline_check CHECK (absolute_deadline_at IS NULL OR started_at IS NULL OR absolute_deadline_at >= started_at)');
            DB::statement("ALTER TABLE public_storefront_check_artifacts ADD CONSTRAINT public_storefront_check_artifacts_kind_check CHECK (kind IN ('screenshot','diagnostics'))");
            DB::statement("ALTER TABLE public_storefront_check_artifacts ADD CONSTRAINT public_storefront_check_artifacts_state_check CHECK (state IN ('ready','deleted'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_storefront_check_artifacts');
        Schema::dropIfExists('public_storefront_checks');
    }
};
