<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestApproval;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

/**
 * PM final approval: ending a request at the PM step instead of routing it to
 * Admin, for holders of `finalize_material_request`.
 *
 * The bypass lives on its OWN endpoint rather than inside /approve, so a PM who
 * holds the right can still choose to escalate a request they judge warrants an
 * Admin decision. Both paths land in the same `approved` status.
 *
 * Setup and request helpers live in MaterialRequestLineTestCase.
 */
class MaterialRequestFinalizeTest extends MaterialRequestLineTestCase
{
    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /**
     * Grant a direct per-user permission at GLOBAL scope — the same scope
     * UserService::update() writes to and the approval routes read from.
     */
    private function grantFinalize(User $user): User
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId(0);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->givePermissionTo('finalize_material_request');
        $registrar->setPermissionsTeamId($previous);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user;
    }

    /** A submitted request sitting at pending_pm, with one line on it. */
    private function requestAtPendingPm(): int
    {
        $mrId = $this->createDraft([
            'items' => [['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 10]],
        ]);

        $this->actingAs($this->foreman, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit")
            ->assertStatus(200);

        return $mrId;
    }

    private function finalizeAs(User $user, int $mrId, array $payload = []): TestResponse
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/finalize", $payload);
    }

    private function approveAs(User $user, int $mrId): TestResponse
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/approve");
    }

    private function lastChainRow(int $mrId): MaterialRequestApproval
    {
        return MaterialRequestApproval::where('material_request_id', $mrId)
            ->orderByDesc('step_no')->firstOrFail();
    }

    /* ---------------- the bypass ---------------- */

    public function test_pm_with_the_permission_finalizes_from_pending_pm(): void
    {
        $mrId = $this->requestAtPendingPm();
        $pm = $this->grantFinalize($this->userWithRole('Project Manager'));

        $this->finalizeAs($pm, $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'approved');

        $this->assertSame('approved', MaterialRequest::findOrFail($mrId)->status->code);
    }

    public function test_the_chain_records_the_bypass_explicitly(): void
    {
        $mrId = $this->requestAtPendingPm();
        $pm = $this->grantFinalize($this->userWithRole('Project Manager'));

        $this->finalizeAs($pm, $mrId, ['comments' => 'Urgent, small value'])->assertStatus(200);

        $row = $this->lastChainRow($mrId);

        $this->assertSame('finalize', $row->action);
        $this->assertSame($pm->id, $row->approver_id);
        $this->assertSame('Project Manager', $row->approver_role);
        $this->assertSame('Urgent, small value', $row->comments);
        // pending_pm -> approved: the Admin step never happened.
        $this->assertSame('pending_pm', $row->fromStatus->code);
        $this->assertSame('approved', $row->toStatus->code);
    }

    public function test_admin_can_also_finalize(): void
    {
        $mrId = $this->requestAtPendingPm();

        // Admin holds the permission through the '*' wildcard, no direct grant.
        $this->finalizeAs($this->userWithRole('Admin'), $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'approved');
    }

    /* ---------------- the choice is preserved ---------------- */

    public function test_a_pm_holding_the_permission_can_still_escalate_to_admin(): void
    {
        // The crux of putting the bypass on its own endpoint: holding the right
        // does not force it. Calling /approve routes to Admin as always.
        $mrId = $this->requestAtPendingPm();
        $pm = $this->grantFinalize($this->userWithRole('Project Manager'));

        $this->approveAs($pm, $mrId)
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_admin');

        $this->assertSame('approve', $this->lastChainRow($mrId)->action);
    }

    /* ---------------- guards ---------------- */

    public function test_pm_without_the_permission_cannot_finalize(): void
    {
        $mrId = $this->requestAtPendingPm();

        $this->finalizeAs($this->userWithRole('Project Manager'), $mrId)->assertStatus(403);

        $this->assertSame('pending_pm', MaterialRequest::findOrFail($mrId)->status->code);
    }

    public function test_a_field_user_granted_the_permission_still_cannot_finalize(): void
    {
        // Passes the route's capability gate, then fails the step rule in the
        // service: this shortcut is only for someone who could approve here.
        $mrId = $this->requestAtPendingPm();
        $foreman = $this->grantFinalize($this->userWithRole('Foreman'));

        $this->finalizeAs($foreman, $mrId)->assertStatus(403);
    }

    public function test_finalize_is_rejected_once_the_request_is_with_admin(): void
    {
        $mrId = $this->requestAtPendingPm();
        $this->approveAs($this->userWithRole('Project Manager'), $mrId)->assertStatus(200);

        // At pending_admin an Admin's ordinary approve() already finalises.
        $this->finalizeAs($this->userWithRole('Admin'), $mrId)->assertStatus(409);
    }

    public function test_finalize_is_rejected_on_a_draft(): void
    {
        $mrId = $this->createDraft([
            'items' => [['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 1]],
        ]);

        $this->finalizeAs($this->userWithRole('Admin'), $mrId)->assertStatus(409);
    }

    public function test_finalize_is_rejected_on_an_already_approved_request(): void
    {
        $mrId = $this->requestAtPendingPm();
        $admin = $this->userWithRole('Admin');
        $this->finalizeAs($admin, $mrId)->assertStatus(200);

        $this->finalizeAs($admin, $mrId)->assertStatus(409);
    }

    /* ---------------- the existing path is untouched ---------------- */

    public function test_the_normal_two_step_chain_is_unchanged(): void
    {
        $mrId = $this->requestAtPendingPm();

        $this->approveAs($this->userWithRole('Project Manager'), $mrId)
            ->assertStatus(200)->assertJsonPath('data.status.code', 'pending_admin');
        $this->assertSame('approve', $this->lastChainRow($mrId)->action);

        $this->approveAs($this->userWithRole('Admin'), $mrId)
            ->assertStatus(200)->assertJsonPath('data.status.code', 'approved');
        $this->assertSame('approve', $this->lastChainRow($mrId)->action);
    }

    public function test_send_back_and_reject_still_work_at_pending_pm(): void
    {
        $sendBackId = $this->requestAtPendingPm();
        $this->actingAs($this->userWithRole('Project Manager'), 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$sendBackId}/send-back",
            ['comments' => 'Clarify quantities'],
        )->assertStatus(200)->assertJsonPath('data.status.code', 'sent_back_to_foreman');

        $rejectId = $this->requestAtPendingPm();
        $this->actingAs($this->userWithRole('Project Manager'), 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$rejectId}/reject",
            ['comments' => 'Not needed'],
        )->assertStatus(200)->assertJsonPath('data.status.code', 'rejected');
    }

    /* ---------------- downstream is identical ---------------- */

    public function test_a_finalized_request_can_be_purchase_ordered(): void
    {
        $mrId = $this->requestAtPendingPm();
        $this->finalizeAs($this->grantFinalize($this->userWithRole('Project Manager')), $mrId)->assertStatus(200);

        $vendor = Vendor::create(['name' => 'Acme Supply', 'is_active' => true]);
        $item = CatalogItem::factory()->create();

        $this->actingAs($this->userWithRole('Procurement'), 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mrId,
            'vendor_id' => $vendor->id,
            'items' => [['catalog_item_id' => $item->id, 'quantity_ordered' => 10, 'unit_price' => 2.50]],
        ])->assertStatus(201);

        // Nothing downstream cares HOW it reached approved — only that it did.
        $this->assertSame('ordered', MaterialRequest::findOrFail($mrId)->status->code);
    }
}
