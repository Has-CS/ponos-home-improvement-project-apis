<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * token_denylist — JWT access tokens are stateless, so logout/revocation is
 * implemented by denylisting the token's jti until expires_at. A scheduled
 * purge job deletes expired rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_denylist', function (Blueprint $table) {
            $table->id();
            $table->uuid('jti');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 60)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
            $table->index('expires_at');
        });

        DB::statement("CREATE UNIQUE INDEX token_denylist_jti_unique
                         ON token_denylist (jti)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('token_denylist');
    }
};
