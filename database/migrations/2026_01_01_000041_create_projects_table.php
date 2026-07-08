<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * projects — the central project-tenancy entity. Almost every operational
 * table FKs back here. The Spatie team key (project_id) on model_has_roles /
 * model_has_permissions is what scopes RBAC to a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('name', 200);

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('project_type_id')->constrained('project_types')->restrictOnDelete();
            $table->foreignId('project_status_id')->constrained('project_statuses')->restrictOnDelete();

            $table->text('site_address')->nullable();
            $table->decimal('budget', 16, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('project_status_id');
            $table->index('client_id');
            $table->index('project_type_id');
        });

        DB::statement("CREATE UNIQUE INDEX projects_code_unique
                         ON projects (code)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
