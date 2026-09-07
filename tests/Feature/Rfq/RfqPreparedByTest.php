<?php

namespace Tests\Feature\Rfq;

use App\Models\Rfq;
use App\Models\User;

/**
 * The document has to say WHO asked for the quote and how to reach them — a
 * vendor questioning a line item needs someone to call, not just a company
 * name.
 *
 * Three fields, in this order: name, contact number, email. The number is
 * users.mobile_number, the only telephone column on that table; the email lives
 * on user_credentials. Both come from $rfq->creator, i.e. created_by — whoever
 * actually raised the RFQ, whatever their role.
 */
class RfqPreparedByTest extends RfqTestCase
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

    /** Give a user both contact details, so all three fields are printable. */
    private function withContactDetails(User $user, string $email, string $mobile): User
    {
        $user->credential()->update(['email' => $email]);
        $user->forceFill(['mobile_number' => $mobile])->save();

        return $user->refresh();
    }

    public function test_it_prints_the_authors_name_number_and_email(): void
    {
        $this->withContactDetails($this->pm, 'pat.morgan@ponos.test', '(203) 491-4431');

        $html = $this->html($this->createDraftAs($this->pm));

        $this->assertStringContainsString('Prepared by', $html);
        $this->assertStringContainsString(trim("{$this->pm->first_name} {$this->pm->last_name}"), $html);
        $this->assertStringContainsString('(203) 491-4431', $html);
        $this->assertStringContainsString('pat.morgan@ponos.test', $html);
    }

    /** Order is an explicit requirement: name, then number, then email. */
    public function test_the_three_fields_print_in_the_required_order(): void
    {
        $this->withContactDetails($this->pm, 'pat.morgan@ponos.test', '(203) 491-4431');

        $html = $this->html($this->createDraftAs($this->pm));

        // Anchor to the panel so the vendor block opposite cannot satisfy these.
        $panel = substr($html, (int) strpos($html, 'Prepared by'));

        $name = strpos($panel, trim("{$this->pm->first_name} {$this->pm->last_name}"));
        $phone = strpos($panel, '(203) 491-4431');
        $email = strpos($panel, 'pat.morgan@ponos.test');

        $this->assertNotFalse($name);
        $this->assertNotFalse($phone);
        $this->assertNotFalse($email);

        $this->assertLessThan($phone, $name, 'Name must print above the contact number.');
        $this->assertLessThan($email, $phone, 'Contact number must print above the email.');
    }

    /**
     * mobile_number is nullable and postdates most accounts, so an author
     * without one has to degrade cleanly — not print "null" or an empty line.
     */
    public function test_an_author_with_no_mobile_number_still_renders_cleanly(): void
    {
        $this->pm->credential()->update(['email' => 'pat.morgan@ponos.test']);
        $this->pm->forceFill(['mobile_number' => null])->save();

        $html = $this->html($this->createDraftAs($this->pm));

        $this->assertStringContainsString(trim("{$this->pm->first_name} {$this->pm->last_name}"), $html);
        $this->assertStringContainsString('pat.morgan@ponos.test', $html);
        $this->assertStringNotContainsString('null', strtolower(substr(
            $html,
            (int) strpos($html, 'Prepared by'),
            400,
        )));
    }

    /** The panel must follow created_by, not some other user on the system. */
    public function test_it_shows_the_actual_creator_not_another_user(): void
    {
        $this->withContactDetails($this->pm, 'author@ponos.test', '(203) 491-4431');
        $other = $this->withContactDetails($this->admin, 'someone.else@ponos.test', '(914) 555-0142');

        // Raised by the PM; the admin merely exists.
        $html = $this->html($this->createDraftAs($this->pm));

        $this->assertStringContainsString('author@ponos.test', $html);
        $this->assertStringContainsString('(203) 491-4431', $html);

        $this->assertStringNotContainsString('someone.else@ponos.test', $html);
        $this->assertStringNotContainsString('(914) 555-0142', $html);
        $this->assertStringNotContainsString(trim("{$other->first_name} {$other->last_name}"), $html);
    }

    /**
     * Removed deliberately: the RFQ box top-right already prints the same date,
     * so the panel carried it twice on one page.
     */
    public function test_the_panel_no_longer_carries_a_created_at_timestamp(): void
    {
        $this->withContactDetails($this->pm, 'pat.morgan@ponos.test', '(203) 491-4431');

        $id = $this->createDraftAs($this->pm);
        $html = $this->html($id);
        $panel = substr($html, (int) strpos($html, 'Prepared by'), 400);

        // The time-of-day format the stamp used ("07 Sep 2026 at 14:22") appears
        // nowhere else on the document, so it is a safe marker for its absence.
        $this->assertStringNotContainsString(
            Rfq::findOrFail($id)->created_at->format('H:i'),
            $panel,
        );
    }

    /** The rest of the document must be undisturbed. */
    public function test_the_surrounding_document_still_renders(): void
    {
        $this->withContactDetails($this->pm, 'pat.morgan@ponos.test', '(203) 491-4431');

        $id = $this->draftWithItem($this->pm);
        $html = $this->html($id);

        $this->assertStringContainsString($this->vendor->name, $html);                 // To — vendor panel
        $this->assertStringContainsString('PVC coupling, 6in schedule 40', $html);     // line item
        $this->assertStringContainsString('Required with your quotation', $html);      // vendor-req block
        $this->assertStringContainsString(Rfq::findOrFail($id)->rfq_no, $html);
    }

    public function test_it_survives_the_real_submit_and_download_path(): void
    {
        $this->withContactDetails($this->pm, 'buyer@ponos.test', '(203) 491-4431');

        $id = $this->draftWithItem($this->pm);
        $this->submitAs($this->pm, $id)->assertOk();

        $pdf = $this->actingAs($this->pm, 'api')->get("/api/v1/rfqs/{$id}/pdf")->getContent();

        $this->assertStringContainsString('%PDF-', $pdf);
    }
}
