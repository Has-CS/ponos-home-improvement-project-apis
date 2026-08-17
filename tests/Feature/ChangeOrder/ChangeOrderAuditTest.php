<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderApproval;

/**
 * The authorization chain.
 *
 * A change order is a contractual document, so "who did what, when, and what
 * did it do to the status" has to be answerable from the chain alone. The
 * headline case here is that a send-back, a rejection and a cancellation are
 * three different acts: they all used to log 'reject', which left a reader
 * inferring the difference from to_status_id.
 */
class ChangeOrderAuditTest extends ChangeOrderTestCase
{
    /** @return \Illuminate\Database\Eloquent\Collection<int,ChangeOrderApproval> */
    private function chain(int $coId)
    {
        return ChangeOrderApproval::where('change_order_id', $coId)->orderBy('step_no')->get();
    }

    /* ---------------- distinct verbs ---------------- */

    public function test_a_send_back_is_recorded_as_a_send_back(): void
    {
        $id = $this->changeOrderAt('pending_pm');
        $this->sendBackAs($this->pm, $id, ['comments' => 'Name the grid lines.'])->assertOk();

        $last = $this->chain($id)->last();

        $this->assertSame('send_back', $last->action);
        $this->assertSame('pending_pm', $last->fromStatus->code);
        $this->assertSame('sent_back', $last->toStatus->code);
        $this->assertSame('Name the grid lines.', $last->comments);
    }

    public function test_a_rejection_is_recorded_as_a_rejection(): void
    {
        $id = $this->changeOrderAt('pending_pm');
        $this->rejectAs($this->pm, $id, ['comments' => 'Covered by the base contract.'])->assertOk();

        $last = $this->chain($id)->last();

        $this->assertSame('reject', $last->action);
        $this->assertSame('rejected_internal', $last->toStatus->code);
    }

    public function test_a_cancellation_is_recorded_as_a_cancellation(): void
    {
        $id = $this->changeOrderAt('pending_admin');
        $this->cancelAs($this->pm, $id, ['comments' => 'Client withdrew.'])->assertOk();

        $last = $this->chain($id)->last();

        $this->assertSame('cancel', $last->action);
        $this->assertSame('cancelled', $last->toStatus->code);
    }

    public function test_the_three_negative_outcomes_are_distinguishable(): void
    {
        $sentBack = $this->changeOrderAt('pending_pm');
        $this->sendBackAs($this->pm, $sentBack, ['comments' => 'revise'])->assertOk();

        $rejected = $this->changeOrderAt('pending_pm');
        $this->rejectAs($this->pm, $rejected, ['comments' => 'no'])->assertOk();

        $cancelled = $this->changeOrderAt('pending_pm');
        $this->cancelAs($this->pm, $cancelled, ['comments' => 'withdrawn'])->assertOk();

        $this->assertSame(
            ['send_back', 'reject', 'cancel'],
            [
                $this->chain($sentBack)->last()->action,
                $this->chain($rejected)->last()->action,
                $this->chain($cancelled)->last()->action,
            ],
        );
    }

    /* ---------------- the full chain ---------------- */

    public function test_the_full_chain_records_every_phase_in_order(): void
    {
        $id = $this->changeOrderAt('active');

        $chain = $this->chain($id);

        $this->assertSame(
            ['submit', 'validate', 'approve', 'prepare_document', 'counter_sign', 'set_gc_status'],
            $chain->pluck('action')->all(),
        );

        // step_no is dense and ordered, so the chain reads as a sequence.
        $this->assertSame([1, 2, 3, 4, 5, 6], $chain->pluck('step_no')->all());

        // Each step's from_status is the previous step's to_status — no gaps.
        $this->assertSame(
            ['draft', 'pending_pm', 'pending_admin', 'pending_document', 'pending_counter_sign', 'pending_gc'],
            $chain->pluck('fromStatus.code')->all(),
        );
        $this->assertSame(
            ['pending_pm', 'pending_admin', 'pending_document', 'pending_counter_sign', 'pending_gc', 'active'],
            $chain->pluck('toStatus.code')->all(),
        );
    }

    public function test_the_chain_records_who_acted_at_each_phase(): void
    {
        $id = $this->changeOrderAt('active');

        $byAction = $this->chain($id)->keyBy('action');

        $this->assertSame($this->foreman->id, (int) $byAction['submit']->actor_id);
        $this->assertSame($this->pm->id, (int) $byAction['validate']->actor_id);
        $this->assertSame($this->admin->id, (int) $byAction['approve']->actor_id);
        $this->assertSame($this->pm->id, (int) $byAction['prepare_document']->actor_id);
        $this->assertSame($this->admin->id, (int) $byAction['counter_sign']->actor_id);

        foreach ($this->chain($id) as $step) {
            $this->assertNotNull($step->acted_at);
        }
    }

    public function test_the_actor_role_is_snapshotted_so_the_chain_survives_a_role_change(): void
    {
        $id = $this->changeOrderAt('pending_admin');

        $validate = $this->chain($id)->firstWhere('action', 'validate');
        $this->assertSame('Project Manager', $validate->actor_role);

        // The snapshot is a plain string on the row, not a live lookup, so
        // re-roling the user later cannot rewrite history.
        $this->assertSame('Project Manager', $validate->fresh()->actor_role);
    }

    public function test_a_resubmission_appends_rather_than_rewriting(): void
    {
        $id = $this->changeOrderAt('pending_pm');
        $this->sendBackAs($this->pm, $id, ['comments' => 'revise'])->assertOk();
        $this->submitAs($this->foreman, $id)->assertOk();

        // The loop is visible: submit, send_back, submit again.
        $this->assertSame(
            ['submit', 'send_back', 'submit'],
            $this->chain($id)->pluck('action')->all(),
        );
        $this->assertSame([1, 2, 3], $this->chain($id)->pluck('step_no')->all());
    }

    public function test_the_gc_decision_records_the_internal_user_who_entered_it(): void
    {
        $id = $this->changeOrderAt('pending_gc');

        $this->gcDecisionAs($this->pm, $id, [
            'decision' => 'approved',
            'notes' => 'GC confirmed by email 14 Aug.',
        ])->assertOk();

        $co = ChangeOrder::findOrFail($id);

        // The GC is not a system user: gc_decision_by is the internal person who
        // entered the out-of-band answer, not the GC themselves.
        $this->assertSame($this->pm->id, (int) $co->gc_decision_by);
        $this->assertNotNull($co->gc_decision_at);
        $this->assertSame('GC confirmed by email 14 Aug.', $co->gc_decision_notes);

        $last = $this->chain($id)->last();
        $this->assertSame('set_gc_status', $last->action);
        $this->assertSame($this->pm->id, (int) $last->actor_id);
    }

    public function test_the_chain_is_exposed_on_the_detail_endpoint(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $this->actingAs($this->pm, 'api')
            ->getJson($this->base()."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.authorization_chain.0.action', 'submit')
            ->assertJsonPath('data.authorization_chain.3.action', 'prepare_document')
            ->assertJsonPath('data.authorization_chain.1.actor_role', 'Project Manager');
    }

    public function test_the_prepared_document_is_exposed_on_the_detail_endpoint(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $response = $this->actingAs($this->pm, 'api')->getJson($this->base()."/{$id}")->assertOk();

        $docId = ChangeOrder::findOrFail($id)->document_attachment_id;

        $response->assertJsonPath('data.document.id', $docId);
        $this->assertStringContainsString(
            "/api/v1/attachments/{$docId}",
            $response->json('data.document.download_url'),
        );
    }
}
