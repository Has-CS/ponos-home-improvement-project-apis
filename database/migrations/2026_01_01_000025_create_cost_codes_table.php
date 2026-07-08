<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cost_codes: standard cost-code library (CSI-style), global per A-7.
 * Self-referencing for hierarchy. Referenced by material_request_items,
 * purchase_order_items, change_orders, estimate_line_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('cost_codes')
                ->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('parent_id');
        });

        // R-4: partial unique on code.
        DB::statement("CREATE UNIQUE INDEX cost_codes_code_unique
                         ON cost_codes (code)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_codes');
    }
};
