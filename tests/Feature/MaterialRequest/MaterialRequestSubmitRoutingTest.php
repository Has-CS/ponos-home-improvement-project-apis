<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestApproval;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Testing\TestResponse;

/**
 * Where a request lands on submit depends on WHO RAISED IT.
 *
 * A PM-level requester has no PM step left to perform on their own request, so
 * it goes straight to Admin rather than to pending_pm — where they would
 * otherwise be able to approve themselves.
 *
 * The rule keys off the requester, not whoever clicks submit: an Admin may
 * submit a foreman's draft on their behalf, and that must still route to the PM.
 *
 * Setup helpers live in MaterialRequestLineTestCase.
 */
class MaterialRequestSubmitRoutingTest extends MaterialRequestLineTestCase
{
    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /** Raise a draft AS the given user (the base helper always uses the foreman). */
    private function draftRaisedBy(User $requester): int
    {
        return (int) $this->actingAs($requester, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests",
            [
                'urgency_id' => Urgency::where('code', 'normal')->value('id'),
                'items' => [['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 10]],
            ],
        )->assertStatus(201)->json('data.id');
    }

    private function submitAs(User $user, int $mrId): TestResponse
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit");
    }

    private function approveAs(User $user, int $mrId): TestResponse
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/approve");
    }

    /* ---------------- routing by the requester's role ---------------- */

    public function test_a_foreman_raised_request_still_goes_to_the_pm(): void
    {
        $mrId = $this->draftRaisedBy($this->foreman);

        $this->submitAs($this->foreman, $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_pm');
    }

    public function test_a_pm_raised_request_skips_the_pm_step(): void
    {
        // The reported bug: this used to land at pending_pm, where the same PM
        // could approve their own request.
        $pm = $this->userWithRole('Project Manager');
        $mrId = $this->draftRaisedBy($pm);

        $this->submitAs($pm, $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_an_assistant_pm_raised_request_skips_the_pm_step(): void
    {
        // Assistant PM can also approve at pending_pm, so the same hole applies.
        $assistant = $this->userWithRole('Assistant Project Manager');
        $mrId = $this->draftRaisedBy($assistant);

        $this->submitAs($assistant, $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_an_admin_raised_request_stops_at_pending_admin(): void
    {
        $admin = $this->userWithRole('Admin');
        $mrId = $this->draftRaisedBy($admin);

        $this->submitAs($admin, $mrId)
            ->assertStatus(200)
            // Deliberately NOT auto-approved: approval stays an explicit,
            // audited act even for an Admin's own request.
            ->assertJsonPath('data.status.code', 'pending_admin');

        $this->assertSame('pending_admin', MaterialRequest::findOrFail($mrId)->status->code);
    }

    /* ---------------- keyed off the requester, not the submitter ---------------- */

    public function test_an_admin_submitting_a_foremans_draft_still_routes_to_the_pm(): void
    {
        // Pins the decision: the route depends on who RAISED the request, so an
        // Admin acting on a foreman's behalf must not skip the PM step.
        $mrId = $this->draftRaisedBy($this->foreman);

        $this->submitAs($this->userWithRole('Admin'), $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_pm');
    }

    /* ---------------- the chain records the shortened path ---------------- */

    public function test_the_chain_shows_a_pm_raised_request_going_straight_to_admin(): void
    {
        $pm = $this->userWithRole('Project Manager');
        $mrId = $this->draftRaisedBy($pm);
        $this->submitAs($pm, $mrId)->assertStatus(200);

        $row = MaterialRequestApproval::where('material_request_id', $mrId)
            ->orderByDesc('step_no')->firstOrFail();

        $this->assertSame('submit', $row->action);
        $this->assertSame('draft', $row->fromStatus->code);
        $this->assertSame('pending_admin', $row->toStatus->code);
    }

    /* ---------------- self-approval guard ---------------- */

    public function test_a_requester_cannot_approve_their_own_request_at_the_pm_step(): void
    {
        // Covers requests raised BEFORE the routing fix, which are still sitting
        // at pending_pm. Simulated by moving a foreman-raised request there and
        // reassigning it to the PM who would self-approve.
        $pm = $this->userWithRole('Project Manager');
        $mrId = $this->draftRaisedBy($this->foreman);
        $this->submitAs($this->foreman, $mrId)->assertStatus(200);

        MaterialRequest::whereKey($mrId)->update(['requested_by' => $pm->id]);

        $this->approveAs($pm, $mrId)->assertStatus(403);
        $this->assertSame('pending_pm', MaterialRequest::findOrFail($mrId)->status->code);
    }

    public function test_another_pm_can_still_approve_that_same_request(): void
    {
        $owner = $this->userWithRole('Project Manager');
        $mrId = $this->draftRaisedBy($this->foreman);
        $this->submitAs($this->foreman, $mrId)->assertStatus(200);
        MaterialRequest::whereKey($mrId)->update(['requested_by' => $owner->id]);

        // Not a deadlock — a different PM clears it.
        $this->approveAs($this->userWithRole('Project Manager'), $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_an_admin_can_still_approve_a_request_they_did_not_raise(): void
    {
        $mrId = $this->draftRaisedBy($this->foreman);
        $this->submitAs($this->foreman, $mrId)->assertStatus(200);

        $this->approveAs($this->userWithRole('Admin'), $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_an_admin_may_approve_their_own_request_at_the_admin_step(): void
    {
        // The guard is scoped to pending_pm on purpose: the Admin is the terminal
        // authority, so blocking here would strand requests in a one-Admin org.
        $admin = $this->userWithRole('Admin');
        $mrId = $this->draftRaisedBy($admin);
        $this->submitAs($admin, $mrId)->assertStatus(200);

        $this->approveAs($admin, $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'approved');
    }

    /* ---------------- downstream ---------------- */

    public function test_a_pm_raised_request_completes_and_can_be_purchase_ordered(): void
    {
        $pm = $this->userWithRole('Project Manager');
        $mrId = $this->draftRaisedBy($pm);
        $this->submitAs($pm, $mrId)->assertStatus(200);

        $this->approveAs($this->userWithRole('Admin'), $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'approved');

        $vendor = Vendor::create(['name' => 'Acme Supply', 'is_active' => true]);
        $item = CatalogItem::factory()->create();

        $this->actingAs($this->userWithRole('Procurement'), 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mrId,
            'vendor_id' => $vendor->id,
            'items' => [['catalog_item_id' => $item->id, 'quantity_ordered' => 10, 'unit_price' => 3.00]],
        ])->assertStatus(201);

        $this->assertSame('ordered', MaterialRequest::findOrFail($mrId)->status->code);
    }

    public function test_a_pm_cannot_finalize_their_own_request(): void
    {
        // Falls out of the routing rather than needing a special case: the
        // request is at pending_admin, and finalize() only acts at pending_pm.
        $pm = $this->userWithRole('Project Manager');
        $mrId = $this->draftRaisedBy($pm);
        $this->submitAs($pm, $mrId)->assertStatus(200);

        $this->actingAs($pm, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/finalize")
            ->assertStatus(403); // lacks the permission; and the status would 409 anyway
    }
}
