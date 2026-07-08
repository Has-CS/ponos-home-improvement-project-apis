<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * login_attempts — security log for auth events. May reference NO user row
 * (failed login on unknown email), so user_id is nullable and email_attempted
 * is captured separately.
 *
 * Append-only in practice (assumption A-9). deleted_at is present only for
 * schema uniformity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('email_attempted', 255)->nullable();
            $table->boolean('was_successful');
            $table->string('failure_reason', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
            $table->index('email_attempted');
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE login_attempts
                         ALTER COLUMN email_attempted TYPE CITEXT USING email_attempted::citext");

        DB::statement("ALTER TABLE login_attempts
                         ALTER COLUMN ip_address TYPE INET USING ip_address::inet");
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
