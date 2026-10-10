<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mfa_secret_ciphertext');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret_ciphertext')->nullable();
            $table->smallInteger('mfa_key_version')->nullable();
            $table->timestampTz('mfa_confirmed_at')->nullable();
            $table->bigInteger('mfa_last_used_step')->nullable();
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->text('code_hash');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret_ciphertext', 'mfa_key_version', 'mfa_confirmed_at', 'mfa_last_used_step']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->binary('mfa_secret_ciphertext')->nullable();
        });
    }
};
