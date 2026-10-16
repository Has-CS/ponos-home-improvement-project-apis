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
 * Brought to parity with the Normal flow's GC/terms/document rebuild: an emergency
 * CO freezes the same gc_* and terms snapshot Normal only gets at
 * prepareDocument().
 *
 * ---- capture is now only HALF the story ----
 *
 * An emergency change order used to be created and finished in one call: straight
 * to `active`, gc_decision = approved, done. Two things were wrong with that, and
 * both are what these tests now pin:
 *
 *  - `active` is terminal, so an emergency change order captured with no value
 *    could NEVER be priced. Its document printed "To be determined" forever.
 *  - It recorded a GC DECISION nobody had made. The representative authorised the
 *    WORK; they did not agree a price, which at that moment did not exist.
 *
 * So capture now lands at `work_authorized` with gc_decision `pending` and no
 * value, and the signature — with the narrative as signed — goes onto its own Work
 * Authorization sheet. Pricing, routing and the send to the GC are separate steps,
 * covered by their own files.
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

        $this->assertSame('work_authorized', $co->status->code);
        $this->assertSame('emergency', $co->type->code);
        $this->assertSame($this->foreman->id, $co->originator_id);

        // The signature authorises WORK. It is not a GC decision on a price that
        // does not exist yet, and recording one would be the defect this redesign
        // removes.
        $this->assertSame('pending', $co->gcDecision->code);
        $this->assertNull($co->became_active_at);
        $this->assertNull($co->gc_decision_at);

        // Server time, so the work-proceeds moment is recorded even though the
        // device supplies its own signed_at.
        $this->assertNotNull($co->work_authorized_at);

        // Unpriced, and visibly so.
        $this->assertNull($co->value);
        $this->assertTrue($co->isAwaitingPricing());
    }

    public function test_the_status_is_not_terminal_so_it_can_still_be_priced(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        // The whole point. `active` was terminal, which is what made an unpriced
        // emergency change order permanently unpriceable.
        $this->assertFalse((bool) ChangeOrder::findOrFail($id)->status->is_terminal);
    }

    public function test_a_price_is_refused_at_capture(): void
    {
        // PROHIBITED, not ignored. Dropping the key would hand the field app a 201
        // with the price silently discarded; this says so out loud.
        $this->emergencyAs($this->foreman, ['value' => 12500.00])
            ->assertStatus(422)
            ->assertJsonPath('errors.value.0', 'An emergency change order is captured without a price. A project manager prices it afterwards.');

        $this->assertDatabaseCount('change_orders', 0);
    }

    public function test_it_logs_a_single_capture_approval_and_no_review_chain(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $co = ChangeOrder::findOrFail($id);
        $this->assertCount(1, $co->approvals);

        // `capture`, not `submit`. Submitting enters a request into a review chain;
        // capturing records that work was authorised outside one. A reader of the
        // chain must be able to tell which happened without inferring it from the
        // status pair.
        $this->assertSame('capture', $co->approvals->first()->action);
        $this->assertNull($co->approvals->first()->from_status_id);
        $this->assertSame('work_authorized', $co->approvals->first()->toStatus->code);
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

    public function test_the_authorization_sheet_is_filed_immediately(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $doc = Attachment::where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $id)
            ->where('attachment_type', 'authorization')
            ->firstOrFail();

        $this->assertTrue(Storage::disk($doc->disk)->exists($doc->file_path));
        $this->assertStringStartsWith('%PDF-', Storage::disk($doc->disk)->get($doc->file_path));
    }

    /**
     * The authorization is filed under its OWN attachment type, and that is not a
     * naming preference: ChangeOrderPdfService::storeFor() soft-deletes every
     * `document`-typed attachment on the change order before filing a new one, so
     * filing the evidence as a `document` would mean the first send destroyed it.
     */
    public function test_no_change_order_document_is_filed_at_capture(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $this->assertNull(ChangeOrder::findOrFail($id)->document_attachment_id);
        $this->assertDatabaseMissing('attachments', [
            'attachable_type' => ChangeOrder::class,
            'attachable_id' => $id,
            'attachment_type' => 'document',
        ]);
    }

    /**
     * Before the change order is priced and sent there is no filed document, so the
     * endpoint renders live — and because no price exists it must NOT read as an
     * approved, priced sheet.
     */
    public function test_the_pdf_endpoint_renders_live_and_unpriced_before_it_is_sent(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $response = $this->actingAs($this->foreman, 'api')->get($this->base()."/{$id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $html = $this->documentHtml($id);
        $this->assertStringContainsString('<div class="watermark">UNPRICED</div>', $html);
        $this->assertStringContainsString('To be determined', $html);
    }

    public function test_a_later_gc_edit_does_not_alter_the_frozen_gc_block(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');
        $before = ChangeOrder::findOrFail($id)->gcBlock();

        $this->gc->update(['name' => 'Renamed Contractors Inc.', 'city' => 'Peoria']);

        // The snapshot is frozen at capture, so even a live render keeps naming the
        // counterparty whose representative actually signed.
        $this->assertSame($before, ChangeOrder::findOrFail($id)->gcBlock());
        $this->assertStringContainsString('Kellerman Construction Group', $this->documentHtml($id));
        $this->assertStringNotContainsString('Renamed Contractors Inc.', $this->documentHtml($id));
    }

    public function test_the_document_references_the_authorization_without_reprinting_it(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $html = $this->documentHtml($id);

        // A pointer, not the evidence: the full capture record and the signature
        // live on the Work Authorization sheet, which travels attached to this one.
        $this->assertStringContainsString('Authorization', $html);
        $this->assertStringContainsString('was authorised on site', $html);
        $this->assertStringContainsString('Dana Whitfield', $html);

        // The provenance detail does NOT belong on this sheet.
        $this->assertStringNotContainsString('On-site capture record', $html);
        $this->assertStringNotContainsString('GPS ', $html);
        $this->assertStringNotContainsString('iPad 9th gen', $html);
    }

    /**
     * "Draft" would be materially false here — a GC representative has signed and
     * the work may already be built. What is missing is the price.
     */
    public function test_an_unpriced_authorized_change_order_prints_unpriced_not_draft(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $html = $this->documentHtml($id);

        $this->assertStringContainsString('<div class="watermark">UNPRICED</div>', $html);
        $this->assertStringNotContainsString('>DRAFT<', $html);
    }

    /**
     * The inversion of what this test used to assert, and the single most important
     * correction in the redesign.
     *
     * The acceptance panel used to print the on-site signature as the General
     * Contractor's ACCEPTANCE of the change order. It is not. Their representative
     * authorised the WORK, on site, before anybody had priced it. This sheet states
     * a figure and asks the General Contractor to accept THAT — a second decision
     * they have not made at the moment the document is produced.
     */
    public function test_the_acceptance_panel_does_not_present_the_onsite_signer_as_accepting_the_price(): void
    {
        // A signer deliberately DIFFERENT from the fixture GC's contact name
        // ("Dana Whitfield"), which the addressee panel legitimately prints as
        // "Attn:", so the two cannot be confused.
        $id = (int) $this->emergencyAs($this->foreman, ['signer_name' => 'Marcus Reed'])->json('data.id');

        $html = $this->documentHtml($id);

        // Blank rules, exactly as on a Normal change order: the GC has still to
        // accept the amount by hand.
        $this->assertStringContainsString('Title: ____________________', $html);
        $this->assertStringContainsString('Date: ____________________', $html);
        $this->assertStringContainsString('Accepted by, for and on behalf of', $html);

        // The signer is named ONLY in the authorization reference, which says what
        // they actually authorised — never on the acceptance line.
        $this->assertStringContainsString('was authorised on site', $html);
        $this->assertSame(1, substr_count($html, 'Marcus Reed'));
    }

    public function test_the_capture_record_prints_on_the_authorization_sheet(): void
    {
        $id = (int) $this->emergencyAs($this->foreman, ['signer_name' => 'Marcus Reed'])->json('data.id');

        $html = $this->authorizationHtml($id);

        // Provenance lives here now. The box is a label/value grid, so these assert
        // the VALUES — the labels are presentation and may be reworded again.
        $this->assertStringContainsString('Trailer, Unit 312', $html);   // location_note
        $this->assertStringContainsString('Recorded by', $html);
        // Values, not the joined string: device_info is jsonb and Postgres does not
        // preserve key order, so the two halves can print either way round.
        $this->assertStringContainsString('iPad 9th gen', $html);
        $this->assertStringContainsString('iOS', $html);

        // Coordinates are evidence and must still print — but BENEATH the
        // human-readable note, not as the headline.
        $this->assertStringContainsString('GPS 41.750800, -88.153500', $html);
        $this->assertLessThan(
            strpos($html, 'GPS 41.750800'),
            strpos($html, 'Trailer, Unit 312'),
            'The readable location note must print above the raw coordinates.',
        );

        // The signer and their title are the acceptance half and print in the
        // signature panel, so each appears once beyond the reference strip.
        $this->assertStringContainsString('Marcus Reed', $html);
        $this->assertStringContainsString('Site Superintendent', $html);
    }

    /** The sheet that carries no price must say so, or it reads as a bill. */
    public function test_the_authorization_sheet_states_that_it_agrees_no_price(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $html = $this->authorizationHtml($id);

        $this->assertStringContainsString('No price is stated or agreed by this authorization', $html);
        $this->assertStringContainsString('WORK AUTHORIZATION', $html);
        // No money anywhere on it, not even a placeholder.
        $this->assertStringNotContainsString('Total cost', $html);
        $this->assertStringNotContainsString('To be determined', $html);
    }

    public function test_a_normal_change_order_keeps_the_blank_acceptance_block(): void
    {
        // Unchanged by the redesign, and the canary for it: the Normal flow's sheet
        // looked exactly like this before and must look exactly like this after.
        $id = $this->changeOrderAt('pending_counter_sign');

        $html = $this->documentHtml($id);

        $this->assertStringContainsString('Title: ____________________', $html);
        $this->assertStringContainsString($this->gc->contact_name, $html);
        // The MARKUP, not the bare class name — the .sig-img rule lives in the
        // stylesheet on every render, so asserting the string alone can never pass.
        $this->assertStringNotContainsString('<img class="sig-img"', $html);
    }

    public function test_the_captured_signature_image_is_embedded_on_the_authorization_sheet(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();
        $expectedSrc = app(AttachmentService::class)->dataUri($sig->signatureAttachment);
        $this->assertNotNull($expectedSrc);

        $html = $this->authorizationHtml($id);
        $this->assertStringContainsString('<img class="sig-img"', $html);
        $this->assertStringContainsString($expectedSrc, $html);
    }

    public function test_the_change_order_document_does_not_embed_the_signature(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        // The MARKUP, not the bare class name — the .sig-img rule lives in the
        // stylesheet on every render, so asserting the string alone can never pass.
        $this->assertStringNotContainsString('<img class="sig-img"', $this->documentHtml($id));
    }

    /* ---------------- the signed snapshot ---------------- */

    public function test_the_narrative_as_signed_is_frozen_at_capture(): void
    {
        $id = (int) $this->emergencyAs($this->foreman, [
            'inclusions' => 'All framing and blocking',
            'exclusions' => 'Permit fees',
        ])->json('data.id');

        $co = ChangeOrder::findOrFail($id);

        $this->assertSame($co->title, $co->signed_title);
        $this->assertSame($co->scope, $co->signed_scope);
        $this->assertSame($co->location, $co->signed_location);
        $this->assertSame('All framing and blocking', $co->signed_inclusions);
        $this->assertSame('Permit fees', $co->signed_exclusions);
        $this->assertFalse($co->signedNarrativeDiverges());
    }

    public function test_editing_the_change_order_leaves_the_signed_narrative_untouched(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');
        $signedScope = ChangeOrder::findOrFail($id)->signed_scope;

        $this->updateAs($this->pm, $id, [
            'scope' => 'Rewritten by the project manager while pricing.',
        ])->assertOk();

        $co = ChangeOrder::findOrFail($id);

        // The working copy moved; the evidence did not.
        $this->assertSame('Rewritten by the project manager while pricing.', $co->scope);
        $this->assertSame($signedScope, $co->signed_scope);

        // And the divergence is VISIBLE rather than forbidden — a PM legitimately
        // tidies wording, but a General Contractor must be able to see that the
        // billed scope is no longer word-for-word the authorised one.
        $this->assertTrue($co->signedNarrativeDiverges());
    }

    public function test_the_signed_narrative_is_exposed_on_the_detail_endpoint(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $this->actingAs($this->foreman, 'api')->getJson($this->base()."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.signed.location', 'Level 3, Unit 312')
            ->assertJsonPath('data.signed_narrative_diverges', false)
            ->assertJsonPath('data.awaiting_pricing', true);
    }

    public function test_a_normal_change_order_has_no_signed_narrative(): void
    {
        $id = $this->changeOrderAt('draft');

        $co = ChangeOrder::findOrFail($id);
        $this->assertNull($co->signed_title);
        $this->assertNull($co->signedNarrativeDiverges());
        $this->assertNull($co->signedBlock());
    }

    /* ---------------- the Normal chain refuses an emergency change order ---------------- */

    public function test_it_cannot_be_submitted_into_the_normal_review_chain(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $this->submitAs($this->foreman, $id)->assertStatus(409);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    public function test_it_cannot_be_validated_approved_or_counter_signed(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $this->validateAs($this->pm, $id)->assertStatus(409);
        $this->prepareAs($this->pm, $id)->assertStatus(409);
        $this->counterSignAs($this->admin, $id)->assertStatus(409);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    /**
     * cancel() explicitly permits the ORIGINATOR, which on this path is the very
     * foreman who took the GC representative's signature. Work has been built
     * against that signature, so erasing it is not theirs to do.
     */
    public function test_it_cannot_be_cancelled_by_anyone(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        // The foreman never even reaches the service: cancel sits behind
        // approve_change_request, which they do not hold. Two different refusals,
        // asserted separately so a later widening of that permission cannot quietly
        // hand them the ability to erase an authorization.
        $this->cancelAs($this->foreman, $id)->assertStatus(403);

        // PM and Admin DO hold it, and are refused by the service instead.
        $this->cancelAs($this->pm, $id)->assertStatus(409);
        $this->cancelAs($this->admin, $id)->assertStatus(409);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    /* ---------------- editing while it awaits pricing ---------------- */

    public function test_a_pm_may_edit_and_price_it(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        $this->updateAs($this->pm, $id, ['value' => 12500.00])->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('12500.00', (string) $co->value);
        $this->assertFalse($co->isAwaitingPricing());
    }

    public function test_the_foreman_who_captured_it_cannot_price_it(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');

        // Their job ended when the GC representative signed. Pricing is the project
        // manager's, which is the whole premise of splitting capture from pricing.
        $this->updateAs($this->foreman, $id, ['value' => 999.00])->assertStatus(403);
        $this->assertNull(ChangeOrder::findOrFail($id)->value);
    }

    /**
     * The gc_* snapshot is frozen at capture and is what the document prints and
     * the outbound email is addressed to. Re-pointing the FK afterwards would leave
     * the old GC printed, the old GC emailed and the new id stored.
     */
    public function test_the_general_contractor_cannot_be_changed_after_capture(): void
    {
        $id = (int) $this->emergencyAs($this->foreman)->json('data.id');
        $second = $this->makeGc(['name' => 'Second GC']);

        $this->updateAs($this->pm, $id, ['general_contractor_id' => $second->id])->assertStatus(422);
        $this->assertSame($this->gc->id, ChangeOrder::findOrFail($id)->general_contractor_id);
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

        return view('pdf.change-order', [
            'co' => $co,
            'company' => config('company'),
            'logoSrc' => null,
        ])->render();
    }

    /** The Work Authorization sheet's HTML, assembled the way its service does. */
    private function authorizationHtml(int $coId): string
    {
        $co = ChangeOrder::with([
            'costCode', 'urgency', 'originator', 'project', 'generalContractor',
            'signatures.capturedBy', 'signatures.signatureAttachment',
        ])->findOrFail($coId);

        $signature = $co->currentSignature();

        return view('pdf.change-order-authorization', [
            'co' => $co,
            'signature' => $signature,
            'company' => config('company'),
            'logoSrc' => null,
            'signatureSrc' => $signature?->signatureAttachment
                ? app(AttachmentService::class)->dataUri($signature->signatureAttachment)
                : null,
        ])->render();
    }
}
