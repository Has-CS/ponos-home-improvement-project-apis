<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * change_order_signatures — emergency-CO on-device GC-rep signature, tied to
 * time and place.
 *
 * The signature image itself lives in `attachments` (attachable_type=ChangeOrder
 * or =ChangeOrderSignature is fine; the spec ties it via signature_attachment_id
 * for direct lookup).
 *
 * Lat/lng are numeric(9,6) — six decimals gives ~11 cm precision, ample for
 * "where the signature was captured". device_info is jsonb to keep device /
 * OS / app-version metadata flexible.
 *
 * Open question Q8 in the schema: any legal-retention / tamper-evidence
 * requirement beyond image + geo/time? If yes, add a hash-chain column later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_order_signatures', function (Blueprint $table) {
            $table->id();

            $table->foreignId('change_order_id')
                ->constrained('change_orders')
                ->restrictOnDelete();

            $table->string('signer_name', 160);            // GC representative
            $table->string('signer_title', 120)->nullable();
            $table->string('signer_company', 160)->nullable();
            $table->string('signer_contact', 120)->nullable(); // phone / email

            $table->foreignId('signature_attachment_id')
                ->constrained('attachments')
                ->restrictOnDelete();

            $table->timestampTz('signed_at');               // time of signing
            $table->decimal('signed_lat', 9, 6)->nullable(); // place of signing
            $table->decimal('signed_lng', 9, 6)->nullable();
            $table->string('location_note', 200)->nullable();

            $table->foreignId('captured_by')                // device operator
                ->constrained('users')
                ->restrictOnDelete();

            $table->jsonb('device_info')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('change_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_order_signatures');
    }
};
