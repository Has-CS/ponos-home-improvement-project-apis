<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds is_system to the 6 lookup tables getting CRUD. Rows the app's PHP
 * constants depend on (UserStatus::ACTIVE gates login, ProjectStatus::ACTIVE
 * is the create default, MilestoneStatus::NOT_STARTED/COMPLETED drive
 * create-default + completion) are flagged so the API can lock their code/
 * is_terminal and block deletion, while leaving label/sort_order editable.
 *
 * Matched by literal code string, not model constants, to keep this
 * migration decoupled from app-layer class definitions (same convention as
 * migration 2026_01_01_000107).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['genders', 'user_statuses', 'project_statuses', 'project_types', 'milestone_phases', 'milestone_statuses'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->boolean('is_system')->default(false)->after('sort_order'));
        }

        DB::table('user_statuses')->whereIn('code', ['active', 'invited', 'suspended', 'disabled'])->update(['is_system' => true]);
        DB::table('project_statuses')->whereIn('code', ['active', 'on_hold', 'completed', 'archived'])->update(['is_system' => true]);
        DB::table('milestone_statuses')->whereIn('code', ['not_started', 'in_progress', 'completed', 'delayed', 'on_hold', 'cancelled'])->update(['is_system' => true]);
        // genders, project_types, milestone_phases: left at default false —
        // no runtime code branches on their specific codes.
    }

    public function down(): void
    {
        foreach (['genders', 'user_statuses', 'project_statuses', 'project_types', 'milestone_phases', 'milestone_statuses'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('is_system'));
        }
    }
};
