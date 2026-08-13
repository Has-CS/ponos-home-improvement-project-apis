<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text ("WhatsApp-style") material requests.
 *
 * Many foremen can't or won't navigate the catalog and trade-category pickers,
 * and that friction was blocking adoption. This lets them describe what they
 * need in prose (and photograph it — see the `attachments` reuse below), and
 * pushes the mapping-to-catalog-items work downstream to the office, where it
 * happens when Procurement cuts the purchase order.
 *
 * `request_text` is deliberately NOT reusing `notes`. `notes` is editable
 * commentary that anyone may revise; this column is the foreman's original
 * message and is frozen once the request is submitted, so it stays usable as
 * the reference Procurement builds the PO from, and as an audit record.
 *
 * `structured_by` / `structured_at` record who first turned prose into line
 * items, IF anyone did. Staying null forever is a valid outcome: a request may
 * legitimately reach `approved` with zero line items and be structured only at
 * the PO.
 *
 * No column is added for photos or for the audit trail — both already exist:
 * `attachments` is polymorphic and its attachment_type CHECK already permits
 * 'photo', and material_request_approvals.action already permits 'edit'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->text('request_text')->nullable()->after('notes');

            $table->foreignId('structured_by')
                ->nullable()
                ->after('request_text')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('structured_at')->nullable()->after('structured_by');
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropForeign(['structured_by']);
            $table->dropColumn(['request_text', 'structured_by', 'structured_at']);
        });
    }
};
