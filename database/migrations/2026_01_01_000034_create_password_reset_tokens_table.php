<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * password_reset_tokens — time-boxed, single-use reset tokens.
 * Replaces Laravel's default password_reset_tokens migration with an explicit
 * expires_at/used_at lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('token_hash', 255);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
        });

        DB::statement("CREATE UNIQUE INDEX password_reset_tokens_token_hash_unique
                         ON password_reset_tokens (token_hash)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
