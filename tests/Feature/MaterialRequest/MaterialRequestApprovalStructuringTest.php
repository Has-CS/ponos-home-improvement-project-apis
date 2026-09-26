<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatus;
use App\Models\Unit;
use App\Models\Urgency;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Testing\TestResponse;

/**
 * A request must say, in catalog terms, exactly what is being bought before it
 * can be approved.
 *
 * The purchase order derives the item, unit and price from these lines, so an
 * unmapped line leaves the buyer being asked for the item a second time — two
 * sources that can disagree. The gate sits on the transition INTO `approved`
 * and nowhere earlier: a foreman still submits prose, and a PM may still pass
 * prose up to the Admin.
 */
class MaterialRequestApprovalStructuringTest extends MaterialRequestLineTestCase
{
    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /** @param array<string,mixed> $payload */
    private function createRaw(array $payload): int
    {
        return (int) $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests",
            ['urgency_id' => Urgency::where('code', 'normal')->value('id'), ...$payload],
        )->assertStatus(201)->json('data.id');
    }

    private function submit(int $mrId): void
    {
        $this->actingAs($this->foreman, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit")
            ->assertStatus(200);
    }

    private function approve(int $mrId, User $as): TestResponse
    {
        return $this->actingAs($as, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/approve");
    }

    /** A catalog line, ready to be ordered from. */
    private function catalogLine(): array
    {
        return ['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 5];
    }

    /** A line naming no catalog item — legal to write, not legal to approve. */
    private function freeTextLine(): array
    {
        return [
            'description' => '2x 8ft pressure-treated 4x4',
            'quantity' => 5,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'trade_category_id' => $this->tradeCategoryId('Roofing'),
        ];
    }

    private function statusOf(int $mrId): string
    {
        return MaterialRequest::with('status')->findOrFail($mrId)->status->code;
    }

    /** Take a request to pending_admin, the step before approval. */
    private function atPendingAdmin(array $payload): int
    {
        $mrId = $this->createRaw($payload);
        $this->submit($mrId);
        $this->approve($mrId, $this->userWithRole('Project Manager'))->assertStatus(200);

        return $mrId;
    }

    /* ---------------- what is refused ---------------- */

    public function test_a_request_with_no_lines_cannot_be_approved(): void
    {
        $mrId = $this->atPendingAdmin(['request_text' => 'Need 20 steel nuts']);

        $response = $this->approve($mrId, $this->userWithRole('Admin'))->assertStatus(422);

        $this->assertStringContainsString('no line items', $response->json('message'));
        $this->assertSame('pending_admin', $this->statusOf($mrId));
    }

    public function test_a_free_text_line_blocks_approval_and_is_named(): void
    {
        $mrId = $this->atPendingAdmin([
            'items' => [$this->catalogLine(), $this->freeTextLine(), $this->catalogLine()],
        ]);

        $response = $this->approve($mrId, $this->userWithRole('Admin'))->assertStatus(422);

        // Named by its own text, which is what the reviewer sees on the row.
        // Not a position: validated() rebuilds the items array in rule order, so
        // stored order need not match the order the client sent.
        $this->assertStringContainsString('2x 8ft pressure-treated 4x4', $response->json('message'));
        $this->assertSame('pending_admin', $this->statusOf($mrId));
    }

    public function test_prose_that_has_not_been_signed_off_blocks_approval(): void
    {
        // Lines exist and are all mapped, but nobody has said they COVER the
        // words — the request asks for eight things and names one.
        $mrId = $this->atPendingAdmin([
            'request_text' => 'Cement, sand, aggregate, bricks, blocks, rebar, binding wire, blocks',
            'items' => [$this->catalogLine()],
        ]);

        $response = $this->approve($mrId, $this->userWithRole('Admin'))->assertStatus(422);

        $this->assertStringContainsString('signed off', $response->json('message'));
        $this->assertSame('pending_admin', $this->statusOf($mrId));
    }

    /* ---------------- what is allowed ---------------- */

    public function test_a_fully_mapped_request_without_prose_is_approved(): void
    {
        $mrId = $this->atPendingAdmin(['items' => [$this->catalogLine(), $this->catalogLine()]]);

        // No prose, so there is nothing for a sign-off to cover.
        $this->approve($mrId, $this->userWithRole('Admin'))->assertStatus(200);

        $this->assertSame('approved', $this->statusOf($mrId));
    }

    public function test_prose_mapped_and_signed_off_is_approved(): void
    {
        $mrId = $this->atPendingAdmin([
            'request_text' => 'Need 20 steel nuts',
            'items' => [$this->catalogLine()],
        ]);

        $admin = $this->userWithRole('Admin');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/mark-structured")
            ->assertStatus(200);

        $this->approve($mrId, $admin)->assertStatus(200);

        $this->assertSame('approved', $this->statusOf($mrId));
    }

    /* ---------------- where the gate is NOT ---------------- */

    /**
     * Only the final step is gated. A foreman who cannot work the catalog must
     * still be able to send prose, and a PM must still be able to pass it up —
     * gating earlier would hand the mapping back to the one person who cannot
     * do it.
     */
    public function test_a_pm_may_still_pass_unstructured_prose_up_to_the_admin(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts']);
        $this->submit($mrId);

        $this->approve($mrId, $this->userWithRole('Project Manager'))->assertStatus(200);

        $this->assertSame('pending_admin', $this->statusOf($mrId));
    }

    public function test_submitting_unstructured_prose_is_still_allowed(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts']);

        $this->submit($mrId);

        $this->assertSame('pending_pm', $this->statusOf($mrId));
    }

    /** The PM's shortcut past the Admin is gated the same way. */
    public function test_the_finalize_shortcut_is_gated_too(): void
    {
        $mrId = $this->createRaw(['request_text' => 'Need 20 steel nuts']);
        $this->submit($mrId);

        $pm = $this->userWithRole('Project Manager');
        $pm->givePermissionTo('finalize_material_request');

        $this->actingAs($pm, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/finalize")
            ->assertStatus(422);

        $this->assertSame('pending_pm', $this->statusOf($mrId));
    }

    /**
     * Seeding a row straight into `approved` — how the purchase-order and
     * delivery fixtures build their data — is untouched. This is a rule about
     * the workflow transition, not about the row.
     */
    public function test_a_request_seeded_directly_as_approved_is_untouched(): void
    {
        $mr = MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $this->project->id,
            'requested_by' => $this->foreman->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'request_text' => 'Legacy prose, never mapped',
            'created_by' => $this->foreman->id,
        ]);

        $this->assertSame('approved', $mr->fresh()->status->code);
        $this->assertSame(0, $mr->items()->count());
    }
}
