<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * milestone_statuses — lookup for milestone workflow state.
 *
 * Seeds only the two rows ('not_started', 'completed') that the very next
 * migration needs to backfill status_id on pre-existing milestone rows —
 * migrations run before seeders, so LookupSeeder isn't available yet here.
 * LookupSeeder::run() later firstOrCreate()s the full set, no-oping on
 * these two and inserting the remaining four.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE UNIQUE INDEX milestone_statuses_code_unique ON milestone_statuses (code) WHERE deleted_at IS NULL');

        $now = now();
        DB::table('milestone_statuses')->insert([
            ['code' => 'not_started', 'label' => 'Not Started', 'sort_order' => 1, 'is_terminal' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'completed', 'label' => 'Completed', 'sort_order' => 3, 'is_terminal' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_statuses');
    }
};
