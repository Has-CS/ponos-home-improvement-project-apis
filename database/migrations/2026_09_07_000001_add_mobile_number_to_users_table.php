<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A contact mobile number for a user.
 *
 * Until now `users` carried no telephone column at all — the one place that
 * already wanted one is the RFQ document's "Prepared by" panel, which prints a
 * name and email and notes in its own template comment that there is no phone
 * column to print from.
 *
 * Named `mobile_number` rather than `phone`, which is the convention on
 * `clients`, `vendors` and `project_general_contractors`. Those are
 * organisations with a single switchboard number; a staff user's mobile is a
 * different thing — it is how you reach a foreman standing on a site — and
 * keeping the name specific leaves room for an office `phone` later without
 * renaming this one. The 40-char width is taken from those tables so every
 * telephone column in the schema is still sized alike.
 *
 * Deliberately NULLABLE, for the same reason `material_requests.title` is:
 * users already exist without a number and none of them can be given an honest
 * one retroactively, so a NOT NULL column would mean inventing placeholder
 * data for every historical row. The requirement is enforced one layer up
 * instead — StoreUserRequest marks it `required`, so every user created through
 * the API from now on must supply one, while existing rows, the admin seeder
 * and the test factory are untouched.
 *
 * No unique index: shared site phones passed between foremen are ordinary in
 * construction, and this application only ever soft-deletes, so a unique
 * constraint would permanently burn an offboarded user's number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile_number', 40)->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mobile_number');
        });
    }
};
