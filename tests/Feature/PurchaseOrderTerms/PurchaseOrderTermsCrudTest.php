<?php

namespace Tests\Feature\PurchaseOrderTerms;

use App\Models\Project;
use App\Models\PurchaseOrderTerm;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\PurchaseOrderTermsSeeder;

class PurchaseOrderTermsCrudTest extends PurchaseOrderTermsTestCase
{
    public function test_admin_can_create_the_default_terms(): void
    {
        $this->postTerms(['title' => 'Standard Terms', 'body' => 'Clause one.'])
            ->assertStatus(201)
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.project_id', null)
            ->assertJsonPath('data.title', 'Standard Terms');
    }

    public function test_admin_can_create_a_project_override(): void
    {
        $this->postTerms(['project_id' => $this->project->id, 'body' => 'Project clause.'])
            ->assertStatus(201)
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.project_id', $this->project->id);
    }

    public function test_procurement_cannot_manage_terms(): void
    {
        $this->postTerms(as: $this->procurement)->assertStatus(403);
    }

    public function test_a_project_manager_cannot_manage_terms(): void
    {
        // Holds edit_project — enough for delivery addresses, deliberately not
        // enough for contractual terms.
        $pm = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($pm, $this->role('Project Manager'));

        $this->postTerms(as: $pm)->assertStatus(403);
    }

    public function test_body_is_required(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/purchase-order-terms', ['title' => 'No body'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_a_second_default_is_rejected(): void
    {
        $this->postTerms(['body' => 'First.'])->assertStatus(201);

        $this->postTerms(['body' => 'Second.'])->assertStatus(409);
    }

    public function test_a_second_override_for_the_same_project_is_rejected(): void
    {
        $this->postTerms(['project_id' => $this->project->id, 'body' => 'First.'])->assertStatus(201);

        $this->postTerms(['project_id' => $this->project->id, 'body' => 'Second.'])->assertStatus(409);
    }

    public function test_two_different_projects_may_each_have_an_override(): void
    {
        $other = Project::factory()->create();

        $this->postTerms(['project_id' => $this->project->id, 'body' => 'A.'])->assertStatus(201);
        $this->postTerms(['project_id' => $other->id, 'body' => 'B.'])->assertStatus(201);
    }

    public function test_a_default_and_an_override_can_coexist(): void
    {
        $this->postTerms(['body' => 'Default.'])->assertStatus(201);
        $this->postTerms(['project_id' => $this->project->id, 'body' => 'Override.'])->assertStatus(201);
    }

    public function test_rescoping_onto_an_occupied_scope_is_rejected(): void
    {
        $default = $this->makeDefaultTerms('Default.');
        $this->makeProjectTerms('Override.');

        // Moving the default onto a project that already has its own set.
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/purchase-order-terms/{$default->id}", ['project_id' => $this->project->id])
            ->assertStatus(409);
    }

    public function test_terms_can_be_edited(): void
    {
        $terms = $this->makeDefaultTerms('Original clause.');

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/purchase-order-terms/{$terms->id}", ['body' => 'Revised clause.'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Revised clause.');
    }

    public function test_terms_can_be_deleted(): void
    {
        $terms = $this->makeDefaultTerms('Default.');

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/purchase-order-terms/{$terms->id}")
            ->assertOk();

        $this->assertSoftDeleted('purchase_order_terms', ['id' => $terms->id]);
    }

    public function test_deleting_the_default_frees_the_scope_for_a_new_one(): void
    {
        $terms = $this->makeDefaultTerms('Old default.');

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/purchase-order-terms/{$terms->id}")->assertOk();

        // The partial unique indexes exclude soft-deleted rows, so this must
        // now succeed rather than collide with the deleted row.
        $this->postTerms(['body' => 'New default.'])->assertStatus(201);
    }

    public function test_index_lists_default_first_then_overrides(): void
    {
        $this->makeProjectTerms('Override.');
        $this->makeDefaultTerms('Default.');

        $flags = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/purchase-order-terms')
            ->assertOk()
            ->json('data.*.is_default');

        $this->assertSame([true, false], $flags);
    }

    public function test_clauses_are_split_from_the_body(): void
    {
        $terms = $this->makeDefaultTerms("First clause.\n\nSecond clause.\nThird clause.");

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/purchase-order-terms/{$terms->id}")
            ->assertOk()
            ->assertJsonPath('data.clauses', ['First clause.', 'Second clause.', 'Third clause.']);
    }

    public function test_the_seeder_installs_a_five_clause_default(): void
    {
        $this->seed(PurchaseOrderTermsSeeder::class);

        $terms = PurchaseOrderTerm::whereNull('project_id')->firstOrFail();

        // Guards the heredoc: a mis-indented closing marker would fold the
        // clauses into one line, and nothing else would catch it.
        $this->assertCount(5, $terms->clauses());
        $this->assertStringStartsWith('This purchase order number must appear', $terms->clauses()[0]);

        // The reworded clause — the original demo text pointed at PO payment
        // terms, a field this schema does not have.
        $this->assertStringContainsString('agreed payment terms', $terms->clauses()[4]);
        $this->assertStringNotContainsString('stated above', $terms->clauses()[4]);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(PurchaseOrderTermsSeeder::class);
        $this->seed(PurchaseOrderTermsSeeder::class);

        // A second run must not trip purchase_order_terms_default_unique.
        $this->assertSame(1, PurchaseOrderTerm::whereNull('project_id')->count());
    }
}
