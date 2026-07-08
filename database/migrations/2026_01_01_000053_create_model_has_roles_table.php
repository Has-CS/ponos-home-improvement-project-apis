<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * model_has_roles — Spatie pivot. THE project-scoped authorization source of
 * truth: a user holding a role within a project (or globally, via the 0
 * sentinel in project_id).
 *
 * project_user (application table) is kept in sync with this by an
 * application service: project_user owns assignment lifecycle and audit,
 * model_has_roles owns authorization resolution. See §1.1.
 *
 * S-1: no id / no timestamps / no soft deletes — Spatie compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type', 125);
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('project_id')->default(0);

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();

            $table->primary(
                ['project_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_pk'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_roles');
    }
};
