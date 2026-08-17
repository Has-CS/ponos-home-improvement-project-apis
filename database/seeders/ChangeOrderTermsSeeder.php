<?php

namespace Database\Seeders;

use App\Models\ChangeOrderTerm;
use Illuminate\Database\Seeder;

/**
 * Seeds the company-wide default Payment Terms / Changes / Acceptance text for
 * change-order documents.
 *
 * NOT registered in DatabaseSeeder, matching PurchaseOrderTermsSeeder: this is
 * business content, not reference data, and an environment that already has
 * terms configured must not have them silently replaced. Run explicitly:
 *
 *     php artisan db:seed --class=ChangeOrderTermsSeeder
 *
 * Idempotent — skips if a default already exists, so a second run cannot trip the
 * change_order_terms_default_unique index.
 *
 * WORDING: adapted from the proposal the company currently produces by hand,
 * with "proposal" changed to "change order" throughout — the source document is a
 * bid, and a change order is an amendment to a contract already signed, so the
 * original wording would misdescribe it. The text is editable through the CRUD,
 * so refining it is a content decision rather than a code change.
 */
class ChangeOrderTermsSeeder extends Seeder
{
    /** One paragraph per line; blank lines are collapsed when rendered. */
    private const PAYMENT_TERMS = <<<'TEXT'
    As per the approved schedule of values.
    Payments should be made to Ponos Home Improvement, Ltd. via Certified Check or ACH transfer, and an Official Receipt or Acknowledgement Receipt will be issued for every transfer received and validated.
    Should the Client be in default during the on-going construction, Ponos Home Improvement, Ltd. has the right to give notice and may stop performance until the Client, as named herein, corrects the default within thirty (30) calendar days.
    TEXT;

    private const CHANGES = <<<'TEXT'
    Changes to this change order must be agreed upon in writing by both parties.
    This change order may be withdrawn if not accepted by the proposed party within 30 days from the issuance date.
    TEXT;

    private const ACCEPTANCE = <<<'TEXT'
    Signature of this change order by the General Contractor constitutes authorisation to proceed with the work described, and agreement to the adjustment to the contract sum stated above.
    This change order, once signed by both parties, forms part of the contract.
    TEXT;

    public function run(): void
    {
        if (ChangeOrderTerm::whereNull('project_id')->exists()) {
            $this->command?->info('Default change-order terms already exist — skipping.');

            return;
        }

        ChangeOrderTerm::create([
            'project_id' => null,
            'payment_terms_body' => self::PAYMENT_TERMS,
            'changes_body' => self::CHANGES,
            'acceptance_body' => self::ACCEPTANCE,
        ]);
    }
}
