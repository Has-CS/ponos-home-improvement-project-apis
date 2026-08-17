<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;

/**
 * The Normal change-order flow, end to end:
 *
 *   draft -> pending_pm -> pending_admin -> pending_document
 *         -> pending_counter_sign -> pending_gc -> active
 *
 * plus the routing rule that decides where a change order lands on submit, and
 * the send-back loop that returns it to its originator.
 */
class ChangeOrderNormalFlowTest extends ChangeOrderTestCase
{
    /* ---------------- the happy path ---------------- */

    public function test_a_foreman_raised_change_order_walks_the_full_chain(): void
    {
        $id = $this->createDraftAs($this->foreman, ['value' => 18500.00]);

        $this->assertSame('draft', $this->statusOf($id));

        $this->submitAs($this->foreman, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_pm');

        $this->validateAs($this->pm, $id, ['comments' => 'Scope and cost check out.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_admin');

        // Admin review lands on pending_document, NOT straight on the
        // counter-signature: the document does not exist yet.
        $this->approveAs($this->admin, $id, ['comments' => 'Budget verified.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_document');

        $this->prepareAs($this->pm, $id)
            ->assertOk()
            ->assertJsonPath('data.change_order.status.code', 'pending_counter_sign');

        $this->counterSignAs($this->admin, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_gc');

        $this->gcDecisionAs($this->pm, $id, ['decision' => 'approved', 'notes' => 'GC confirmed by email.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'active');

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame($this->admin->id, $co->counter_signed_by);
        $this->assertNotNull($co->counter_signed_at);
        $this->assertNotNull($co->became_active_at);
        $this->assertSame('approved', $co->gcDecision->code);
        $this->assertNotNull($co->document_attachment_id);
    }

    public function test_a_gc_rejection_ends_the_change_order(): void
    {
        $id = $this->changeOrderAt('pending_gc');

        $this->gcDecisionAs($this->pm, $id, ['decision' => 'rejected', 'notes' => 'GC declined the cost.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'gc_rejected');

        $this->assertNull(ChangeOrder::findOrFail($id)->became_active_at);
    }

    /* ---------------- routing by the originator's role ---------------- */

    public function test_a_foreman_raised_change_order_goes_to_the_pm(): void
    {
        $id = $this->createDraftAs($this->foreman);

        $this->submitAs($this->foreman, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_pm');
    }

    public function test_a_pm_raised_change_order_skips_the_pm_step(): void
    {
        // The client's addition to the diagram: a PM can log the query too, and
        // it must go to Admin — routing it to pending_pm would invite the same
        // PM to validate their own change order.
        $id = $this->createDraftAs($this->pm);

        $this->submitAs($this->pm, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_an_admin_raised_change_order_goes_to_pending_admin(): void
    {
        $id = $this->createDraftAs($this->admin);

        $this->submitAs($this->admin, $id)
            ->assertOk()
            // Deliberately not auto-approved: approval stays an explicit,
            // audited act even for an Admin's own change order.
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_an_assistant_pm_raised_change_order_still_gets_pm_review(): void
    {
        // Narrower than the material-request rule on purpose. An Assistant PM is
        // not the person who signs off scope and cost on a contractual document,
        // so their change order does NOT skip the PM.
        $id = $this->createDraftAs($this->assistantPm);

        $this->submitAs($this->assistantPm, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_pm');
    }

    public function test_routing_keys_off_the_originator_not_the_submitter(): void
    {
        // An Admin may submit a foreman's draft on their behalf; that must still
        // route to the PM rather than skipping the review the foreman needs.
        $id = $this->createDraftAs($this->foreman);

        $this->submitAs($this->admin, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_pm');
    }

    /* ---------------- send back and resubmit ---------------- */

    public function test_a_pm_can_send_a_change_order_back_for_revision(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        $this->sendBackAs($this->pm, $id, ['comments' => 'State the affected grid lines.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'sent_back');

        // The originator edits and resubmits, and the routing rule re-applies.
        $this->updateAs($this->foreman, $id, ['scope' => 'Grid lines C4 to C7.'])->assertOk();

        $this->submitAs($this->foreman, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_pm');
    }

    public function test_an_admin_send_back_returns_to_the_originator_and_re_routes(): void
    {
        $id = $this->changeOrderAt('pending_admin');

        $this->sendBackAs($this->admin, $id, ['comments' => 'Attach the surveyor note.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'sent_back');

        // Foreman-raised, so it goes back through the PM — a send-back from
        // Admin does not skip the step the originator's role calls for.
        $this->submitAs($this->foreman, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_pm');
    }

    public function test_a_pm_raised_change_order_sent_back_returns_straight_to_admin(): void
    {
        $id = $this->createDraftAs($this->pm, ['value' => 4200.00]);
        $this->submitAs($this->pm, $id)->assertOk();

        $this->sendBackAs($this->admin, $id, ['comments' => 'Break out the labour.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'sent_back');

        $this->submitAs($this->pm, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_a_reviewer_can_reject_a_change_order_outright(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        $this->rejectAs($this->pm, $id, ['comments' => 'Already covered by the base contract.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'rejected_internal')
            ->assertJsonPath('data.status.is_terminal', true);
    }

    public function test_a_change_order_can_be_cancelled_before_it_goes_active(): void
    {
        $id = $this->changeOrderAt('pending_admin');

        $this->cancelAs($this->pm, $id, ['comments' => 'Client withdrew the request.'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'cancelled');
    }
}
