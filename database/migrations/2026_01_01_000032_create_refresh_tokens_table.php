<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * refresh_tokens — rotating refresh tokens supporting JWT refresh and per-device
 * revocation. We store token_hash, never the raw token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('token_hash', 255);
            $table->uuid('jti')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('replaced_by_id')
                ->nullable()
                ->constrained('refresh_tokens')
                ->restrictOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
            $table->index('expires_at');
        });

        // Real Postgres inet type.
        DB::statement("ALTER TABLE refresh_tokens
                         ALTER COLUMN ip_address TYPE INET USING ip_address::inet");

        DB::statement("CREATE UNIQUE INDEX refresh_tokens_token_hash_unique
                         ON refresh_tokens (token_hash)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
