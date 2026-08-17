<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderTerm;
use App\Models\Project;
use App\Services\ChangeOrderTerms\ChangeOrderTermsService;
use Illuminate\Support\Facades\Storage;

/**
 * Payment Terms / Changes / Acceptance on the change-order document, and the
 * reworked layout that carries them.
 *
 * Two guarantees under test:
 *  - resolution — a project's override beats the company default, in one query;
 *  - the snapshot — once a document is prepared, revising the standard text
 *    cannot alter what that change order says or what its filed PDF shows.
 */
class ChangeOrderTermsTest extends ChangeOrderTestCase
{
    private const TERMS_URL = '/api/v1/change-order-terms';

    /** @param array<string,mixed> $overrides */
    private function makeTerms(array $overrides = []): ChangeOrderTerm
    {
        return ChangeOrderTerm::create([
            'project_id' => null,
            'payment_terms_body' => "As per the approved schedule of values.\nPayable via Certified Check or ACH transfer.",
            'changes_body' => 'Changes must be agreed upon in writing by both parties.',
            'acceptance_body' => 'Signature constitutes authorisation to proceed.',
            ...$overrides,
        ]);
    }

    private function html(int $coId): string
    {
        $co = ChangeOrder::with([
            'type', 'status', 'gcDecision', 'costCode', 'urgency', 'originator',
            'counterSignedBy', 'gcDecisionBy', 'project.client', 'generalContractor',
            'signatures.capturedBy',
        ])->findOrFail($coId);

        return view('pdf.change-order', [
            'co' => $co,
            'company' => config('company'),
            'logoSrc' => null,
        ])->render();
    }

    /* ---------------- CRUD + gates ---------------- */

    public function test_an_admin_can_manage_the_default_terms(): void
    {
        $this->actingAs($this->admin, 'api')->postJson(self::TERMS_URL, [
            'payment_terms_body' => 'Net 30 from verified completion.',
            'changes_body' => 'Agreed in writing.',
        ])->assertStatus(201)->assertJsonPath('data.is_default', true);

        $this->actingAs($this->admin, 'api')->getJson(self::TERMS_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_pm_cannot_manage_terms(): void
    {
        // manage_lookups, not edit_project: this text binds the company
        // contractually, a narrower question than "who may edit this project".
        $this->actingAs($this->pm, 'api')
            ->postJson(self::TERMS_URL, ['payment_terms_body' => 'Nope.'])
            ->assertStatus(403);

        $this->actingAs($this->pm, 'api')->getJson(self::TERMS_URL)->assertStatus(403);
    }

    public function test_a_second_default_is_rejected(): void
    {
        $this->makeTerms();

        $this->actingAs($this->admin, 'api')
            ->postJson(self::TERMS_URL, ['payment_terms_body' => 'A rival default.'])
            ->assertStatus(409);
    }

    public function test_a_set_with_every_body_empty_is_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::TERMS_URL, [])
            ->assertStatus(422);
    }

    /* ---------------- resolution ---------------- */

    public function test_a_project_override_beats_the_company_default(): void
    {
        $this->makeTerms(['payment_terms_body' => 'DEFAULT TERMS']);
        $this->makeTerms(['project_id' => $this->project->id, 'payment_terms_body' => 'OVERRIDE TERMS']);

        $resolved = app(ChangeOrderTermsService::class)->resolveFor($this->project->id);

        $this->assertSame('OVERRIDE TERMS', $resolved->payment_terms_body);

        // A different project still gets the default.
        $other = Project::factory()->create();
        $this->assertSame('DEFAULT TERMS', app(ChangeOrderTermsService::class)->resolveFor($other->id)->payment_terms_body);
    }

