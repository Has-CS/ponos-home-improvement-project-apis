<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * project_user — application-level staffing record that drives Spatie
 * model_has_roles and carries assignment metadata + lifecycle.
 *
 * The "team assignment wizard" writes here; an application service mirrors
 * the (user_id, role_id, project_id) rows into Spatie's model_has_roles for
 * authorization. The small redundancy is deliberate (§1.1) — Spatie's pivot
 * cannot hold assigned_by / assigned_at / is_active / deactivated_at / soft
 * deletes without forking the package.
 *
 * Assigning here with is_active=true activates the user's project-scoped access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();

            $table->boolean('is_active')->default(true);

            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestampTz('deactivated_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
            $table->index(['project_id', 'is_active']);
        });

        // R-4: a user may hold the same role on the same project only once at a time.
        // Soft-deleting frees the slot for reassignment.
        DB::statement("CREATE UNIQUE INDEX project_user_project_user_role_unique
                         ON project_user (project_id, user_id, role_id)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
    }
};
