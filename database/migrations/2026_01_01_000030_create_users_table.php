<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users — identity + profile. Credentials live in user_credentials (1:1).
 *
 * R-1: NO is_active boolean. user_statuses is the single source of truth for
 * "may this user authenticate" — the login/refresh gate checks status.code='active'.
 *
 * Note: no role_id column. Roles are project-scoped and authoritative in Spatie
 * (see assumption A-2). The post-login dashboard is derived from the user's
 * highest global Spatie role.
 *
 * The self-ref FK (created_by → users.id) is defined inline; Postgres permits
 * this on a freshly-created table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 80);
            $table->string('last_name', 80);

            $table->foreignId('gender_id')
                ->nullable()
                ->constrained('genders')
                ->restrictOnDelete();

            $table->date('date_of_birth')->nullable();

            $table->foreignId('user_status_id')
                ->constrained('user_statuses')
                ->restrictOnDelete();

            $table->timestampTz('last_login_at')->nullable();

            // Self-ref: who created this user.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // Laravel auth scaffolding compatibility.
            $table->rememberToken();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_status_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
