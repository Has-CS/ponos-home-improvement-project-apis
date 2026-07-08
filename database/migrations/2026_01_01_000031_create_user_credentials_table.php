<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * user_credentials — 1:1 with users. Credentials live here so login/forgot-password
 * queries hit a narrow, focused table.
 *
 * R-2: must_change_password supports the admin-create registration flow. Admin
 * creates the row with a temp hash + must_change_password=true; first login forces
 * a password change which clears the flag and stamps password_changed_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_credentials', function (Blueprint $table) {
            $table->id();

            // 1:1 — partial unique enforced below.
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Defined as string here; promoted to CITEXT below for case-insensitive lookup.
            $table->string('email', 255);

            $table->string('password', 255);

            $table->boolean('must_change_password')->default(false);
            $table->timestampTz('password_changed_at')->nullable();
            $table->timestampTz('email_verified_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Promote to citext (requires the citext extension, enabled in 0001).
        DB::statement("ALTER TABLE user_credentials
                         ALTER COLUMN email TYPE CITEXT USING email::citext");

        // R-4: partial uniques on a soft-deletable table.
        DB::statement("CREATE UNIQUE INDEX user_credentials_user_id_unique
                         ON user_credentials (user_id)
                         WHERE deleted_at IS NULL");

        DB::statement("CREATE UNIQUE INDEX user_credentials_email_unique
                         ON user_credentials (email)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_credentials');
    }
};
