<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\Attachment;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderSignature;
use App\Models\ProjectGeneralContractor;
use App\Services\Attachment\AttachmentService;
use App\Services\ChangeOrderTerms\ChangeOrderTermsService;
use Illuminate\Support\Facades\Storage;

/**
 * The emergency change order: authorized on-site by a GC representative's
 * captured signature, with no PM->Admin->GC review chain — the signature IS
 * the authorization, so the change order goes straight to active.
 *
 * Brought to parity with the Normal flow's GC/terms/document rebuild: an
 * emergency CO now freezes the same gc_* and terms snapshot Normal only gets
 * at prepareDocument(), and files its PDF immediately rather than leaving it
 * a permanent live render.
 */
class ChangeOrderEmergencyTest extends ChangeOrderTestCase
{
    /** A valid 1x1 transparent PNG. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @param array<string,mixed> $payload */
    private function emergencyAs(\App\Models\User $user, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'api')->postJson($this->base().'/emergency', [
            'title' => 'Unforeseen conduit conflict at grid C4',
            'scope' => "Door Reframing\nReframing the Door Opening 1018\nSkim Coating",
            'location' => 'Level 3, Unit 312',
            'signer_name' => 'Dana Whitfield',
            'signer_title' => 'Site Superintendent',
            'signer_company' => 'Kellerman Construction Group',
            'signer_contact' => 'dana@kellermangc.test',
            'signature_image' => self::PNG,
            'signed_lat' => 41.7508,
            'signed_lng' => -88.1535,
            'location_note' => 'Trailer, Unit 312',
            'device_info' => ['platform' => 'iOS', 'model' => 'iPad 9th gen'],
            ...$payload,
        ]);
    }

    /* ---------------- creation ---------------- */

    public function test_a_foreman_can_raise_an_emergency_change_order(): void
    {
        $response = $this->emergencyAs($this->foreman)->assertStatus(201);

        $id = (int) $response->json('data.id');
        $co = ChangeOrder::findOrFail($id);

        $this->assertSame('active', $co->status->code);
        $this->assertSame('approved', $co->gcDecision->code);
        $this->assertSame('emergency', $co->type->code);
        $this->assertSame($this->foreman->id, $co->originator_id);
        $this->assertNotNull($co->became_active_at);
    }

    public function test_it_logs_a_single_submit_approval_and_no_review_chain(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $co = ChangeOrder::findOrFail($id);
        $this->assertCount(1, $co->approvals);
        $this->assertSame('submit', $co->approvals->first()->action);
        $this->assertNull($co->approvals->first()->from_status_id);
        $this->assertSame('active', $co->approvals->first()->toStatus->code);
    }

    public function test_the_signature_record_captures_signer_and_location(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();

        $this->assertSame('Dana Whitfield', $sig->signer_name);
        $this->assertSame('Site Superintendent', $sig->signer_title);
        $this->assertSame('Kellerman Construction Group', $sig->signer_company);
        $this->assertEqualsWithDelta(41.7508, (float) $sig->signed_lat, 0.0001);
        $this->assertEqualsWithDelta(-88.1535, (float) $sig->signed_lng, 0.0001);
        $this->assertSame('Trailer, Unit 312', $sig->location_note);
        $this->assertEquals(['platform' => 'iOS', 'model' => 'iPad 9th gen'], $sig->device_info);
        $this->assertSame($this->foreman->id, $sig->captured_by);
        $this->assertNotNull($sig->signed_at);
    }

    public function test_the_signature_image_is_stored_as_a_private_attachment(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();
        $attachment = Attachment::findOrFail($sig->signature_attachment_id);

        $this->assertSame('signature', $attachment->attachment_type);
        $this->assertSame('local', $attachment->disk);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertTrue(Storage::disk('local')->exists($attachment->file_path));
    }

    public function test_scope_is_stored_multi_line(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $this->assertSame(
            "Door Reframing\nReframing the Door Opening 1018\nSkim Coating",
            ChangeOrder::findOrFail($id)->scope,
        );
    }

    /* ---------------- GC / inclusions / exclusions ---------------- */

    public function test_it_defaults_to_the_projects_primary_general_contractor(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $this->assertSame($this->gc->id, ChangeOrder::findOrFail($id)->general_contractor_id);
    }

    public function test_it_can_name_a_specific_general_contractor(): void
    {
        $second = $this->makeGc(['name' => 'Second GC']);

        $id = (int) $this->emergencyAs($this->foreman, ['general_contractor_id' => $second->id])->json('data.id');

        $this->assertSame($second->id, ChangeOrder::findOrFail($id)->general_contractor_id);
    }

    public function test_another_projects_general_contractor_is_rejected(): void
    {
        $other = \App\Models\Project::factory()->create();
        $foreign = ProjectGeneralContractor::create([
            'project_id' => $other->id, 'name' => 'Foreign GC',
            'street_1' => '1 Elsewhere', 'city' => 'Chicago',
        ]);

        $this->emergencyAs($this->foreman, ['general_contractor_id' => $foreign->id])->assertStatus(422);
    }

    public function test_inclusions_and_exclusions_round_trip(): void
    {
        $id = (int) $this->emergencyAs($this->foreman, [
            'inclusions' => "All framing and blocking\nTemporary shoring",
            'exclusions' => 'Permit fees',
        ])->json('data.id');

        $this->actingAs($this->foreman, 'api')->getJson($this->base()."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.inclusion_list', ['All framing and blocking', 'Temporary shoring'])
            ->assertJsonPath('data.exclusion_list', ['Permit fees']);
    }

    /* ---------------- the snapshot ---------------- */

    public function test_the_gc_snapshot_is_frozen_immediately(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $co = ChangeOrder::findOrFail($id);
        $this->assertTrue($co->hasGcSnapshot());
        $this->assertSame($this->gc->name, $co->gc_name);
        $this->assertSame($this->gc->contact_name, $co->gc_contact_name);
        $this->assertSame($this->gc->email, $co->gc_email);
    }

    public function test_editing_the_gc_afterwards_does_not_alter_an_active_emergency_co(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');
        $before = ChangeOrder::findOrFail($id)->gcBlock();

        $this->gc->update(['name' => 'Renamed Contractors Inc.', 'city' => 'Peoria']);

        $after = ChangeOrder::findOrFail($id)->gcBlock();
        $this->assertSame($before, $after);
        $this->assertSame('Kellerman Construction Group', $after['name']);
    }

    public function test_the_terms_snapshot_is_frozen_when_configured(): void
    {
        app(ChangeOrderTermsService::class)->create([
            'project_id' => $this->project->id,
            'payment_terms_body' => 'Net 30 from acceptance.',
            'changes_body' => 'Changes require written agreement.',
            'acceptance_body' => 'Acceptance is binding once signed.',
        ], $this->admin->id);

        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('Net 30 from acceptance.', $co->payment_terms_body);
        $this->assertSame('Changes require written agreement.', $co->changes_body);
        $this->assertSame('Acceptance is binding once signed.', $co->acceptance_body);
    }

    /* ---------------- the filed document ---------------- */

    public function test_the_document_is_filed_immediately(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $co = ChangeOrder::findOrFail($id);
        $this->assertNotNull($co->document_attachment_id);

        $doc = Attachment::findOrFail($co->document_attachment_id);
        $this->assertSame('document', $doc->attachment_type);
        $this->assertTrue(Storage::disk($doc->disk)->exists($doc->file_path));
        $this->assertStringStartsWith('%PDF-', Storage::disk($doc->disk)->get($doc->file_path));
    }

    public function test_the_pdf_endpoint_serves_the_filed_copy_not_a_live_render(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $doc = Attachment::findOrFail(ChangeOrder::findOrFail($id)->document_attachment_id);
        $filed = Storage::disk($doc->disk)->get($doc->file_path);

        $response = $this->actingAs($this->foreman, 'api')->get($this->base()."/{$id}/pdf")->assertOk();
        $this->assertSame($filed, $response->getContent());
    }

    public function test_a_later_gc_edit_does_not_alter_the_filed_pdf(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $doc = Attachment::findOrFail(ChangeOrder::findOrFail($id)->document_attachment_id);
        $filed = Storage::disk($doc->disk)->get($doc->file_path);

        $this->gc->update(['name' => 'Renamed Contractors Inc.']);

        $response = $this->actingAs($this->foreman, 'api')->get($this->base()."/{$id}/pdf")->assertOk();
        $this->assertSame($filed, $response->getContent());
    }

    public function test_the_document_prints_the_onsite_authorization_and_no_watermark(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $html = $this->documentHtml($id);

        $this->assertStringContainsString('On-site authorization', $html);
        $this->assertStringContainsString('Dana Whitfield', $html);
        $this->assertStringContainsString('Site Superintendent', $html);
        // Authorized and active — must not read as a pending draft.
        $this->assertStringNotContainsString('<div class="watermark">', $html);
    }

    public function test_the_acceptance_panel_states_the_onsite_signer_not_the_gc_contact(): void
    {
        // A signer deliberately DIFFERENT from the fixture GC's contact name
        // ("Dana Whitfield"), which the addressee panel legitimately prints as
        // "Attn:". Without that separation neither this test nor the duplication
        // one below can tell the two apart.
        $id = (int) $this->emergencyAs($this->foreman, ['signer_name' => 'Marcus Reed'])->json('data.id');

        $html = $this->documentHtml($id);
        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();

        // The person who signed on the device IS the acceptance, so their real
        // title and the moment they signed replace the blank rules.
        $this->assertStringContainsString('Marcus Reed', $html);
        $this->assertStringContainsString('Title: Site Superintendent', $html);
        $this->assertStringContainsString('Date: '.$sig->signed_at->format('d M Y H:i'), $html);
        $this->assertStringContainsString('Kellerman Construction Group', $html);

        // Nothing is left for someone to fill in after the fact.
        $this->assertStringNotContainsString('Title: ____________________', $html);
    }

    public function test_the_capture_record_no_longer_duplicates_the_signer(): void
    {
        $id = (int) $this->emergencyAs($this->foreman, ['signer_name' => 'Marcus Reed'])->json('data.id');

        $html = $this->documentHtml($id);

        // Provenance stays in the capture box. The box is a label/value grid
        // now rather than a prose paragraph, so these assert the VALUES — the
        // labels are presentation and may be reworded again.
        $this->assertStringContainsString('Trailer, Unit 312', $html);   // location_note
        $this->assertStringContainsString('Recorded by', $html);
        // Values, not the joined string: device_info is jsonb and Postgres does
        // not preserve key order, so the two halves can print either way round.
        $this->assertStringContainsString('iPad 9th gen', $html);
        $this->assertStringContainsString('iOS', $html);

        // Coordinates are evidence and must still print — but BENEATH the
        // human-readable note, not as the headline. Asserting the order is what
        // stops a later edit quietly promoting raw GPS back to the lead line.
        $this->assertStringContainsString('GPS 41.750800, -88.153500', $html);
        $this->assertLessThan(
            strpos($html, 'GPS 41.750800'),
            strpos($html, 'Trailer, Unit 312'),
            'The readable location note must print above the raw coordinates.',
        );

        // ...while identity lives only in the signature panel, so the signer's
        // name and title each print exactly once on the sheet.
        $this->assertSame(1, substr_count($html, 'Marcus Reed'));
        $this->assertSame(1, substr_count($html, 'Site Superintendent'));
    }

    public function test_a_normal_change_order_keeps_the_blank_acceptance_block(): void
    {
        // No on-site signature, so the sheet still goes out for the GC to sign
        // by hand — inventing a signer there would be fabricated data.
        $id = $this->changeOrderAt('pending_counter_sign');

        $html = $this->documentHtml($id);

        $this->assertStringContainsString('Title: ____________________', $html);
        $this->assertStringContainsString($this->gc->contact_name, $html);
        // The MARKUP, not the bare class name — the .sig-img rule lives in the
        // stylesheet on every render, so asserting the string alone can never pass.
        $this->assertStringNotContainsString('<img class="sig-img"', $html);
    }

    public function test_the_captured_signature_image_is_embedded_on_the_signature_line(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();
        $expectedSrc = app(AttachmentService::class)->dataUri($sig->signatureAttachment);
        $this->assertNotNull($expectedSrc);

        $html = $this->documentHtml($id);
        $this->assertStringContainsString('<img class="sig-img"', $html);
        $this->assertStringContainsString($expectedSrc, $html);
    }

    /* ---------------- permissions ---------------- */

    public function test_a_role_without_create_change_request_is_forbidden(): void
    {
        $this->emergencyAs($this->globalOnly('Procurement'))->assertStatus(403);
    }

    public function test_a_holder_not_staffed_to_the_project_is_forbidden(): void
    {
        $this->emergencyAs($this->globalOnly('Foreman'))->assertStatus(403);
    }

    /* ---------------- validation ---------------- */

    public function test_signature_image_is_required(): void
    {
        $this->emergencyAs($this->foreman, ['signature_image' => null])->assertStatus(422);
    }

    public function test_signer_name_is_required(): void
    {
        $this->emergencyAs($this->foreman, ['signer_name' => null])->assertStatus(422);
    }

    public function test_scope_is_required(): void
    {
        $this->emergencyAs($this->foreman, ['scope' => null])->assertStatus(422);
    }

    public function test_location_is_required(): void
    {
        $this->emergencyAs($this->foreman, ['location' => null])->assertStatus(422);
    }

    private function documentHtml(int $coId): string
    {
        $co = ChangeOrder::with([
            'type', 'status', 'gcDecision', 'costCode', 'urgency', 'originator',
            'counterSignedBy', 'gcDecisionBy', 'project.client', 'generalContractor',
            'scopeItems.unit',
            'signatures.capturedBy', 'signatures.signatureAttachment',
        ])->findOrFail($coId);

        $attachments = app(AttachmentService::class);

        return view('pdf.change-order', [
            'co' => $co,
            'company' => config('company'),
            'logoSrc' => null,
            'signatureImages' => $co->signatures->mapWithKeys(
                fn ($sig) => [$sig->id => $sig->signatureAttachment ? $attachments->dataUri($sig->signatureAttachment) : null]
            ),
        ])->render();
    }
}
