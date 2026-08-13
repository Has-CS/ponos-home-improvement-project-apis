<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\Activity;
use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\Urgency;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Testing\TestResponse;

/**
 * A reviewing PM or Admin may correct a request in place rather than only
 * approving, rejecting, or bouncing it back to a foreman who often cannot do
 * the structuring anyway.
 *
 * Two rules under test:
 *   - WHO may edit at which status (Assistant PM may approve but not edit)
 *   - every change after the request leaves draft leaves an audit row
 *
 * Setup and request helpers live in MaterialRequestLineTestCase.
 */
class MaterialRequestReviewerEditTest extends MaterialRequestLineTestCase
{
    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /** A submitted request sitting at pending_pm, with one line already on it. */
    private function requestUnderReview(): array
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft([
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 10]],
        ]);
        $lineId = (int) MaterialRequestItem::where('material_request_id', $mrId)->value('id');

        $this->actingAs($this->foreman, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit")
            ->assertStatus(200);

        return [$mrId, $lineId];
    }

    /** @param array<string,mixed> $payload */
    private function patchLineAs(User $user, int $mrId, int $lineId, array $payload): TestResponse
    {
        return $this->actingAs($user, 'api')->patchJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items/{$lineId}",
            $payload,
        );
    }

    /** @param array<string,mixed> $line */
    private function addLineAs(User $user, int $mrId, array $line): TestResponse
    {
        return $this->actingAs($user, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items",
            $line,
        );
    }

    private function approveAs(User $user, int $mrId): TestResponse
    {
        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/approve");
    }

    /* ---------------- who may edit, and when ---------------- */

    public function test_pm_may_edit_a_request_awaiting_their_review(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();

        $this->patchLineAs($this->userWithRole('Project Manager'), $mrId, $lineId, ['quantity' => 25])
            ->assertStatus(200)
            ->assertJsonPath('data.quantity', '25.000');
    }

    public function test_assistant_pm_may_not_edit_a_request_awaiting_review(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();

        $this->patchLineAs($this->userWithRole('Assistant Project Manager'), $mrId, $lineId, ['quantity' => 25])
            ->assertStatus(403);

        $this->assertSame('10.000', MaterialRequestItem::findOrFail($lineId)->quantity);
    }

    public function test_assistant_pm_may_still_approve_what_they_cannot_edit(): void
    {
        // The split is deliberate: approval rights are unchanged, only the
        // ability to rewrite another user's lines was withdrawn.
        [$mrId, $lineId] = $this->requestUnderReview();
        $assistant = $this->userWithRole('Assistant Project Manager');

        $this->patchLineAs($assistant, $mrId, $lineId, ['quantity' => 25])->assertStatus(403);
        $this->approveAs($assistant, $mrId)->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_admin');
    }

    public function test_admin_may_edit_at_pending_admin_but_pm_may_not(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();
        $this->approveAs($this->userWithRole('Project Manager'), $mrId)->assertStatus(200);

        $this->patchLineAs($this->userWithRole('Project Manager'), $mrId, $lineId, ['quantity' => 30])
            ->assertStatus(403);

        $this->patchLineAs($this->userWithRole('Admin'), $mrId, $lineId, ['quantity' => 30])
            ->assertStatus(200);
    }

    public function test_requester_may_not_edit_once_under_review(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();

        $this->patchLineAs($this->foreman, $mrId, $lineId, ['quantity' => 99])->assertStatus(403);
    }

    public function test_nobody_may_edit_once_the_request_is_approved(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();
        $this->approveAs($this->userWithRole('Project Manager'), $mrId)->assertStatus(200);
        $this->approveAs($this->userWithRole('Admin'), $mrId)->assertStatus(200);

        $this->patchLineAs($this->userWithRole('Admin'), $mrId, $lineId, ['quantity' => 30])
            ->assertStatus(409);
    }

    /* ---------------- the request does not move ---------------- */

    public function test_a_reviewer_edit_leaves_the_status_and_approval_chain_untouched(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();
        $approvalsBefore = MaterialRequest::findOrFail($mrId)->approvals()->count();

        $this->patchLineAs($this->userWithRole('Project Manager'), $mrId, $lineId, ['quantity' => 25])
            ->assertStatus(200);

        $mr = MaterialRequest::findOrFail($mrId);
        $this->assertSame('pending_pm', $mr->status->code);
        $this->assertSame($approvalsBefore, $mr->approvals()->count());
    }

    /* ---------------- audit trail ---------------- */

    public function test_an_update_logs_only_the_changed_keys_with_old_and_new(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();
        $pm = $this->userWithRole('Project Manager');

        $this->patchLineAs($pm, $mrId, $lineId, ['quantity' => 25, 'notes' => 'Bumped for wastage'])
            ->assertStatus(200);

        $activity = Activity::where('event', 'material_request_item.updated')->latest('id')->firstOrFail();

        $this->assertSame(MaterialRequest::class, $activity->subject_type);
        $this->assertSame($mrId, (int) $activity->subject_id);
        $this->assertSame($pm->id, $activity->causer_id);
        $this->assertSame($this->project->id, (int) $activity->project_id);
        $this->assertSame($lineId, $activity->properties['item_id']);

        $this->assertSame('10.000', $activity->properties['old']['quantity']);
        $this->assertSame('25.000', (string) $activity->properties['new']['quantity']);
        $this->assertSame('Bumped for wastage', $activity->properties['new']['notes']);

        // Untouched columns must not appear in the diff at all.
        $this->assertArrayNotHasKey('catalog_item_id', $activity->properties['new']);
        $this->assertArrayNotHasKey('updated_at', $activity->properties['new']);
    }

    public function test_adding_a_line_under_review_is_logged(): void
    {
        [$mrId] = $this->requestUnderReview();
        $pm = $this->userWithRole('Project Manager');
        $extra = CatalogItem::factory()->create();

        $newLineId = (int) $this->addLineAs($pm, $mrId, ['catalog_item_id' => $extra->id, 'quantity' => 4])
            ->assertStatus(201)->json('data.id');

        $activity = Activity::where('event', 'material_request_item.created')->latest('id')->firstOrFail();

        $this->assertSame($pm->id, $activity->causer_id);
        $this->assertSame($newLineId, $activity->properties['item_id']);
        $this->assertSame($extra->id, (int) $activity->properties['new']['catalog_item_id']);
        $this->assertArrayNotHasKey('old', $activity->properties);
    }

    public function test_removing_a_line_under_review_is_logged_with_its_old_values(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();
        $pm = $this->userWithRole('Project Manager');

        $this->actingAs($pm, 'api')
            ->deleteJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items/{$lineId}")
            ->assertStatus(200);

        $activity = Activity::where('event', 'material_request_item.deleted')->latest('id')->firstOrFail();

        $this->assertSame($pm->id, $activity->causer_id);
        $this->assertSame($lineId, $activity->properties['item_id']);
        $this->assertSame('10.000', $activity->properties['old']['quantity']);
    }

    public function test_draft_edits_are_not_logged(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft(['items' => [['catalog_item_id' => $item->id, 'quantity' => 10]]]);
        $lineId = (int) MaterialRequestItem::where('material_request_id', $mrId)->value('id');

        // The author's own scratchpad — never submitted, so nothing to audit.
        $this->patchLineAs($this->foreman, $mrId, $lineId, ['quantity' => 12])->assertStatus(200);
        $this->addLineAs($this->foreman, $mrId, ['catalog_item_id' => $item->id, 'quantity' => 3])->assertStatus(201);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_edits_during_a_send_back_window_are_logged(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();

        $this->actingAs($this->userWithRole('Project Manager'), 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/send-back",
            ['comments' => 'Quantities look wrong'],
        )->assertStatus(200);

        // Back with the foreman, but the request has left draft — still audited.
        $this->patchLineAs($this->foreman, $mrId, $lineId, ['quantity' => 15])->assertStatus(200);

        $activity = Activity::where('event', 'material_request_item.updated')->latest('id')->firstOrFail();
        $this->assertSame($this->foreman->id, $activity->causer_id);
        $this->assertSame('15.000', (string) $activity->properties['new']['quantity']);
    }

    public function test_a_no_op_patch_writes_no_audit_row(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();

        $this->patchLineAs($this->userWithRole('Project Manager'), $mrId, $lineId, ['quantity' => 10])
            ->assertStatus(200);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_change_log_appears_on_the_request_detail_payload(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();
        $pm = $this->userWithRole('Project Manager');

        $this->patchLineAs($pm, $mrId, $lineId, ['quantity' => 25])->assertStatus(200);

        $this->joinProject(); // detail reads sit behind project.access

        $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.change_log')
            ->assertJsonPath('data.change_log.0.event', 'material_request_item.updated')
            ->assertJsonPath('data.change_log.0.item_id', $lineId)
            ->assertJsonPath('data.change_log.0.old.quantity', '10.000')
            ->assertJsonPath('data.change_log.0.causer.id', $pm->id);
    }

    /* ---------------- validation still applies under review ---------------- */

    public function test_line_validation_still_applies_when_a_reviewer_edits(): void
    {
        [$mrId, $lineId] = $this->requestUnderReview();
        $pm = $this->userWithRole('Project Manager');

        $this->patchLineAs($pm, $mrId, $lineId, ['quantity' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['quantity']);

        // Converting to a free-text line still demands a trade category.
        $this->patchLineAs($pm, $mrId, $lineId, [
            'catalog_item_id' => null,
            'trade_category_id' => null,
            'description' => 'Whatever the plumber wanted',
        ])->assertStatus(422)->assertJsonValidationErrors(['trade_category_id']);
    }

    public function test_reviewer_added_line_still_derives_trade_category(): void
    {
        [$mrId] = $this->requestUnderReview();
        $catalogItem = CatalogItem::factory()->tradeCategory('Plumbing')->create();

        $lineId = (int) $this->addLineAs($this->userWithRole('Project Manager'), $mrId, [
            'catalog_item_id' => $catalogItem->id,
            'quantity' => 4,
        ])->assertStatus(201)->json('data.id');

        $this->assertSame(
            $catalogItem->trade_category_id,
            MaterialRequestItem::findOrFail($lineId)->trade_category_id,
        );
    }
}
