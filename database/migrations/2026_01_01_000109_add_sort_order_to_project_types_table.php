<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * project_types was missing sort_order, unlike its sibling lookup tables
 * (genders, user_statuses, milestone_phases, project_statuses, milestone_statuses).
 * Added here for a uniform shape ahead of the generic lookup CRUD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_types', function (Blueprint $table) {
            $table->smallInteger('sort_order')->default(0)->after('label');
        });

        // Backfill by existing id order — matches LookupSeeder's insertion
        // order (residential, commercial, renovation); no business preference expressed.
        $ids = DB::table('project_types')->orderBy('id')->pluck('id');
        foreach ($ids as $i => $id) {
            DB::table('project_types')->where('id', $id)->update(['sort_order' => $i + 1]);
        }
    }

    public function down(): void
    {
        Schema::table('project_types', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
