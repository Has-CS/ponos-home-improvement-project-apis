<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderSignature;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

/**
 * The second half of the emergency flow: the office prices what the field captured,
 * and decides how it reaches the General Contractor.
 *
 * Capture is covered by ChangeOrderEmergencyTest. Everything here starts from a
 * change order already sitting at work_authorized, unpriced.
 *
 * The fork under test is the one the client asked for: a PM who has priced it may
 * either send it straight to the GC, or route it to an Admin first. The first of
 * those skips a control, so it is gated by a permission granted per user rather than
 * by role — the shape finalize_material_request already established.
 */
class ChangeOrderEmergencyPricingTest extends ChangeOrderTestCase
{
    /** A valid 1x1 transparent PNG. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here is asserting delivery; that is ChangeOrderEmergencyEmailTest's
        // job. Faking keeps a queue worker out of the picture entirely.
        Mail::fake();
        Storage::fake('local');
    }

    /** A captured, unpriced emergency change order. */
    private function captured(array $payload = []): int
    {
        return (int) $this->actingAs($this->foreman, 'api')
            ->postJson($this->base().'/emergency', [
                'title' => 'Unforeseen conduit conflict at grid C4',
                'scope' => 'Reframe the door opening and make good.',
                'location' => 'Level 3, Unit 312',
                'signer_name' => 'Dana Whitfield',
                'signer_title' => 'Site Superintendent',
                'signature_image' => self::PNG,
                ...$payload,
            ])->assertStatus(201)->json('data.id');
    }

    private function priced(?User $by = null, float $value = 12500.00): int
    {
        $id = $this->captured();
        $this->updateAs($by ?? $this->pm, $id, ['value' => $value])->assertOk();

        return $id;
    }

    private function forwardAs(User $u, int $id, array $p = []): TestResponse
    {
        return $this->actAs($u, $id, 'forward', $p);
    }

    private function sendToGcAs(User $u, int $id, array $p = []): TestResponse
    {
        return $this->actAs($u, $id, 'send-to-gc', $p);
    }

    private function sendBackToPricingAs(User $u, int $id, array $p): TestResponse
    {
        return $this->actAs($u, $id, 'send-back-to-pricing', $p);
    }

    private function voidAs(User $u, int $id, array $p): TestResponse
    {
        return $this->actAs($u, $id, 'void-authorization', $p);
    }

