<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * catalog_items — central catalog of materials, labor, subcontractor items,
 * and custom items. Custom items may be project-specific (project_id set).
 *
 * `attributes` is jsonb for flexible per-type attrs (dimensions, finish,
 * pieces-per-bundle) without a wide, sparse column set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_category_id')->constrained('trade_categories')->restrictOnDelete();
            $table->foreignId('catalog_item_type_id')->constrained('catalog_item_types')->restrictOnDelete();
            $table->foreignId('default_unit_id')->constrained('units')->restrictOnDelete();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->restrictOnDelete();

            $table->string('sku', 60)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->jsonb('attributes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('trade_category_id');
            $table->index('catalog_item_type_id');
            $table->index('project_id');
        });

        // SKU is optional. Uniqueness only enforced when present AND row is live.
        DB::statement("CREATE UNIQUE INDEX catalog_items_sku_unique
                         ON catalog_items (sku)
                         WHERE sku IS NOT NULL AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
