<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * model_has_permissions — Spatie pivot for direct permission grants, scoped
 * by team (project_id).
 *
 * S-1 (§5): pure Spatie pivots have NO id, NO timestamps, NO soft deletes —
 * Spatie's queries assume composite PK semantics and no soft-delete scoping.
 * Lifecycle is captured in activity_logs instead. Single, deliberate exception
 * to the universal-timestamps rule.
 *
 * R-6: project_id is NOT NULL with default 0 (Spatie's sentinel for global
 * grants). No FK to projects — value 0 has no matching row, so a real FK
 * would block global grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 125);
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('project_id')->default(0);

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign('permission_id')
                ->references('id')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->primary(
                ['project_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_pk'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
    }
};
