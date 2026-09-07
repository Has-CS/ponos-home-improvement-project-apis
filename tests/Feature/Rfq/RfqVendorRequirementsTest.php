<?php

namespace Tests\Feature\Rfq;

use App\Models\Rfq;

/**
 * Every RFQ has to ask the vendor for two things the buyer cannot compare
 * quotes without: how long the goods take, and who moves them.
 *
 * They are printed PROSE, not form fields — the vendor never returns this
 * sheet, they answer by phone or email — so what is under test is simply that
 * both requests are on the document, in every state it can be rendered in.
 */
class RfqVendorRequirementsTest extends RfqTestCase
{
    /**
     * The real eager-load list, not a hand-rolled subset — a relation missing
     * here would pass in this test and still N+1 or null-error in production.
     */
    private function html(int $rfqId): string
    {
        $rfq = Rfq::findOrFail($rfqId);

        return view('pdf.rfq', [
            'rfq' => $rfq->loadMissing(['vendor', 'project', 'status', 'creator.credential', 'items.unit', 'items.catalogItem', 'items.tradeCategory']),
            'company' => config('company'),
            'logoSrc' => null,
        ])->render();
    }

    private function assertBothRequestsPresent(string $html): void
    {
        $this->assertStringContainsString('Required with your quotation', $html);

        $this->assertStringContainsString('Lead time', $html);
        $this->assertStringContainsString('working days from receipt of order', $html);

        $this->assertStringContainsString('Shipping', $html);
        $this->assertStringContainsString('whether you deliver to our', $html);
        $this->assertStringContainsString('we collect from you', $html);
    }

    public function test_a_draft_prints_both_requests(): void
    {
        $this->assertBothRequestsPresent($this->html($this->draftWithItem()));
    }

    public function test_a_sent_rfq_prints_both_requests(): void
    {
        $id = $this->draftWithItem();
        $this->submitAs($this->pm, $id)->assertOk();

        $this->assertBothRequestsPresent($this->html($id));
    }

    /**
     * The block sits next to the OPTIONAL notes box. It must not have picked up
     * that box's @if by accident.
     */
    public function test_it_prints_when_the_rfq_has_no_notes(): void
    {
        $id = $this->draftWithItem();
        $this->assertNull(Rfq::findOrFail($id)->notes);

        $this->assertBothRequestsPresent($this->html($id));
    }

    public function test_it_prints_alongside_notes_when_there_are_some(): void
    {
        $id = $this->createDraftAs($this->pm, ['notes' => 'Deliver to the rear gate.']);
        $this->addItemAs($this->pm, $id, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 5,
        ])->assertStatus(201);

        $html = $this->html($id);

        $this->assertBothRequestsPresent($html);
        $this->assertStringContainsString('Deliver to the rear gate.', $html);
    }

    /**
     * An RFQ with no lines still has to carry the two questions — the buyer may
     * print one to talk a vendor through before the list is finalised.
     */
    public function test_it_prints_on_an_rfq_with_no_line_items(): void
    {
        $this->assertBothRequestsPresent($this->html($this->createDraftAs($this->pm)));
    }

    /** The surrounding document must be undisturbed. */
    public function test_the_rest_of_the_document_still_renders(): void
    {
        $this->pm->credential()->update(['email' => 'buyer@ponos.test']);

        $id = $this->draftWithItem();
        $html = $this->html($id);

        $this->assertStringContainsString($this->vendor->name, $html);           // To — vendor panel
        $this->assertStringContainsString('Prepared by', $html);                 // author panel
        $this->assertStringContainsString('buyer@ponos.test', $html);
        $this->assertStringContainsString('PVC coupling, 6in schedule 40', $html); // line item
        $this->assertStringContainsString(Rfq::findOrFail($id)->rfq_no, $html);
    }

    public function test_it_survives_the_real_submit_and_download_path(): void
    {
        $id = $this->draftWithItem();
        $this->submitAs($this->pm, $id)->assertOk();

        $pdf = $this->actingAs($this->pm, 'api')->get("/api/v1/rfqs/{$id}/pdf")->getContent();

        $this->assertStringContainsString('%PDF-', $pdf);
    }
}
