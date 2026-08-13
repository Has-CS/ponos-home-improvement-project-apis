<?php

namespace Tests\Feature\PurchaseOrderTerms;

use App\Models\Project;
use App\Models\PurchaseOrder;

class PurchaseOrderTermsResolutionTest extends PurchaseOrderTermsTestCase
{
    public function test_a_project_override_wins_over_the_default(): void
    {
        $this->makeDefaultTerms('Company default clause.');
        $this->makeProjectTerms('Project-specific clause.');

        $poId = $this->createPurchaseOrder();

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.terms.body', 'Project-specific clause.');
    }

    public function test_a_project_without_an_override_falls_back_to_the_default(): void
    {
        $this->makeDefaultTerms('Company default clause.');
        $this->makeProjectTerms('Someone else clause.', for: Project::factory()->create());

        $poId = $this->createPurchaseOrder();

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.terms.body', 'Company default clause.');
    }

    public function test_a_po_carries_no_terms_when_none_are_configured(): void
    {
        $poId = $this->createPurchaseOrder();

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.terms', null);
    }

    /**
     * The deliberate divergence from the ship-to rule: an address is per-order
     * data and blocks issuing when missing, terms are standing company content
     * and never do.
     */
    public function test_a_po_with_no_terms_can_still_be_issued(): void
    {
        $poId = $this->createPurchaseOrder();

        $this->issuePurchaseOrder($poId)->assertOk();

        $this->showPurchaseOrder($poId)->assertJsonPath('data.terms', null);
    }

    public function test_the_effective_terms_endpoint_reports_what_a_po_would_carry(): void
    {
        $this->makeDefaultTerms('Company default clause.');
        $this->makeProjectTerms('Project-specific clause.');

        $this->actingAs($this->procurement, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/purchase-order-terms")
            ->assertOk()
            ->assertJsonPath('data.body', 'Project-specific clause.')
            ->assertJsonPath('data.is_default', false);
    }

    public function test_the_effective_terms_endpoint_returns_null_when_none_configured(): void
    {
        $this->actingAs($this->procurement, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/purchase-order-terms")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /**
     * Terms are re-resolved at issue, unlike the ship-to snapshot: an admin may
     * revise the standard terms while an order sits in draft, and the order
     * must go out under the terms in force when it was actually placed.
     */
    public function test_terms_are_re_resolved_at_issue(): void
    {
        $terms = $this->makeDefaultTerms('Original clause.');

        $poId = $this->createPurchaseOrder();
        $this->showPurchaseOrder($poId)->assertJsonPath('data.terms.body', 'Original clause.');

        $terms->update(['body' => 'Revised clause.']);

        $this->issuePurchaseOrder($poId)->assertOk();

        $this->showPurchaseOrder($poId)->assertJsonPath('data.terms.body', 'Revised clause.');
    }

    /** The core guarantee — an issued order's terms are frozen for good. */
    public function test_an_issued_po_is_immune_to_later_terms_edits_and_deletion(): void
    {
        $terms = $this->makeDefaultTerms("First clause.\nSecond clause.", 'Standard Terms');

        $poId = $this->createPurchaseOrder();
        $this->issuePurchaseOrder($poId)->assertOk();

        $terms->update(['title' => 'REWRITTEN', 'body' => 'Totally different clause.']);
        $terms->delete();

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.terms.title', 'Standard Terms')
            ->assertJsonPath('data.terms.body', "First clause.\nSecond clause.")
            ->assertJsonPath('data.terms.clauses', ['First clause.', 'Second clause.']);
    }

    public function test_a_new_project_override_does_not_disturb_an_issued_po(): void
    {
        $this->makeDefaultTerms('Company default clause.');

        $poId = $this->createPurchaseOrder();
        $this->issuePurchaseOrder($poId)->assertOk();

        // Terms introduced after the fact must not reach back.
        $this->makeProjectTerms('Late override.');

        $this->showPurchaseOrder($poId)
            ->assertJsonPath('data.terms.body', 'Company default clause.');
    }

    public function test_the_terms_snapshot_matches_the_print_demo(): void
    {
        $this->makeDefaultTerms(implode("\n", [
            'This purchase order number must appear on all invoices, delivery notes, packing slips and correspondence.',
            'Deliveries are accepted only at the Ship To address above, during site working hours, and must be accompanied by a signed delivery note or bill of lading.',
            'Quantities and prices are as stated. Substitutions, back-orders, over-supply and price changes require prior written approval from Ponos Home Improvement, Ltd.',
            'Goods are subject to inspection on receipt. Items rejected for damage, shortage or non-conformance may be returned at the vendor\'s expense.',
            'Invoices are payable per the agreed payment terms, calculated from the date of verified delivery.',
        ]), 'Terms & Conditions');

        $poId = $this->createPurchaseOrder();
        $this->issuePurchaseOrder($poId)->assertOk();

        $clauses = $this->showPurchaseOrder($poId)->assertOk()->json('data.terms.clauses');

        $this->assertCount(5, $clauses);
        $this->assertSame(
            'This purchase order number must appear on all invoices, delivery notes, packing slips and correspondence.',
            $clauses[0],
        );
        $this->assertStringStartsWith('Invoices are payable per the agreed payment terms', $clauses[4]);
    }

    public function test_the_terms_fk_is_recorded_alongside_the_snapshot(): void
    {
        $terms = $this->makeDefaultTerms('A clause.');

        $poId = $this->createPurchaseOrder();

        $this->assertSame($terms->id, (int) PurchaseOrder::find($poId)->terms_id);
    }
}
