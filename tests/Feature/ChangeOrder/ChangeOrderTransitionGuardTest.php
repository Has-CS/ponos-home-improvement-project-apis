<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;

/**
 * The guards on every transition: wrong status (409), wrong role (403), missing
 * project membership (403), and the value requirement at document preparation
 * (422).
 *
 * The two-layer authorization model is what most of this exercises. Holding
 * approve_change_request gets you to the endpoint; the service then decides
 * whether your role may act AT THIS PARTICULAR STATUS. Both layers have to be
 * tested, because either alone would let the wrong person through.
 */
class ChangeOrderTransitionGuardTest extends ChangeOrderTestCase
{
    /* ---------------- project membership ---------------- */

    public function test_a_permission_holder_outside_the_project_cannot_raise_a_change_order(): void
    {
        // Holds create_change_request globally but is not staffed onto the
        // project. Before project.access was added to the write routes this
        // succeeded, letting anyone act on any project in the system.
        $outsider = $this->globalOnly('Site Engineer');

        $this->actingAs($outsider, 'api')
            ->postJson($this->base(), ['title' => 'Unauthorised change'])
            ->assertStatus(403);
    }

    public function test_a_reviewer_outside_the_project_cannot_action_the_chain(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        // A second PM cannot be staffed onto the project (one active PM per
        // project), which makes this the natural way to hold the permission
        // without membership.
        $outsidePm = $this->globalOnly('Project Manager');

        $this->validateAs($outsidePm, $id)->assertStatus(403);
        $this->sendBackAs($outsidePm, $id, ['comments' => 'no'])->assertStatus(403);
    }

    /* ---------------- role gates per step ---------------- */

