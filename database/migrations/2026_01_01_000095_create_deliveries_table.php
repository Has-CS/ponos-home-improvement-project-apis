<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deliveries — receipt event against a PO. Photo / BOL evidence lives in
 * the polymorphic `attachments` table (attachable_type=Delivery, attachable_id=this.id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();

            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at')->useCurrent();

            $table->string('bol_number', 80)->nullable();
            $table->boolean('has_discrepancy')->default(false);
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('purchase_order_id');
            $table->index('received_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
