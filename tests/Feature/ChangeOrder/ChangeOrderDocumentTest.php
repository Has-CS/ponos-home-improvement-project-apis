<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\Attachment;
use App\Models\ChangeOrder;
use App\Services\ChangeOrder\ChangeOrderPdfService;
use App\Services\ChangeOrder\ChangeOrderService;
use Illuminate\Support\Facades\Storage;

/**
 * Generation of the formal change-order document.
 *
 * Two levels of assertion, matching PurchaseOrderPdfTest:
 *  - the rendered HTML, where content can be checked precisely;
 *  - the real PDF bytes and the Attachment row, which prove dompdf produces a
 *    document and that it is filed at the right two moments.
 */
class ChangeOrderDocumentTest extends ChangeOrderTestCase
{
    private function documents(int $coId, bool $withTrashed = false): \Illuminate\Support\Collection
    {
        $q = Attachment::query()
            ->where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $coId)
            ->where('attachment_type', 'document')
            ->orderBy('id');

        if ($withTrashed) {
            $q->withTrashed();
        }

        return $q->get();
    }

    private function html(int $coId): string
    {
        $co = ChangeOrder::with([
            'type', 'status', 'gcDecision', 'costCode', 'urgency', 'originator',
            'counterSignedBy', 'gcDecisionBy', 'project.client',
            'approvals.actor', 'approvals.fromStatus', 'approvals.toStatus',
            'signatures.capturedBy',
        ])->findOrFail($coId);

        return view('pdf.change-order', [
            'co' => $co,
            'company' => config('company'),
            'logoSrc' => null,
        ])->render();
    }

    /* ---------------- filing ---------------- */

    public function test_preparing_the_document_files_a_pdf(): void
    {
        $id = $this->changeOrderAt('pending_document');

        $this->assertCount(0, $this->documents($id));

        $this->prepareAs($this->pm, $id)->assertOk();

        $docs = $this->documents($id);
        $this->assertCount(1, $docs);

        $doc = $docs->first();
        $this->assertSame('application/pdf', $doc->mime_type);
        $this->assertSame($this->project->id, (int) $doc->project_id);
        $this->assertSame($this->pm->id, (int) $doc->uploaded_by);
        $this->assertStringEndsWith('.pdf', $doc->file_name);

        // The CO points at it, and the bytes are really on disk and really a PDF.
        $this->assertSame($doc->id, ChangeOrder::findOrFail($id)->document_attachment_id);
        $this->assertTrue(Storage::disk($doc->disk)->exists($doc->file_path));
        $this->assertStringStartsWith('%PDF-', Storage::disk($doc->disk)->get($doc->file_path));
    }

    public function test_counter_signing_refiles_the_document_and_supersedes_the_first(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $prepared = $this->documents($id)->first();
        $this->assertNotNull($prepared);

        $this->counterSignAs($this->admin, $id)->assertOk();

        // Exactly one live document — the counter-signed one.
        $live = $this->documents($id);
        $this->assertCount(1, $live);
        $this->assertNotSame($prepared->id, $live->first()->id);

        // The superseded copy is retained, soft-deleted, for audit.
        $this->assertCount(2, $this->documents($id, withTrashed: true));
        $this->assertSoftDeleted('attachments', ['id' => $prepared->id]);

        $this->assertSame($live->first()->id, ChangeOrder::findOrFail($id)->document_attachment_id);
    }

    public function test_the_counter_signed_document_carries_the_signature(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        // Before: the signature block has no name and no date.
        $before = $this->html($id);
        $this->assertStringContainsString('____________________', $before);

        $this->counterSignAs($this->admin, $id)->assertOk();

        $after = $this->html($id);
        $signer = e(trim("{$this->admin->first_name} {$this->admin->last_name}"));
        $this->assertStringContainsString($signer, $after);
        $this->assertStringContainsString('Pending GC', $after);
    }

    /* ---------------- the endpoint ---------------- */

    public function test_the_endpoint_returns_a_real_pdf(): void
    {
        $id = $this->changeOrderAt('draft');

        $response = $this->actingAs($this->foreman, 'api')->get($this->base()."/{$id}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_endpoint_renders_live_before_the_document_is_prepared(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        // Nothing filed yet, so this is a live render — and it must not read as
        // an authorised change.
        $this->assertCount(0, $this->documents($id));
        $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/pdf")->assertOk();

        $this->assertStringContainsString('watermark', $this->html($id));
        $this->assertStringContainsString('DRAFT', $this->html($id));
    }

    public function test_the_endpoint_serves_the_stored_copy_once_prepared(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $doc = $this->documents($id)->first();
        $stored = Storage::disk($doc->disk)->get($doc->file_path);

        $response = $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/pdf");
        $response->assertOk();

        // Byte-for-byte what was filed, not a fresh render — what the GC was
        // sent has to stay retrievable.
        $this->assertSame($stored, $response->getContent());
    }

    public function test_preview_renders_live_instead_of_serving_the_filed_copy(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $doc = $this->documents($id)->first();
        $filed = Storage::disk($doc->disk)->get($doc->file_path);

        // Without the flag: the filed bytes, as always.
        $this->assertSame(
            $filed,
            $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/pdf")->assertOk()->getContent(),
        );

        // With it: a fresh render. Same data, so the content matches, but the
        // bytes are newly produced rather than read off disk — which is what
        // makes a template change visible against an already-prepared change
        // order.
        $preview = $this->actingAs($this->pm, 'api')
            ->get($this->base()."/{$id}/pdf?preview=1")
            ->assertOk();

        $this->assertStringStartsWith('%PDF-', $preview->getContent());
        $this->assertNotSame($filed, $preview->getContent());
    }

    public function test_preview_never_replaces_the_filed_copy(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $doc = $this->documents($id)->first();
        $filed = Storage::disk($doc->disk)->get($doc->file_path);

        $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/pdf?preview=1")->assertOk();

        // An issued document must stay exactly as issued — previewing is a read.
        $this->assertCount(1, $this->documents($id));
        $this->assertSame($doc->id, $this->documents($id)->first()->id);
        $this->assertSame($filed, Storage::disk($doc->disk)->get($doc->file_path));
        $this->assertSame($doc->id, ChangeOrder::findOrFail($id)->document_attachment_id);
    }

    public function test_the_pdf_endpoint_is_membership_gated(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        $this->actingAs($this->globalOnly('Site Engineer'), 'api')
            ->get($this->base()."/{$id}/pdf")
            ->assertStatus(403);
    }

    /* ---------------- document content ---------------- */

    public function test_the_document_states_the_change_and_its_value(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign', [
            'title' => 'Relocate the second-floor stair core',
            'value' => 18500.00,
        ]);

        $html = $this->html($id);

        $this->assertStringContainsString('CHANGE ORDER', $html);
        $this->assertStringContainsString('Relocate the second-floor stair core', $html);
        // Relabelled from "Change in contract sum" when the document was reworked
        // to match the client's sheet, which heads this section "TOTAL COST".
        $this->assertStringContainsString('Total cost', $html);
        // Whole dollars with a currency symbol — the stored value keeps its
        // cents, only the printed form drops them. See the currency tests below.
        $this->assertStringContainsString('$18,500', $html);
        $this->assertStringNotContainsString('18,500.00', $html);
        // The GC, not the project's client — a client is the owner commissioning
        // the project, a GC is who Ponos contracts under.
        $this->assertStringContainsString(e($this->gc->name), $html);
        $this->assertStringContainsString('General Contractor', $html);
        $this->assertStringNotContainsString(e($this->project->client->name), $html);
    }

    public function test_the_document_does_not_print_the_internal_authorization_chain(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $html = $this->html($id);

        // Inverted deliberately. The chain used to print here; it was removed
        // when the document was reworked to match the sheet the client sends a
        // General Contractor — who approved it internally is our record, not
        // theirs. It remains on the detail endpoint and in change_order_approvals
        // (see ChangeOrderAuditTest).
        $this->assertStringNotContainsString('Internal authorization chain', $html);
        $this->assertStringNotContainsString('Document prepared', $html);
        $this->assertStringNotContainsString('Sent back for revision', $html);

        // The counter-signer still appears — on the signature block, which is
        // part of the contractual sheet rather than the internal trail.
        $this->counterSignAs($this->admin, $id)->assertOk();
        $this->assertStringContainsString(
            e(trim("{$this->admin->first_name} {$this->admin->last_name}")),
            $this->html($id),
        );
    }

    /* ---------------- the total cost figure ---------------- */

    public function test_the_total_prints_as_whole_dollars_with_separators(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign', ['value' => 1250000.00]);

        $html = $this->html($id);

        $this->assertStringContainsString('$1,250,000', $html);
        // No trailing cents on a whole amount, and no unseparated run of digits.
        $this->assertStringNotContainsString('1250000', $html);
        $this->assertStringNotContainsString('$1,250,000.00', $html);
    }

    public function test_the_stored_value_keeps_its_cents(): void
    {
        // The formatting is display-only: rounding for print must never write
        // back a rounded figure.
        $id = $this->changeOrderAt('pending_counter_sign', ['value' => 12500.50]);

        $this->assertSame('12500.50', (string) ChangeOrder::findOrFail($id)->value);

        // ...and the document rounds it for print. Worth pinning: a GC signs
        // against the printed figure, which here is a dollar above the record.
        $this->assertStringContainsString('$12,501', $this->html($id));
    }

    public function test_the_total_is_rendered_as_the_emphasised_box(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $html = $this->html($id);

        $this->assertStringContainsString('<div class="value-box">', $html);
        $this->assertStringContainsString('<span class="vb-amount">', $html);
    }

    public function test_an_unpriced_change_order_does_not_print_a_zero(): void
    {
        // Reachable through the live-render endpoint before the value is set —
        // "0.00" on a contractual sheet would be a lie.
        $id = $this->createDraftAs($this->foreman);

        $html = $this->html($id);
        $this->assertStringContainsString('To be determined', $html);
        $this->assertStringNotContainsString('>0.00<', $html);
    }

    /* ---------------- the email draft ---------------- */

    public function test_preparing_returns_an_email_draft_addressed_to_the_gc(): void
    {
        $id = $this->changeOrderAt('pending_document', ['title' => 'Relocate the stair core']);

        $response = $this->prepareAs($this->pm, $id)->assertOk();

        $co = ChangeOrder::findOrFail($id);

        $response->assertJsonPath('data.email_draft.to', $this->gc->email);
        $response->assertJsonPath('data.email_draft.to_name', $this->gc->contact_name);

        $this->assertStringContainsString($co->change_order_no, $response->json('data.email_draft.subject'));
        $this->assertStringContainsString('Relocate the stair core', $response->json('data.email_draft.subject'));

        $body = $response->json('data.email_draft.body');
        $this->assertStringContainsString($this->gc->contact_name, $body);
        $this->assertStringContainsString($co->change_order_no, $body);
        $this->assertStringContainsString('18,500.00', $body);
    }

    public function test_the_email_draft_is_not_sent(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $id = $this->changeOrderAt('pending_document');
        $this->prepareAs($this->pm, $id)->assertOk();

        // The GC is a third party who does not use this system; mailing them
        // automatically is not a decision this endpoint makes.
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_the_draft_degrades_when_the_gc_has_no_contact(): void
    {
        $this->gc->update(['contact_name' => null, 'email' => null]);

        $id = $this->changeOrderAt('pending_document');

        $draft = app(ChangeOrderService::class)->emailDraft(ChangeOrder::findOrFail($id));

        $this->assertNull($draft['to']);
        $this->assertStringContainsString('Dear Sir or Madam,', $draft['body']);
    }

    /* ---------------- service-level ---------------- */

    public function test_the_file_name_is_the_change_order_number(): void
    {
        $id = $this->changeOrderAt('draft');
        $co = ChangeOrder::findOrFail($id);

        $this->assertSame(
            $co->change_order_no.'.pdf',
            app(ChangeOrderPdfService::class)->fileName($co),
        );
    }
}
