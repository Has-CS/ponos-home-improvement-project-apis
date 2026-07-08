<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * permissions — Spatie laravel-permission. Kept byte-compatible with the
 * published package so HasRoles / hasPermissionTo work unmodified.
 *
 * Per §5 (S-1): no soft deletes on the three pure pivots
 * (model_has_roles, model_has_permissions, role_has_permissions).
 * `permissions` and `roles` themselves DO carry Laravel timestamps, matching
 * Spatie's own migration shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 125);
            $table->string('guard_name', 125)->default('api');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