    public function test_only_a_pm_or_admin_can_validate(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        // Foreman holds create_change_request, not approve_change_request, so
        // the route middleware stops them first.
        $this->validateAs($this->foreman, $id)->assertStatus(403);

        // So does an Assistant PM, and that is worth pinning down: the SERVICE
        // gate for this step is isPmLevel(), which does include an Assistant PM
        // — but RoleSeeder never grants them approve_change_request, so they are
        // refused a layer earlier and the isPmLevel() branch is unreachable for
        // them in practice. Asserting the real behaviour rather than the
        // intended one, so that if the role matrix is ever widened this test
        // fails and the discrepancy gets a decision rather than a surprise.
        $this->validateAs($this->assistantPm, $id)->assertStatus(403);

        $this->validateAs($this->pm, $id)
            ->assertOk()
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_only_an_admin_can_approve(): void
    {
        $id = $this->changeOrderAt('pending_admin');

        $this->approveAs($this->pm, $id)->assertStatus(403);
        $this->approveAs($this->assistantPm, $id)->assertStatus(403);

        $this->approveAs($this->admin, $id)->assertOk();
    }

    public function test_an_assistant_pm_cannot_prepare_the_document(): void
    {
        $id = $this->changeOrderAt('pending_document');

        // Refused twice over, which is the intent: they lack
        // approve_change_request at the route, and the service gate is
        // isPmOrAdmin() rather than isPmLevel() because preparing the document
        // is the act that commits a figure to a contractual sheet.
        $this->prepareAs($this->assistantPm, $id)->assertStatus(403);

        $this->prepareAs($this->pm, $id)->assertOk();
    }

    public function test_only_an_admin_can_counter_sign(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $this->counterSignAs($this->pm, $id)->assertStatus(403);
        $this->counterSignAs($this->assistantPm, $id)->assertStatus(403);

        $this->counterSignAs($this->admin, $id)->assertOk();
    }

    public function test_only_the_originator_or_an_admin_can_submit(): void
    {
        $id = $this->createDraftAs($this->foreman);

        $this->submitAs($this->pm, $id)->assertStatus(403);

        $this->submitAs($this->foreman, $id)->assertOk();
    }

    /* ---------------- wrong status ---------------- */

    public function test_each_step_rejects_a_change_order_in_the_wrong_status(): void
    {
        $draft = $this->changeOrderAt('draft');

        $this->validateAs($this->pm, $draft)->assertStatus(409);
        $this->approveAs($this->admin, $draft)->assertStatus(409);
        $this->prepareAs($this->pm, $draft)->assertStatus(409);
        $this->counterSignAs($this->admin, $draft)->assertStatus(409);
        $this->gcDecisionAs($this->pm, $draft, ['decision' => 'approved'])->assertStatus(409);
    }

    public function test_a_change_order_cannot_be_counter_signed_before_its_document_exists(): void
    {
        $id = $this->changeOrderAt('pending_document');

        // The prepare step is not optional — it is what produces the thing the
        // Admin is being asked to sign.
        $this->counterSignAs($this->admin, $id)->assertStatus(409);
    }

    public function test_a_submitted_change_order_cannot_be_resubmitted(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        $this->submitAs($this->foreman, $id)->assertStatus(409);
    }

    public function test_a_terminal_change_order_cannot_be_cancelled(): void
    {
        $id = $this->changeOrderAt('active');

        $this->cancelAs($this->pm, $id)->assertStatus(409);
    }

    public function test_the_gc_decision_is_only_accepted_while_pending_gc(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $this->gcDecisionAs($this->pm, $id, ['decision' => 'approved'])->assertStatus(409);
    }

    /* ---------------- the value requirement ---------------- */

    public function test_the_document_cannot_be_prepared_without_a_value(): void
    {
        $id = $this->createDraftAs($this->foreman);   // no value
        $this->submitAs($this->foreman, $id)->assertOk();
        $this->validateAs($this->pm, $id)->assertOk();
        $this->approveAs($this->admin, $id)->assertOk();

        $this->prepareAs($this->pm, $id)->assertStatus(422);

        // Still where it was: a refused prepare must not advance the chain.
        $this->assertSame('pending_document', $this->statusOf($id));
        $this->assertNull(ChangeOrder::findOrFail($id)->document_attachment_id);

        // The PM fills it in at pending_document — the window that replaced
        // editing at pending_counter_sign — and it goes through.
        $this->updateAs($this->pm, $id, ['value' => 9750.00])->assertOk();
        $this->prepareAs($this->pm, $id)->assertOk();
    }

    /* ---------------- the edit matrix ---------------- */

    public function test_the_originator_can_edit_a_draft(): void
    {
        $id = $this->createDraftAs($this->foreman);

        $this->updateAs($this->foreman, $id, ['title' => 'Revised title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Revised title');
    }

    public function test_another_field_user_cannot_edit_someone_elses_draft(): void
    {
        $otherForeman = $this->staffedUser('Site Engineer');
        $id = $this->createDraftAs($this->foreman);

        $this->updateAs($otherForeman, $id, ['title' => 'Hijacked'])->assertStatus(403);
    }

    public function test_a_pm_can_correct_the_value_at_their_own_review_step(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        // "Validate scope & cost" means the reviewer can fix the cost, not only
        // bounce the change order back for someone else to fix.
        $this->updateAs($this->pm, $id, ['value' => 21000.00])
            ->assertOk()
            ->assertJsonPath('data.value', '21000.00');
    }

    public function test_the_originator_cannot_edit_once_it_is_under_review(): void
    {
        $id = $this->changeOrderAt('pending_pm');

        $this->updateAs($this->foreman, $id, ['value' => 1.00])->assertStatus(403);
    }

    public function test_only_an_admin_can_edit_at_the_admin_review_step(): void
    {
        $id = $this->changeOrderAt('pending_admin');

        $this->updateAs($this->pm, $id, ['value' => 5.00])->assertStatus(403);

        $this->updateAs($this->admin, $id, ['value' => 27500.00])
            ->assertOk()
            ->assertJsonPath('data.value', '27500.00');
    }

    public function test_nobody_can_edit_once_the_document_has_been_generated(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        // The reversal that matters: a filed PDF now exists quoting the value,
        // so editing the record behind it would leave the document contradicting
        // the row it describes.
        $this->updateAs($this->admin, $id, ['value' => 1.00])->assertStatus(409);
        $this->updateAs($this->pm, $id, ['value' => 1.00])->assertStatus(409);
        $this->updateAs($this->foreman, $id, ['value' => 1.00])->assertStatus(409);
    }

    public function test_an_active_change_order_cannot_be_edited(): void
    {
        $id = $this->changeOrderAt('active');

        $this->updateAs($this->admin, $id, ['title' => 'Too late'])->assertStatus(409);
    }
}