    public function test_the_effective_endpoint_shows_what_a_change_order_would_carry(): void
    {
        $this->makeTerms(['payment_terms_body' => 'DEFAULT TERMS']);

        // Open to the PM who prepares the document, not only to Admin.
        $this->actingAs($this->pm, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/change-order-terms")
            ->assertOk()
            ->assertJsonPath('data.payment_terms_body', 'DEFAULT TERMS');
    }

    public function test_the_effective_endpoint_returns_null_when_nothing_is_configured(): void
    {
        $this->actingAs($this->pm, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/change-order-terms")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /* ---------------- the snapshot ---------------- */

    public function test_the_terms_are_frozen_when_the_document_is_prepared(): void
    {
        $terms = $this->makeTerms();
        $id = $this->changeOrderAt('pending_document');

        // Nothing frozen yet.
        $this->assertNull(ChangeOrder::findOrFail($id)->payment_terms_body);

        $this->prepareAs($this->pm, $id)->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame($terms->id, $co->terms_id);
        $this->assertSame($terms->payment_terms_body, $co->payment_terms_body);
        $this->assertSame($terms->changes_body, $co->changes_body);
        $this->assertSame($terms->acceptance_body, $co->acceptance_body);
    }

    public function test_revising_the_terms_afterwards_does_not_alter_an_issued_change_order(): void
    {
        $terms = $this->makeTerms();
        $id = $this->changeOrderAt('pending_counter_sign');   // prepared already

        $before = ChangeOrder::findOrFail($id)->termsParagraphs();

        $terms->update([
            'payment_terms_body' => 'COMPLETELY REWRITTEN TERMS',
            'changes_body' => 'REWRITTEN',
            'acceptance_body' => 'REWRITTEN',
        ]);

        // The whole point of the snapshot.
        $this->assertSame($before, ChangeOrder::findOrFail($id)->termsParagraphs());
        $this->assertStringNotContainsString('COMPLETELY REWRITTEN', $this->html($id));
    }

    public function test_deleting_the_terms_does_not_break_an_issued_change_order(): void
    {
        $terms = $this->makeTerms();
        $id = $this->changeOrderAt('pending_counter_sign');

        $this->actingAs($this->admin, 'api')
            ->deleteJson(self::TERMS_URL."/{$terms->id}")
            ->assertOk();

        $this->assertStringContainsString('Payment terms', $this->html($id));
        $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/pdf")->assertOk();
    }

    public function test_the_filed_pdf_is_unchanged_by_a_later_terms_edit(): void
    {
        $terms = $this->makeTerms();
        $id = $this->changeOrderAt('pending_counter_sign');

        $doc = \App\Models\Attachment::where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $id)->where('attachment_type', 'document')->firstOrFail();
        $filed = Storage::disk($doc->disk)->get($doc->file_path);

        $terms->update(['payment_terms_body' => 'REWRITTEN']);

        $response = $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/pdf")->assertOk();
        $this->assertSame($filed, $response->getContent());
    }

    public function test_missing_terms_never_block_preparation(): void
    {
        // Unlike the GC, which prepareDocument() refuses to proceed without.
        $this->assertSame(0, ChangeOrderTerm::count());

        $id = $this->changeOrderAt('pending_document');

        $this->prepareAs($this->pm, $id)->assertOk();
        $this->assertNull(ChangeOrder::findOrFail($id)->terms_id);
    }

    /* ---------------- the document ---------------- */

    public function test_the_three_sections_print_from_the_snapshot(): void
    {
        $this->makeTerms();
        $id = $this->changeOrderAt('pending_counter_sign');

        $html = $this->html($id);

        $this->assertStringContainsString('Payment terms', $html);
        $this->assertStringContainsString('approved schedule of values', $html);
        $this->assertStringContainsString('Changes', $html);
        $this->assertStringContainsString('agreed upon in writing', $html);
        $this->assertStringContainsString('Acceptance', $html);
        $this->assertStringContainsString('constitutes authorisation to proceed', $html);
    }

    public function test_the_sections_collapse_when_no_terms_are_configured(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');   // no terms seeded

        $html = $this->html($id);

        // No empty headings over nothing — a change order prepared before this
        // text was configured prints without them.
        $this->assertStringNotContainsString('Payment terms', $html);
        $this->assertStringNotContainsString('>Acceptance<', $html);
    }

    public function test_only_the_configured_sections_print(): void
    {
        $this->makeTerms(['changes_body' => null, 'acceptance_body' => null]);
        $id = $this->changeOrderAt('pending_counter_sign');

        $html = $this->html($id);

        $this->assertStringContainsString('Payment terms', $html);
        $this->assertStringNotContainsString('>Acceptance<', $html);
    }

    /* ---------------- the reworked layout ---------------- */

    public function test_the_addressee_block_shows_only_the_general_contractor(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $html = $this->html($id);

        // The "Issued by" panel repeated the masthead and is gone.
        $this->assertStringNotContainsString('Issued by', $html);
        $this->assertStringContainsString('To — General Contractor', $html);
        $this->assertStringContainsString(e($this->gc->name), $html);
    }

    public function test_the_body_sections_appear_in_the_agreed_order(): void
    {
        $this->makeTerms();
        $id = $this->changeOrderAt('pending_counter_sign', [
            'inclusions' => 'All framing and blocking',
            'exclusions' => 'Permit fees',
        ]);

        $html = $this->html($id);

        $positions = [];
        foreach (['Description of change', 'Total cost', '>Inclusions<', '>Exclusions<',
            'Payment terms', '>Changes<', '>Acceptance<'] as $needle) {
            $at = strpos($html, $needle);
            $this->assertNotFalse($at, "Section '{$needle}' is missing from the document.");
            $positions[] = $at;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Body sections are out of order.');
    }

    public function test_long_sections_are_allowed_to_break_across_pages(): void
    {
        // page-break-inside: avoid on a block taller than the page would overflow
        // the margin; the terms sections opt out of it.
        $this->makeTerms(['payment_terms_body' => implode("\n", array_fill(0, 60, 'A payment clause that runs on at some length to force pagination.'))]);
        $id = $this->changeOrderAt('pending_counter_sign');

        $this->assertStringContainsString('section-flow', $this->html($id));

        $bytes = app(\App\Services\ChangeOrder\ChangeOrderPdfService::class)
            ->render(ChangeOrder::findOrFail($id));

        $this->assertStringStartsWith('%PDF-', $bytes);
        // Genuinely paginated rather than clipped.
        $this->assertGreaterThan(1, substr_count($bytes, '/Type /Page'));
    }
}
