<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Admin oversight queue.
 *
 * This is what "the Admin can see that a PM sent it direct" means in practice. There
 * is no notification system in this application — the notifications table exists and
 * nothing has ever written to it — so the honest answer is a queue an Admin can open,
 * not a push that does not exist. Building the notification layer is a cross-cutting
 * project of its own, not part of the emergency change-order flow.
 *
 * The queue also has to surface the rows nobody else will go looking for: an
 * emergency change order the General Contractor REFUSED after the work was authorised
 * on site and carried out. Those are the most financially urgent records in the
 * system, and gc_rejected is terminal, so without a filter they simply vanish.
 */
class ChangeOrderGcQueueTest extends ChangeOrderTestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const URL = '/api/v1/change-orders/gc-queue';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    private function grantSendToGc(User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $user->givePermissionTo('send_change_request_to_gc');
        $user->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<string,mixed> $payload */
    private function capture(array $payload = []): int
    {
        return (int) $this->actingAs($this->foreman, 'api')
            ->postJson($this->base().'/emergency', [
                'title' => 'Unforeseen conduit conflict',
                'scope' => 'Reframe the door opening.',
                'location' => 'Level 3, Unit 312',
                'signer_name' => 'Dana Whitfield',
                'signature_image' => self::PNG,
                ...$payload,
            ])->assertStatus(201)->json('data.id');
    }

    /** Captured, priced, and sent straight to the GC by the PM. */
    private function sentDirect(): int
    {
        $id = $this->capture();
        $this->updateAs($this->pm, $id, ['value' => 12500.00])->assertOk();
        $this->grantSendToGc($this->pm);
        $this->actAs($this->pm, $id, 'send-to-gc')->assertOk();

        return $id;
    }

    /** Captured, priced, reviewed by an Admin, then sent. */
    private function sentAfterReview(): int
    {
        $id = $this->capture();
        $this->updateAs($this->pm, $id, ['value' => 8000.00])->assertOk();
        $this->actAs($this->pm, $id, 'forward')->assertOk();
        $this->actAs($this->admin, $id, 'send-to-gc')->assertOk();

        return $id;
    }

    /* ---------------- what the queue contains ---------------- */

    public function test_it_lists_only_change_orders_that_reached_the_gc(): void
    {
        $sent = $this->sentDirect();
        $unsent = $this->capture();                 // still at work_authorized
        $normalDraft = $this->changeOrderAt('draft');

        $ids = collect($this->actingAs($this->admin, 'api')->getJson(self::URL)
            ->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertContains($sent, $ids);
        $this->assertNotContains($unsent, $ids, 'An unsent change order is not in the queue.');
        $this->assertNotContains($normalDraft, $ids);
    }

    public function test_the_bypass_filter_returns_only_what_skipped_admin_review(): void
    {
        $direct = $this->sentDirect();
        $reviewed = $this->sentAfterReview();

        $ids = collect($this->actingAs($this->admin, 'api')
            ->getJson(self::URL.'?gc_sent_via=pm_direct')
            ->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertSame([$direct], $ids);
        $this->assertNotContains($reviewed, $ids);
    }

    public function test_the_reviewed_route_is_filterable_too(): void
    {
        $direct = $this->sentDirect();
        $reviewed = $this->sentAfterReview();

        $ids = collect($this->actingAs($this->admin, 'api')
            ->getJson(self::URL.'?gc_sent_via=admin_reviewed')
            ->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertSame([$reviewed], $ids);
        $this->assertNotContains($direct, $ids);
    }

    public function test_each_row_carries_the_route_it_took_and_when(): void
    {
        $this->sentDirect();

        $row = $this->actingAs($this->admin, 'api')->getJson(self::URL)
            ->assertOk()->json('data.items.0');

        $this->assertSame('pm_direct', $row['gc_sent_via']);
        $this->assertNotNull($row['sent_to_gc_at']);
        $this->assertNotNull($row['work_authorized_at']);
        $this->assertFalse($row['awaiting_pricing']);
    }

    /* ---------------- work built, payment refused ---------------- */

    public function test_the_disputed_filter_finds_built_work_the_gc_refused(): void
    {
        $refused = $this->sentDirect();
        $this->gcDecisionAs($this->pm, $refused, ['decision' => 'rejected', 'notes' => 'Disputes the rate.'])->assertOk();

        $accepted = $this->sentAfterReview();
        $this->gcDecisionAs($this->pm, $accepted, ['decision' => 'approved'])->assertOk();

        $ids = collect($this->actingAs($this->admin, 'api')->getJson(self::URL.'?disputed=1')
            ->assertOk()->json('data.items'))->pluck('id')->all();

        // gc_rejected is terminal, so without this filter these rows disappear from
        // view entirely — while someone still has to decide who pays for work that
        // exists.
        $this->assertSame([$refused], $ids);
        $this->assertNotContains($accepted, $ids);
    }

    public function test_a_normal_rejected_change_order_is_not_a_dispute(): void
    {
        // A Normal change order the GC rejected means the work never happened. There
        // is nothing to dispute, and work_authorized_at is what tells them apart.
        $id = $this->changeOrderAt('pending_gc');
        $this->gcDecisionAs($this->pm, $id, ['decision' => 'rejected'])->assertOk();

        $ids = collect($this->actingAs($this->admin, 'api')->getJson(self::URL.'?disputed=1')
            ->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertNotContains($id, $ids);
    }

    /* ---------------- scoping ---------------- */

    public function test_a_pm_sees_only_their_own_projects(): void
    {
        $mine = $this->sentDirect();

        // A second project with its own staff, and a change order sent there.
        $other = Project::factory()->create(['code' => 'PNS-2026-099']);
        ProjectGeneralContractor::create([
            'project_id' => $other->id, 'name' => 'Other GC',
            'street_1' => '1 Elsewhere', 'city' => 'Chicago',
            'email' => 'other@gc.test', 'is_primary' => true,
        ]);

        $otherForeman = User::factory()->create();
        $otherPm = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($otherForeman, $this->role('Foreman'));
        app(RoleAssignmentService::class)->assignGlobalRole($otherPm, $this->role('Project Manager'));
        app(RoleAssignmentService::class)->assignProjectRole($other, $otherForeman, $this->role('Foreman'), null);
        app(RoleAssignmentService::class)->assignProjectRole($other, $otherPm, $this->role('Project Manager'), null);

        $base = "/api/v1/projects/{$other->id}/change-orders";
        $foreign = (int) $this->actingAs($otherForeman, 'api')->postJson($base.'/emergency', [
            'title' => 'Elsewhere', 'scope' => 'x', 'location' => 'y',
            'signer_name' => 'Someone', 'signature_image' => self::PNG,
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($otherPm, 'api')->patchJson($base."/{$foreign}", ['value' => 500.00])->assertOk();
        $this->grantSendToGc($otherPm);
        $this->actingAs($otherPm, 'api')->postJson($base."/{$foreign}/send-to-gc")->assertOk();

        // Our PM is staffed on one project only.
        $ids = collect($this->actingAs($this->pm, 'api')->getJson(self::URL)
            ->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertContains($mine, $ids);
        $this->assertNotContains($foreign, $ids, 'A PM must not see another project through the queue.');

        // An Admin sees both.
        $adminIds = collect($this->actingAs($this->admin, 'api')->getJson(self::URL)
            ->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertContains($mine, $adminIds);
        $this->assertContains($foreign, $adminIds);
    }

    public function test_it_is_gated_by_approve_change_request(): void
    {
        $this->sentDirect();

        // A foreman may raise change orders but not review them, so the oversight
        // queue is not theirs.
        $this->actingAs($this->foreman, 'api')->getJson(self::URL)->assertStatus(403);
    }

    public function test_it_rejects_an_unknown_route_value(): void
    {
        $this->actingAs($this->admin, 'api')->getJson(self::URL.'?gc_sent_via=somehow')
            ->assertStatus(422);
    }

    /* ---------------- the path, and the 500 it used to cause ---------------- */

    /**
     * The oversight queue is TOP-LEVEL. Every other change-order endpoint is nested
     * under a project, so constructing the queue's path by analogy is the obvious
     * mistake to make — and it was reported from the field.
     *
     * The nested path matches projects/{project}/change-orders/{change_order} with
     * change_order = "gc-queue". Before the numeric route patterns in routes/api.php,
     * implicit binding handed that straight to Postgres, which refused the cast
     * (SQLSTATE 22P02, "invalid input syntax for type bigint: gc-queue"). The
     * resulting QueryException is one the exception handler is right to mask, so the
     * caller got "Something went wrong. Please try again later." with a 500 and a
     * reference — for a mistyped URL.
     */
    public function test_the_project_nested_queue_path_is_a_404_not_a_server_error(): void
    {
        $this->sentDirect();

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/change-orders/gc-queue")
            ->assertStatus(404);
    }

    public function test_the_top_level_queue_path_is_the_one_that_works(): void
    {
        $id = $this->sentDirect();

        $ids = collect($this->actingAs($this->admin, 'api')->getJson(self::URL)
            ->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertContains($id, $ids);
    }

    /**
     * The same class of fault on any id-bound parameter, which is what the global
     * patterns fix. A non-numeric segment must never reach the database.
     */
    public function test_a_non_numeric_id_is_a_404_on_any_route(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/projects/not-an-id/change-orders')
            ->assertStatus(404);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/change-orders/not-an-id")
            ->assertStatus(404);
    }

    /** A missing but well-formed id still resolves to the handler's clean 404. */
    public function test_a_numeric_id_that_does_not_exist_is_still_a_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/change-orders/999999")
            ->assertStatus(404);
    }

    public function test_it_paginates(): void
    {
        $this->sentDirect();

        $this->actingAs($this->admin, 'api')->getJson(self::URL.'?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonStructure(['data' => ['items', 'pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);
    }
}
