<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One more `attachment_type`: `authorization`.
 *
 * The Work Authorization sheet — the on-site record of what the GC's
 * representative signed, with the signature embedded — filed at capture time as
 * its own document. It is what travels to the General Contractor as PROOF
 * alongside the priced change order: "this work was done at your request, here is
 * the signed authorization, and this is the payment against it."
 *
 * It CANNOT be filed as a plain `document`, and that is not a naming preference.
 * ChangeOrderPdfService::storeFor() soft-deletes every `document`-typed attachment
 * on the change order before filing a new one, precisely so exactly one current
 * change-order document exists. Filing the authorization under that type would
 * mean the first prepare or send destroyed the evidence.
 *
 * `sent_document` is already allowed by 2026_09_18_000001 and is reused as-is for
 * the merged proof package that actually leaves the building.
 *
 * The column is guarded by a CHECK rather than an enum, so the list has to be
 * replaced. Done as its own migration — the original create_attachments_table
 * migration is left untouched.
 */
return new class extends Migration
{
    private const TYPES = ['photo', 'bol', 'document', 'signature', 'supporting', 'sent_document', 'authorization'];

    public function up(): void
    {
        $this->replaceCheck(self::TYPES);
    }

    public function down(): void
    {
        // Rows using the new type would violate the narrower constraint, so they
        // go first. Only ever the work-authorization sheet, never the generated
        // change-order document itself, which stays a plain `document`.
        DB::table('attachments')->where('attachment_type', 'authorization')->delete();

        $this->replaceCheck(['photo', 'bol', 'document', 'signature', 'supporting', 'sent_document']);
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
