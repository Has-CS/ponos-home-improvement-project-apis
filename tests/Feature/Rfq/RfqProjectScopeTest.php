<?php

namespace Tests\Feature\Rfq;

use App\Models\Project;

/**
 * Once an RFQ carries a project_id, it has stopped being pre-project planning
 * and become that project's procurement — a PM must be staffed on that
 * project to write to it (create against it, edit, touch its lines, or
 * submit), same as Purchase Orders. Reads stay open regardless of project
 * (cross-project quote comparison while planning is the whole point of the
 * module), and a still-unlinked (project_id null) RFQ stays fully writable by
 * any PM. `$this->pm` (from RfqTestCase) is staffed on Project A only.
 */
class RfqProjectScopeTest extends RfqTestCase
{
    protected Project $projectA;

    protected Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectA = Project::factory()->create();
        $this->projectB = Project::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/projects/{$this->projectA->id}/assign-user", ['user_id' => $this->pm->id])
            ->assertStatus(201);
    }

    /** Admin is unrestricted, so it's a neutral way to build project-linked fixtures. */
    private function createRfqFor(?Project $project): int
    {
        return $this->createDraftAs($this->admin, $project ? ['project_id' => $project->id] : []);
    }

    /* ---------------- reads stay open ---------------- */

    public function test_pm_can_read_an_rfq_tied_to_another_project(): void
    {
        $rfqId = $this->createRfqFor($this->projectB);

        $this->showAs($this->pm, $rfqId)->assertOk();
    }

    /* ---------------- creation ---------------- */

    public function test_pm_cannot_create_an_rfq_against_another_projects_id(): void
    {
        $this->actingAs($this->pm, 'api')->postJson('/api/v1/rfqs', [
            'vendor_id' => $this->vendor->id,
            'title' => "Bid for someone else's job",
            'project_id' => $this->projectB->id,
        ])->assertStatus(403);
    }

    public function test_pm_can_create_an_rfq_against_their_own_project(): void
    {
        $this->createDraftAs($this->pm, ['project_id' => $this->projectA->id]);
    }

    public function test_pm_can_create_a_pre_project_rfq_without_any_project(): void
    {
        $this->createDraftAs($this->pm);
    }

    /* ---------------- update, incl. attaching a project ---------------- */

    public function test_pm_cannot_update_an_rfq_tied_to_another_project(): void
    {
        $rfqId = $this->createRfqFor($this->projectB);

        $this->updateRfqAs($this->pm, $rfqId, ['title' => 'Trying to edit'])->assertStatus(403);
    }

    public function test_pm_can_update_an_rfq_tied_to_their_own_project(): void
    {
        $rfqId = $this->createRfqFor($this->projectA);

        $this->updateRfqAs($this->pm, $rfqId, ['title' => 'Updated title'])->assertOk();
    }

    public function test_pm_can_update_a_pre_project_rfq(): void
    {
        $rfqId = $this->createRfqFor(null);

        $this->updateRfqAs($this->pm, $rfqId, ['title' => 'Updated title'])->assertOk();
    }

    public function test_pm_cannot_attach_a_pre_project_rfq_to_another_project(): void
    {
        $rfqId = $this->createRfqFor(null);

        $this->updateRfqAs($this->pm, $rfqId, ['project_id' => $this->projectB->id])->assertStatus(403);
    }

    public function test_pm_can_attach_a_pre_project_rfq_to_their_own_project(): void
    {
        $rfqId = $this->createRfqFor(null);

        $this->updateRfqAs($this->pm, $rfqId, ['project_id' => $this->projectA->id])->assertOk();
    }

    /* ---------------- line items ---------------- */

    public function test_pm_cannot_add_an_item_to_an_rfq_tied_to_another_project(): void
    {
        $rfqId = $this->createRfqFor($this->projectB);

        $this->addItemAs($this->pm, $rfqId, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 10,
        ])->assertStatus(403);
    }

    public function test_pm_cannot_update_an_item_on_an_rfq_tied_to_another_project(): void
    {
        $rfqId = $this->createRfqFor($this->projectB);
        $itemResponse = $this->addItemAs($this->admin, $rfqId, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 10,
        ]);
        $itemResponse->assertStatus(201);

        $this->updateItemAs($this->pm, $rfqId, (int) $itemResponse->json('data.id'), ['quantity' => 20])
            ->assertStatus(403);
    }

    public function test_pm_cannot_remove_an_item_from_an_rfq_tied_to_another_project(): void
    {
        $rfqId = $this->createRfqFor($this->projectB);
        $itemResponse = $this->addItemAs($this->admin, $rfqId, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 10,
        ]);
        $itemResponse->assertStatus(201);

        $this->removeItemAs($this->pm, $rfqId, (int) $itemResponse->json('data.id'))
            ->assertStatus(403);
    }

    /* ---------------- submit ---------------- */

    public function test_pm_cannot_submit_an_rfq_tied_to_another_project(): void
    {
        $rfqId = $this->createRfqFor($this->projectB);
        $this->addItemAs($this->admin, $rfqId, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 10,
        ])->assertStatus(201);

        $this->submitAs($this->pm, $rfqId)->assertStatus(403);
    }

    public function test_pm_can_submit_an_rfq_tied_to_their_own_project(): void
    {
        $rfqId = $this->createRfqFor($this->projectA);
        $this->addItemAs($this->admin, $rfqId, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 10,
        ])->assertStatus(201);

        $this->submitAs($this->pm, $rfqId)->assertOk();
    }

    /* ---------------- admin unaffected ---------------- */

    public function test_admin_is_unaffected_by_project_scoping(): void
    {
        $rfqId = $this->createRfqFor($this->projectB);

        $this->updateRfqAs($this->admin, $rfqId, ['title' => 'Admin can edit'])->assertOk();
    }
}
