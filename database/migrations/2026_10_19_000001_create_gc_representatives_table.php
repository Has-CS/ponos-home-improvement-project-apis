<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The people who sign for a General Contractor on site — picked from a dropdown
 * when an emergency change order's work authorization is captured, instead of
 * being typed in every time.
 *
 * Normalized: no company column (it is the GC's `name`) and no project_id (it is
 * reachable through the GC). The signed record never reads these rows — capture
 * copies the values into change_order_signatures.signer_*, so editing or retiring
 * a representative cannot alter what was signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gc_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('general_contractor_id')
                ->constrained('project_general_contractors')
                ->restrictOnDelete();

            // Lengths match the change_order_signatures.signer_* columns they are
            // copied into, so a stored representative always fits the snapshot.
            $table->string('name', 160);
            $table->string('title', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 120)->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('general_contractor_id');
        });

        // Case-insensitive, matching project_general_contractors.email.
        DB::statement('ALTER TABLE gc_representatives
                         ALTER COLUMN email TYPE CITEXT USING email::citext');
    }

    public function down(): void
    {
        Schema::dropIfExists('gc_representatives');
    }
};
