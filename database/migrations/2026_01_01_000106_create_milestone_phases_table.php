<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * milestone_phases — lookup for the construction-phase a milestone belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_phases', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE UNIQUE INDEX milestone_phases_code_unique ON milestone_phases (code) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_phases');
    }
};
