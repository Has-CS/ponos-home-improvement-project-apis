<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Testing\TestResponse;

/**
 * `needs_structuring` is an explicit sign-off, not a line count.
 *
 * The request's prose stays "needs structuring" until a PM, Admin or (after
 * approval) Procurement says the lines cover it, and any edit that could
 * un-cover it voids that sign-off. See MaterialRequestService::markStructured().
 */
class MaterialRequestStructuringSignOffTest extends MaterialRequestLineTestCase
{
    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /** A PM staffed on the project. One per test: a project has one active PM. */
    private function staffedPm(): User
    {
        $pm = $this->userWithRole('Project Manager');
        app(RoleAssignmentService::class)->assignProjectRole($this->project, $pm, $this->role('Project Manager'), null);

        return $pm;
    }

    /** Prose plus one catalog line — the shape that used to read as structured. */
    private function mixedDraft(): int
    {
        return $this->createDraft([
            'request_text' => 'Need 20 steel nuts and whatever sealant the plumber asked for',
            'items' => [['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 20]],
        ]);
    }

    private function markAs(int $mrId, User $user): TestResponse
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/mark-structured");
    }

    private function submit(int $mrId): void
    {
        $this->actingAs($this->foreman, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit")
            ->assertStatus(200);
    }

    private function approveAs(int $mrId, User $user): void
    {
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/approve")
            ->assertStatus(200);
    }

    private function approveThrough(int $mrId): void
    {
        $this->submit($mrId);
        $this->approveAs($mrId, $this->userWithRole('Project Manager'));
        $this->approveAs($mrId, $this->userWithRole('Admin'));
    }

    private function needsStructuring(int $mrId): bool
    {
        return MaterialRequest::with('status')->findOrFail($mrId)->needsStructuring();
    }

    private function firstLineId(int $mrId): int
    {
        return (int) MaterialRequestItem::where('material_request_id', $mrId)->orderBy('id')->value('id');
    }

    /* ---------------- the flag ---------------- */

    public function test_mixed_request_needs_structuring_until_signed_off(): void
    {
        $mrId = $this->mixedDraft();
        $this->assertTrue($this->needsStructuring($mrId));

        $pm = $this->staffedPm();
        $this->markAs($mrId, $pm)
            ->assertStatus(200)
            ->assertJsonPath('data.needs_structuring', false)
            ->assertJsonPath('data.structured_by.id', $pm->id);

        $this->assertDatabaseHas('material_request_approvals', [
            'material_request_id' => $mrId,
            'approver_id' => $pm->id,
            'action' => 'edit',
            'comments' => 'Signed off: the line items cover the request text.',
        ]);
    }

    public function test_partially_structured_request_still_needs_structuring(): void
    {
        $mrId = $this->createDraft(['request_text' => 'Need 20 steel nuts, 3 bags of cement and a roll of wire']);
        $this->submit($mrId);

        $this->actingAs($this->staffedPm(), 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items",
            ['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 20],
        )->assertStatus(201);

        $this->assertTrue($this->needsStructuring($mrId));
    }

    /* ---------------- what voids a sign-off ---------------- */

    public function test_changing_the_request_text_clears_the_sign_off(): void
    {
        $mrId = $this->mixedDraft();
        $this->markAs($mrId, $this->staffedPm())->assertStatus(200);

        $this->actingAs($this->foreman, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}", ['request_text' => 'Need 40 steel nuts'])
            ->assertStatus(200)
            ->assertJsonPath('data.needs_structuring', true)
            ->assertJsonPath('data.structured_at', null);

        $this->assertDatabaseHas('material_request_approvals', [
            'material_request_id' => $mrId,
            'approver_id' => $this->foreman->id,
            'comments' => 'Structuring sign-off cleared: the request text changed.',
        ]);
    }

    public function test_resending_the_same_request_text_keeps_the_sign_off(): void
    {
        $mrId = $this->mixedDraft();
        $this->markAs($mrId, $this->staffedPm())->assertStatus(200);
        $text = MaterialRequest::findOrFail($mrId)->request_text;

        $this->actingAs($this->foreman, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}", ['request_text' => $text, 'notes' => 'Gate code 1234'])
            ->assertStatus(200)
            ->assertJsonPath('data.needs_structuring', false);
    }

    public function test_removing_a_line_clears_the_sign_off(): void
    {
        $mrId = $this->mixedDraft();
        $this->addLine($mrId, ['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 1])->assertStatus(201);
        $this->markAs($mrId, $this->staffedPm())->assertStatus(200);

        $this->actingAs($this->foreman, 'api')
            ->deleteJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items/{$this->firstLineId($mrId)}")
            ->assertStatus(200);

        $this->assertTrue($this->needsStructuring($mrId));
    }

    public function test_changing_a_line_quantity_clears_the_sign_off(): void
    {
        $mrId = $this->mixedDraft();
        $this->markAs($mrId, $this->staffedPm())->assertStatus(200);

        $this->patchLine($mrId, $this->firstLineId($mrId), ['quantity' => 2])->assertStatus(200);

        $this->assertTrue($this->needsStructuring($mrId));
    }

    public function test_bookkeeping_edits_and_new_lines_keep_the_sign_off(): void
    {
        $mrId = $this->mixedDraft();
        $this->markAs($mrId, $this->staffedPm())->assertStatus(200);

        // Same quantity (stored "20.000"), plus a cost code and a note: none of
        // these change what is being requested.
        $this->patchLine($mrId, $this->firstLineId($mrId), [
            'quantity' => 20,
            'cost_code_id' => $this->costCodeId(),
            'notes' => 'For the first floor',
        ])->assertStatus(200);

        $this->addLine($mrId, ['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 1])->assertStatus(201);

        $this->assertFalse($this->needsStructuring($mrId));
    }

    /* ---------------- who may sign off, and when ---------------- */

    public function test_sign_off_before_approval_requires_a_line(): void
    {
        $mrId = $this->createDraft(['request_text' => 'Need 20 steel nuts']);

        $this->markAs($mrId, $this->staffedPm())->assertStatus(422);
    }

    public function test_request_without_prose_cannot_be_signed_off(): void
    {
        $mrId = $this->createDraft(['items' => [['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 1]]]);

        $this->markAs($mrId, $this->userWithRole('Admin'))->assertStatus(422);
    }

    public function test_foreman_and_assistant_pm_cannot_sign_off(): void
    {
        $mrId = $this->mixedDraft();

        $this->markAs($mrId, $this->foreman)->assertStatus(403);
        $this->markAs($mrId, $this->userWithRole('Assistant Project Manager'))->assertStatus(403);
    }

    public function test_pm_not_on_the_project_cannot_sign_off(): void
    {
        $mrId = $this->mixedDraft();

        $this->markAs($mrId, $this->userWithRole('Project Manager'))->assertStatus(403);
    }

    public function test_only_an_admin_may_sign_off_while_it_awaits_admin_review(): void
    {
        $mrId = $this->mixedDraft();
        $this->submit($mrId);
        $this->approveAs($mrId, $this->userWithRole('Project Manager'));

        $this->markAs($mrId, $this->staffedPm())->assertStatus(403);
        $this->markAs($mrId, $this->userWithRole('Admin'))->assertStatus(200);
    }

    public function test_procurement_may_sign_off_only_after_approval_and_needs_no_line(): void
    {
        $procurement = $this->userWithRole('Procurement');

        $pending = $this->mixedDraft();
        $this->submit($pending);
        $this->markAs($pending, $procurement)->assertStatus(403);

        // Prose-only and approved: the mapping lives on the PO, not the MR.
        $approved = $this->createDraft(['request_text' => 'Need 20 steel nuts']);
        $this->approveThrough($approved);
        $this->markAs($approved, $procurement)->assertStatus(200);

        $this->assertSame($procurement->id, MaterialRequest::findOrFail($approved)->structured_by);
    }

    public function test_signing_off_twice_is_rejected(): void
    {
        $mrId = $this->mixedDraft();
        $this->markAs($mrId, $this->userWithRole('Admin'))->assertStatus(200);

        $this->markAs($mrId, $this->userWithRole('Admin'))->assertStatus(409);
    }

    public function test_rejected_request_reports_false_and_cannot_be_signed_off(): void
    {
        $mrId = $this->mixedDraft();
        $this->submit($mrId);

        $this->actingAs($this->userWithRole('Project Manager'), 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/reject", ['comments' => 'Not needed'])
            ->assertStatus(200)
            ->assertJsonPath('data.needs_structuring', false);

        $this->markAs($mrId, $this->userWithRole('Admin'))->assertStatus(409);
    }

    /* ---------------- the buyer queue ---------------- */

    public function test_buyer_queue_lists_a_mixed_request_until_it_is_signed_off(): void
    {
        $mrId = $this->mixedDraft();
        $this->approveThrough($mrId);
        $procurement = $this->userWithRole('Procurement');

        $this->actingAs($procurement, 'api')->getJson('/api/v1/purchase-orders/pending-requests?needs_structuring=true')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mrId)
            ->assertJsonPath('data.items.0.needs_structuring', true);

        $this->markAs($mrId, $procurement)->assertStatus(200);

        $this->actingAs($procurement, 'api')->getJson('/api/v1/purchase-orders/pending-requests?needs_structuring=true')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.items');

        $this->actingAs($procurement, 'api')->getJson('/api/v1/purchase-orders/pending-requests?needs_structuring=false')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $mrId)
            ->assertJsonPath('data.items.0.needs_structuring', false);
    }
}
