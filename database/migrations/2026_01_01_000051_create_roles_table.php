<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * roles — Spatie laravel-permission with the teams feature enabled.
 *
 * R-6: roles.project_id IS a real nullable FK to projects(id). NULL = global
 * role (e.g. Admin). This is DISTINCT from the project_id columns on
 * model_has_roles / model_has_permissions, which are part of a composite PK
 * (and so cannot be NULL — Spatie uses the `0` sentinel there for global grants
 * and intentionally has no FK on those).
 *
 * Seed roles after migrating: Admin (global), Project Manager, Assistant Project
 * Manager, Project Coordinator, Site Engineer, Foreman, Procurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->restrictOnDelete();
            $table->string('name', 125);
            $table->string('guard_name', 125)->default('api');
            $table->timestamps();

            $table->unique(['project_id', 'name', 'guard_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
