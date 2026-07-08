<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * trade_categories: doors, tiles, labor, finishes, cabinets, floor_sheets, etc.
 * Self-referencing (parent_id) for sub-categories. Also serves as the
 * "material category" dropdown source (assumption A-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_categories', function (Blueprint $table) {
            $table->id();
            // Self-ref FK; Postgres allows this in the same CREATE TABLE.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('trade_categories')
                ->restrictOnDelete();
            $table->string('name', 120);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('parent_id');
        });

        // Composite uniqueness on (parent_id, name) only while the row is live (R-4).
        DB::statement("CREATE UNIQUE INDEX trade_categories_parent_name_unique
                         ON trade_categories (parent_id, name)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_categories');
    }
};
