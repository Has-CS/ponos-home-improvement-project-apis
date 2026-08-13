<?php

namespace Database\Seeders;

use App\Models\PurchaseOrderTerm;
use Illuminate\Database\Seeder;

/**
 * Seeds the company-wide default Terms & Conditions for purchase orders.
 *
 * NOT registered in DatabaseSeeder: this is business content, not reference
 * data, and an environment that already has terms configured must not have them
 * silently replaced. Run explicitly:
 *
 *     php artisan db:seed --class=PurchaseOrderTermsSeeder
 *
 * Idempotent — skips if a default already exists, so a second run cannot trip
 * the purchase_order_terms_default_unique index.
 */
class PurchaseOrderTermsSeeder extends Seeder
{
    /**
     * One clause per line. Clause 5 deliberately says "the agreed payment
     * terms" rather than "the payment terms stated above": purchase_orders has
     * no payment-terms field, so the original wording would point the vendor at
     * a part of the document that does not exist. Reword again if a real
     * payment_terms column is ever added.
     */
    private const DEFAULT_BODY = <<<'TEXT'
    This purchase order number must appear on all invoices, delivery notes, packing slips and correspondence.
    Deliveries are accepted only at the Ship To address above, during site working hours, and must be accompanied by a signed delivery note or bill of lading.
    Quantities and prices are as stated. Substitutions, back-orders, over-supply and price changes require prior written approval from Ponos Home Improvement, Ltd.
    Goods are subject to inspection on receipt. Items rejected for damage, shortage or non-conformance may be returned at the vendor's expense.
    Invoices are payable per the agreed payment terms, calculated from the date of verified delivery.
    TEXT;

    public function run(): void
    {
        if (PurchaseOrderTerm::whereNull('project_id')->exists()) {
            $this->command?->info('Default purchase order terms already exist — skipping.');

            return;
        }

        PurchaseOrderTerm::create([
            'project_id' => null,
            'title' => 'Terms & Conditions',
            'body' => self::DEFAULT_BODY,
        ]);
    }
}
