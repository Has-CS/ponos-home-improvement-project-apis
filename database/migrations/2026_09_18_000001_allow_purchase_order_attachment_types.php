<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two more `attachment_type` values, for purchase-order paperwork:
 *
 *  - `supporting`    the files a buyer attaches to an order (a rate screenshot,
 *                    a vendor's emailed quote, a signed contract scan). These are
 *                    appended to the PDF the vendor receives.
 *  - `sent_document` the merged copy that actually went to the vendor, filed so
 *                    what was sent stays retrievable even after the supporting
 *                    files change.
 *
 * The column is guarded by a CHECK rather than an enum, so the list has to be
 * replaced. Done as its own migration — the original create_attachments_table
 * migration is left untouched.
 */
return new class extends Migration
{
    private const TYPES = ['photo', 'bol', 'document', 'signature', 'supporting', 'sent_document'];

    public function up(): void
    {
        $this->replaceCheck(self::TYPES);
    }

    public function down(): void
    {
        // Rows using the new types would violate the narrower constraint, so
        // they go first. Only ever purchase-order paperwork, never the generated
        // order document itself, which stays a plain `document`.
        DB::table('attachments')
            ->whereIn('attachment_type', ['supporting', 'sent_document'])
            ->delete();

        $this->replaceCheck(['photo', 'bol', 'document', 'signature']);
    }

    /** @param array<int,string> $types */
    private function replaceCheck(array $types): void
    {
        $list = implode(',', array_map(fn (string $type) => "'{$type}'", $types));

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_attachment_type_check');
        DB::statement("ALTER TABLE attachments
                         ADD CONSTRAINT attachments_attachment_type_check
                         CHECK (attachment_type IN ({$list}))");
    }
};
