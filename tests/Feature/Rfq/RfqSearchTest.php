<?php

namespace Tests\Feature\Rfq;

use App\Models\Rfq;
use App\Models\Vendor;
use Illuminate\Testing\TestResponse;

/**
 * The RFQ list's `search` matches the RFQ number OR the title.
 *
 * Title matching is the point: `rfqs.title` is required on every RFQ, and it is
 * what people actually remember — "Kitchen remodel", not "RFQ-000042".
 *
 * The two combined-filter tests matter most. `search` is applied before
 * vendor_id/status_id in RfqService::paginate(), and AND binds tighter than OR,
 * so if the search predicates are ever un-grouped a number match escapes the
 * other filters entirely. Both are built so the FIRST RFQ matches on number and
 * the SECOND on title — that shape is what makes them able to fail; see the
 * note on the status test.
 */
class RfqSearchTest extends RfqTestCase
{
    /** @param array<string,mixed> $query */
    private function search(array $query): TestResponse
    {
        return $this->actingAs($this->pm, 'api')
            ->getJson('/api/v1/rfqs?'.http_build_query($query));
    }

    /** @return array<int,string> the rfq_no values returned, for order-free assertions */
    private function numbersFrom(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('data.items'), 'rfq_no');
    }

    /* ---------------- title matching — the fix ---------------- */

    public function test_a_partial_title_finds_the_rfq(): void
    {
        $id = $this->createDraftAs($this->pm, ['title' => 'Kitchen remodel — plumbing fixtures']);
        $this->createDraftAs($this->pm, ['title' => 'Roof flashing replacement']);

        $found = $this->numbersFrom($this->search(['search' => 'Kitchen remodel']));

        $this->assertSame([Rfq::findOrFail($id)->rfq_no], $found);
    }

    public function test_title_matching_is_case_insensitive(): void
    {
        $id = $this->createDraftAs($this->pm, ['title' => 'Kitchen remodel — plumbing fixtures']);

        $this->assertContains(
            Rfq::findOrFail($id)->rfq_no,
            $this->numbersFrom($this->search(['search' => 'KITCHEN REMODEL'])),
        );
    }

    public function test_a_mid_word_fragment_of_the_title_matches(): void
    {
        $id = $this->createDraftAs($this->pm, ['title' => 'Roof flashing replacement']);

        $this->assertContains(
            Rfq::findOrFail($id)->rfq_no,
            $this->numbersFrom($this->search(['search' => 'flash'])),
        );
    }

    /* ---------------- number matching still works ---------------- */

    public function test_the_full_rfq_number_still_finds_it(): void
    {
        $id = $this->createDraftAs($this->pm, ['title' => 'Roof flashing replacement']);
        $number = Rfq::findOrFail($id)->rfq_no;

        $this->assertSame([$number], $this->numbersFrom($this->search(['search' => $number])));
    }

    public function test_a_partial_rfq_number_still_finds_it(): void
    {
        $id = $this->createDraftAs($this->pm, ['title' => 'Roof flashing replacement']);
        $number = Rfq::findOrFail($id)->rfq_no;

        $this->assertContains(
            $number,
            $this->numbersFrom($this->search(['search' => substr($number, -4)])),
        );
    }

    public function test_a_term_matching_nothing_returns_an_empty_list(): void
    {
        $this->createDraftAs($this->pm, ['title' => 'Kitchen remodel — plumbing fixtures']);

        $this->assertSame([], $this->numbersFrom($this->search(['search' => 'nothing matches this'])));
    }

    /* ---------------- search must not escape the other filters ---------------- */

    /**
     * The regression guard for the closure. RFQ A matches on NUMBER, RFQ B on
     * TITLE, and they sit with different vendors. Filtering to B's vendor must
     * return only B — un-grouped, A leaks through on its number match.
     *
     * Verified by mutation: removing the closure makes this fail with A present.
     */
    public function test_search_combined_with_a_vendor_filter_does_not_leak(): void
    {
        $otherVendor = Vendor::create([
            'name' => 'Northgate Trading Ltd.',
            'email' => 'sales@northgate.test',
            'is_active' => true,
        ]);

        $aId = $this->createDraftAs($this->pm, ['title' => 'Unrelated works']);
        $aNumber = Rfq::findOrFail($aId)->rfq_no;

        $bId = $this->createDraftAs($this->pm, [
            'title' => "Contains {$aNumber} in the title",
            'vendor_id' => $otherVendor->id,
        ]);

        // The term matches A by number and B by title.
        $found = $this->numbersFrom($this->search([
            'search' => $aNumber,
            'vendor_id' => $otherVendor->id,
        ]));

        $this->assertSame([Rfq::findOrFail($bId)->rfq_no], $found);
        $this->assertNotContains($aNumber, $found);
    }

    /**
     * Same guard, against the status filter — deliberately built the same way as
     * the vendor one, with the draft matching on NUMBER and the sent RFQ on
     * TITLE.
     *
     * An earlier version had BOTH matching on title only, and that version
     * passed even with the closure removed: when nothing matches on rfq_no, the
     * un-grouped `rfq_no ILIKE ? OR (title ILIKE ? AND status = ?)` still yields
     * the right row by accident. A guard that cannot fail is not a guard.
     */
    public function test_search_combined_with_a_status_filter_does_not_leak(): void
    {
        $draftId = $this->createDraftAs($this->pm, ['title' => 'Unrelated works']);
        $draftNumber = Rfq::findOrFail($draftId)->rfq_no;

        $sentId = $this->createDraftAs($this->pm, ['title' => "Contains {$draftNumber} in the title"]);
        $this->addItemAs($this->pm, $sentId, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 4,
        ])->assertStatus(201);
        $this->submitAs($this->pm, $sentId)->assertOk();

        $sent = Rfq::findOrFail($sentId);

        // The term matches the draft by number and the sent RFQ by title.
        $found = $this->numbersFrom($this->search([
            'search' => $draftNumber,
            'status_id' => $sent->rfq_status_id,
        ]));

        $this->assertSame([$sent->rfq_no], $found);
        $this->assertNotContains($draftNumber, $found);
    }
}