    /**
     * Grant the bypass right to one USER, at Spatie's global team scope — the scope
     * every permission check in this API resolves at. Mirrors
     * MaterialRequestFinalizeTest::grantFinalize().
     */
    private function grantSendToGc(User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $user->givePermissionTo('send_change_request_to_gc');
        $user->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /* ---------------- pricing ---------------- */

    public function test_a_pm_prices_it_and_the_chain_records_who_and_how_much(): void
    {
        $id = $this->captured();

        $this->updateAs($this->pm, $id, ['value' => 12500.00])->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('12500.00', (string) $co->value);
        $this->assertSame($this->pm->id, $co->priced_by);
        $this->assertNotNull($co->priced_at);

        // Pricing is a recordable act, not a field edit: the one thing the field user
        // is barred from and the one thing an Admin reviews.
        $price = $co->approvals->firstWhere('action', 'price');
        $this->assertNotNull($price, 'Pricing must appear in the authorization chain.');
        $this->assertSame($this->pm->id, $price->actor_id);
        $this->assertStringContainsString('12,500.00', $price->comments);

        // Not a transition — the status is unchanged either side of it.
        $this->assertSame($price->from_status_id, $price->to_status_id);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    public function test_resending_the_same_price_does_not_litter_the_chain(): void
    {
        $id = $this->priced();
        $before = ChangeOrder::findOrFail($id)->approvals->where('action', 'price')->count();

        $this->updateAs($this->pm, $id, ['value' => 12500.00])->assertOk();

        $this->assertSame(
            $before,
            ChangeOrder::findOrFail($id)->approvals->where('action', 'price')->count(),
            'Re-sending an identical figure is not a repricing.',
        );
    }

    public function test_revising_the_price_appends_rather_than_rewriting(): void
    {
        $id = $this->priced();
        $this->updateAs($this->pm, $id, ['value' => 13750.00])->assertOk();

        $prices = ChangeOrder::findOrFail($id)->approvals->where('action', 'price')->values();
        $this->assertCount(2, $prices);
        $this->assertStringContainsString('12,500.00', $prices[0]->comments);
        $this->assertStringContainsString('13,750.00', $prices[1]->comments);
    }

    public function test_pricing_does_not_add_a_price_verb_to_a_normal_change_order(): void
    {
        // The Normal flow's value moves at pending_document as part of preparing the
        // document, and its chain is asserted step-by-step in ChangeOrderAuditTest.
        // Adding a verb there would change behaviour this work must leave alone.
        $id = $this->changeOrderAt('pending_document');

        $this->updateAs($this->pm, $id, ['value' => 4000.00])->assertOk();

        $this->assertCount(
            0,
            ChangeOrder::findOrFail($id)->approvals->where('action', 'price'),
            'The Normal flow must be untouched.',
        );
    }

    /* ---------------- the fork: route to Admin ---------------- */

    public function test_a_pm_forwards_a_priced_change_order_for_review(): void
    {
        $id = $this->priced();

        $this->forwardAs($this->pm, $id)->assertOk();

        $this->assertSame('pending_admin', $this->statusOf($id));
        $this->assertSame('forward', ChangeOrder::findOrFail($id)->approvals->last()->action);
    }

    public function test_an_unpriced_change_order_cannot_be_forwarded(): void
    {
        $id = $this->captured();

        // What an Admin reviews IS the value; forwarding without one asks them to
        // approve nothing.
        $this->forwardAs($this->pm, $id)->assertStatus(422);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    public function test_an_admin_sends_what_they_have_reviewed_and_it_is_recorded_as_reviewed(): void
    {
        $id = $this->priced();
        $this->forwardAs($this->pm, $id)->assertOk();

        $this->sendToGcAs($this->admin, $id)->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('pending_gc', $co->status->code);
        $this->assertSame('admin_reviewed', $co->gc_sent_via);
        $this->assertSame($this->admin->id, $co->sent_to_gc_by);
        $this->assertNotNull($co->sent_to_gc_at);
    }

    public function test_a_pm_cannot_send_a_change_order_awaiting_admin_review(): void
    {
        $id = $this->priced();
        $this->forwardAs($this->pm, $id)->assertOk();

        // Even holding the bypass right: once it is with an Admin, it is theirs.
        $this->grantSendToGc($this->pm);

        $this->sendToGcAs($this->pm, $id)->assertStatus(403);
        $this->assertSame('pending_admin', $this->statusOf($id));
    }

    /* ---------------- the fork: straight to the GC ---------------- */

    public function test_a_pm_with_the_permission_sends_direct_and_it_is_recorded_as_a_bypass(): void
    {
        $id = $this->priced();
        $this->grantSendToGc($this->pm);

        $this->sendToGcAs($this->pm, $id)->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('pending_gc', $co->status->code);
        $this->assertSame('pm_direct', $co->gc_sent_via);
        $this->assertSame($this->pm->id, $co->sent_to_gc_by);

        $sent = $co->approvals->last();
        $this->assertSame('send_to_gc', $sent->action);
        $this->assertSame('work_authorized', $sent->fromStatus->code);
        $this->assertSame('pending_gc', $sent->toStatus->code);
    }

    public function test_a_pm_without_the_permission_cannot_send_direct(): void
    {
        $id = $this->priced();

        $this->sendToGcAs($this->pm, $id)->assertStatus(403);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    /**
     * The pairing that matters: holding the right does not force it, and lacking it
     * does not strand the change order. Mirrors
     * StandalonePurchaseOrderTest's denial/ordinary-path pair.
     */
    public function test_a_pm_without_the_permission_can_still_route_it_to_an_admin(): void
    {
        $id = $this->priced();

        $this->sendToGcAs($this->pm, $id)->assertStatus(403);
        $this->forwardAs($this->pm, $id)->assertOk();

        $this->assertSame('pending_admin', $this->statusOf($id));
    }

    public function test_a_pm_holding_the_permission_may_still_choose_to_escalate(): void
    {
        $id = $this->priced();
        $this->grantSendToGc($this->pm);

        $this->forwardAs($this->pm, $id)->assertOk();

        $this->assertSame('pending_admin', $this->statusOf($id));
    }

    public function test_an_admin_holds_the_permission_through_the_wildcard(): void
    {
        $id = $this->priced();

        // No explicit grant: RoleSeeder gives Admin ['*'].
        $this->sendToGcAs($this->admin, $id)->assertOk();

        $this->assertSame('pm_direct', ChangeOrder::findOrFail($id)->gc_sent_via);
    }

    public function test_a_field_user_cannot_send_to_the_gc_even_with_the_permission(): void
    {
        $id = $this->priced();
        $this->grantSendToGc($this->foreman);

        // The route still demands approve_change_request, which a Foreman lacks — two
        // independent gates, so widening one cannot open the other.
        $this->sendToGcAs($this->foreman, $id)->assertStatus(403);
    }

    /* ---------------- sending preconditions ---------------- */

    public function test_an_unpriced_change_order_cannot_be_sent(): void
    {
        $id = $this->captured();
        $this->grantSendToGc($this->pm);

        $this->sendToGcAs($this->pm, $id)->assertStatus(422);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    public function test_it_cannot_be_sent_without_a_general_contractor_email(): void
    {
        $this->gc->update(['email' => null]);
        $id = $this->priced();
        $this->grantSendToGc($this->pm);

        $this->sendToGcAs($this->pm, $id)->assertStatus(422);

        // Refused BEFORE the status moved, so nothing is marked sent with nothing
        // deliverable behind it.
        $this->assertSame('work_authorized', $this->statusOf($id));
        $this->assertNull(ChangeOrder::findOrFail($id)->sent_to_gc_at);
    }

    public function test_sending_files_the_first_change_order_document(): void
    {
        $id = $this->priced();
        $this->grantSendToGc($this->pm);

        $this->assertNull(ChangeOrder::findOrFail($id)->document_attachment_id);

        $this->sendToGcAs($this->pm, $id)->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertNotNull($co->document_attachment_id, 'Sending is when the document comes into existence.');
        $this->assertSame('document', $co->documentAttachment->attachment_type);
    }

    /* ---------------- the Admin sends it back ---------------- */

    public function test_an_admin_sends_it_back_to_the_pricing_desk_not_to_the_foreman(): void
    {
        $id = $this->priced();
        $this->forwardAs($this->pm, $id)->assertOk();

        $this->sendBackToPricingAs($this->admin, $id, ['comments' => 'Labour rate looks high; recheck.'])->assertOk();

        // work_authorized, NOT sent_back: sent_back is the Normal flow's state and
        // submit() accepts it, which would let an emergency change order re-enter the
        // Normal chain at pending_pm with no pricing precondition at all.
        $this->assertSame('work_authorized', $this->statusOf($id));

        $back = ChangeOrder::findOrFail($id)->approvals->last();
        $this->assertSame('send_back', $back->action);
        $this->assertSame('Labour rate looks high; recheck.', $back->comments);
    }

    public function test_a_reason_is_required_to_send_it_back(): void
    {
        $id = $this->priced();
        $this->forwardAs($this->pm, $id)->assertOk();

        $this->sendBackToPricingAs($this->admin, $id, [])->assertStatus(422);
    }

    public function test_a_pm_cannot_send_it_back_to_themselves(): void
    {
        $id = $this->priced();
        $this->forwardAs($this->pm, $id)->assertOk();

        $this->sendBackToPricingAs($this->pm, $id, ['comments' => 'Changed my mind.'])->assertStatus(403);
    }

    public function test_it_can_be_repriced_and_sent_after_a_send_back(): void
    {
        $id = $this->priced();
        $this->forwardAs($this->pm, $id)->assertOk();
        $this->sendBackToPricingAs($this->admin, $id, ['comments' => 'Too high.'])->assertOk();

        $this->updateAs($this->pm, $id, ['value' => 9800.00])->assertOk();
        $this->forwardAs($this->pm, $id)->assertOk();
        $this->sendToGcAs($this->admin, $id)->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('pending_gc', $co->status->code);
        $this->assertSame('9800.00', (string) $co->value);
    }

    public function test_an_admin_can_reject_it_outright(): void
    {
        $id = $this->priced();
        $this->forwardAs($this->pm, $id)->assertOk();

        $this->rejectAs($this->admin, $id, ['comments' => 'This was in scope all along.'])->assertOk();

        $this->assertSame('rejected_internal', $this->statusOf($id));
    }

    /* ---------------- the GC answers ---------------- */

    public function test_the_gc_approval_is_what_makes_it_active(): void
    {
        $id = $this->priced();
        $this->grantSendToGc($this->pm);
        $this->sendToGcAs($this->pm, $id)->assertOk();

        // Sending is INTERNAL approval only. Until the GC answers, gc_decision stays
        // pending — writing one at send would recreate, one step later, the defect
        // createEmergency() used to commit at capture.
        $mid = ChangeOrder::findOrFail($id);
        $this->assertSame('pending', $mid->gcDecision->code);
        $this->assertNull($mid->became_active_at);

        $this->gcDecisionAs($this->pm, $id, ['decision' => 'approved', 'notes' => 'Approved by email.'])->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('active', $co->status->code);
        $this->assertSame('approved', $co->gcDecision->code);
        $this->assertNotNull($co->became_active_at);

        // The two moments stay distinct, which is the whole point.
        $this->assertNotNull($co->work_authorized_at);
        $this->assertTrue($co->became_active_at->greaterThanOrEqualTo($co->work_authorized_at));
    }

    public function test_a_gc_rejection_of_built_work_is_recorded_as_such(): void
    {
        $id = $this->priced();
        $this->grantSendToGc($this->pm);
        $this->sendToGcAs($this->pm, $id)->assertOk();

        $this->gcDecisionAs($this->pm, $id, ['decision' => 'rejected', 'notes' => 'Disputes the rate.'])->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('gc_rejected', $co->status->code);

        // work_authorized_at survives, and that is what makes the dispute findable:
        // this is not "we did not do the work", it is "we did it and were refused".
        $this->assertNotNull($co->work_authorized_at);
        $this->assertNull($co->became_active_at);
    }

    /* ---------------- voiding a defective authorization ---------------- */

    public function test_a_pm_voids_a_defective_authorization_and_the_signature_survives(): void
    {
        $id = $this->captured();

        $this->voidAs($this->pm, $id, ['comments' => 'The signer had no authority for this trade.'])->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('cancelled', $co->status->code);

        // Marked, NOT deleted: change_order_signatures soft-deletes, so a delete would
        // hide the evidence behind a global scope — the opposite of what a disputed
        // signature needs.
        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();
        $this->assertNotNull($sig->voided_at);
        $this->assertSame('The signer had no authority for this trade.', $sig->void_reason);

        // It stays readable, and simply stops authorising anything.
        $this->assertNull($co->fresh()->currentSignature());
    }

    public function test_voiding_is_recorded_with_its_own_verb_not_as_a_cancellation(): void
    {
        $id = $this->captured();

        $this->voidAs($this->pm, $id, ['comments' => 'Wrong unit signed for.'])->assertOk();

        // Both land in `cancelled`, so the verb is the only thing that distinguishes
        // "we dropped this change" from "the paperwork was defective and was redone".
        $last = ChangeOrder::findOrFail($id)->approvals->last();
        $this->assertSame('void_authorization', $last->action);
        $this->assertSame('Wrong unit signed for.', $last->comments);
    }

    public function test_a_reason_is_required_to_void(): void
    {
        $id = $this->captured();

        $this->voidAs($this->pm, $id, [])->assertStatus(422);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    public function test_the_foreman_who_captured_it_cannot_void_it(): void
    {
        $id = $this->captured();

        // Work has been built on the strength of that signature. Erasing it is not
        // theirs to do — and cancel() would have let them, which is why voiding is a
        // separate act.
        $this->voidAs($this->foreman, $id, ['comments' => 'Changed my mind.'])->assertStatus(403);
        $this->assertSame('work_authorized', $this->statusOf($id));
    }

    public function test_it_cannot_be_voided_once_it_has_gone_to_the_gc(): void
    {
        $id = $this->priced();
        $this->grantSendToGc($this->pm);
        $this->sendToGcAs($this->pm, $id)->assertOk();

        $this->voidAs($this->pm, $id, ['comments' => 'Too late.'])->assertStatus(409);
        $this->assertSame('pending_gc', $this->statusOf($id));
    }

    /* ---------------- the Normal flow cannot use any of this ---------------- */

    public function test_a_normal_change_order_is_refused_by_every_emergency_step(): void
    {
        $id = $this->changeOrderAt('pending_admin');

        $this->forwardAs($this->pm, $id)->assertStatus(409);
        $this->sendToGcAs($this->admin, $id)->assertStatus(409);
        $this->sendBackToPricingAs($this->admin, $id, ['comments' => 'No.'])->assertStatus(409);
        $this->voidAs($this->pm, $id, ['comments' => 'No.'])->assertStatus(409);

        $this->assertSame('pending_admin', $this->statusOf($id));
    }
}
