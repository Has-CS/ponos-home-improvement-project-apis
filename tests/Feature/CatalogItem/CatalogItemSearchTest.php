<?php

namespace Tests\Feature\CatalogItem;

use App\Models\CatalogItem;
use App\Models\MaterialRequestItem;
use App\Models\Project;
use App\Models\Urgency;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The catalog type-ahead that replaces the two long dropdowns when adding a
 * material-request line.
 *
 * The headline case is authorization: a Foreman gets 403 from the pre-existing
 * /catalog-items endpoint (it is view_pricing-gated because it exposes vendor
 * rates), so this endpoint exists to serve them a price-free view.
 */
class CatalogItemSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $foreman;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->foreman = $this->userWithRole('Foreman');
        $this->project = Project::factory()->create();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $user,
            Role::where('name', $roleName)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        return $user;
    }

    /** @param array<string,mixed> $params */
    private function search(array $params, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->foreman, 'api')->getJson(
            "/api/v1/projects/{$this->project->id}/catalog-items/search?".http_build_query($params),
        );
    }

    /* ---------------- matching ---------------- */

    public function test_matches_on_name_substring(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A single-pole']);
        CatalogItem::factory()->create(['name' => 'Copper pipe 15mm']);

        $this->search(['q' => 'break'])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Breaker 20A single-pole');
    }

    public function test_matches_on_description_substring(): void
    {
        CatalogItem::factory()->create([
            'name' => 'CB-2010',
            'description' => 'Standard residential branch breaker',
        ]);

        $this->search(['q' => 'branch'])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'CB-2010');
    }

    public function test_matches_on_trade_category_name(): void
    {
        // Neither the name nor the description contains "electrical" — the match
        // can only come from the item's trade category.
        CatalogItem::factory()->tradeCategory('Electrical')->create([
            'name' => 'CB-2010',
            'description' => 'Twenty amp, single pole',
        ]);
        CatalogItem::factory()->tradeCategory('Plumbing')->create(['name' => 'Copper pipe 15mm']);

        $this->search(['q' => 'electrical'])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'CB-2010');
    }

    public function test_search_is_case_insensitive(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A single-pole']);

        $this->search(['q' => 'BREAKER'])->assertStatus(200)->assertJsonCount(1, 'data.items');
    }

    public function test_query_shorter_than_two_characters_is_rejected(): void
    {
        $this->search(['q' => 'b'])->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_missing_query_is_rejected(): void
    {
        $this->search([])->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_soft_deleted_items_are_excluded(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Breaker 20A single-pole']);
        $item->delete();

        $this->search(['q' => 'break'])->assertStatus(200)->assertJsonCount(0, 'data.items');
    }

    /* ---------------- project scoping ---------------- */

    public function test_returns_global_items_and_this_projects_custom_items_only(): void
    {
        $otherProject = Project::factory()->create();

        CatalogItem::factory()->create(['name' => 'Breaker global', 'project_id' => null]);
        CatalogItem::factory()->create(['name' => 'Breaker mine', 'project_id' => $this->project->id, 'is_custom' => true]);
        CatalogItem::factory()->create(['name' => 'Breaker theirs', 'project_id' => $otherProject->id, 'is_custom' => true]);

        $names = $this->search(['q' => 'breaker'])->assertStatus(200)->json('data.items.*.name');

        sort($names);
        $this->assertSame(['Breaker global', 'Breaker mine'], $names);
    }

    /* ---------------- limiting ---------------- */

    public function test_limit_is_respected_and_has_more_is_flagged(): void
    {
        CatalogItem::factory()->count(5)->create(['name' => 'Breaker unit']);

        $this->search(['q' => 'breaker', 'limit' => 3])
            ->assertStatus(200)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.limit', 3)
            ->assertJsonPath('data.has_more', true);
    }

    public function test_has_more_is_false_when_results_fit(): void
    {
        CatalogItem::factory()->count(2)->create(['name' => 'Breaker unit']);

        $this->search(['q' => 'breaker', 'limit' => 10])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.has_more', false);
    }

    public function test_response_carries_no_pagination_total(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A']);

        $data = $this->search(['q' => 'break'])->assertStatus(200)->json('data');

        $this->assertArrayNotHasKey('pagination', $data);
        $this->assertArrayNotHasKey('total', $data);
        $this->assertArrayNotHasKey('last_page', $data);
    }

    public function test_limit_above_the_maximum_is_rejected(): void
    {
        $this->search(['q' => 'break', 'limit' => 500])->assertStatus(422)->assertJsonValidationErrors(['limit']);
    }

    /* ---------------- ordering ---------------- */

    public function test_prefix_matches_rank_above_mid_string_matches(): void
    {
        CatalogItem::factory()->create(['name' => 'Panel with breaker slots']);
        CatalogItem::factory()->create(['name' => 'Breaker 20A single-pole']);

        $names = $this->search(['q' => 'break'])->assertStatus(200)->json('data.items.*.name');

        $this->assertSame('Breaker 20A single-pole', $names[0]);
    }

    /* ---------------- authorization ---------------- */

    public function test_foreman_can_search_the_catalog(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A']);

        // The whole point: this same user gets 403 from GET /catalog-items.
        $this->search(['q' => 'break'], $this->foreman)->assertStatus(200);
        $this->actingAs($this->foreman, 'api')->getJson('/api/v1/catalog-items')->assertStatus(403);
    }

    public function test_procurement_can_search_via_view_pricing(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A']);

        $this->search(['q' => 'break'], $this->userWithRole('Procurement'))->assertStatus(200);
    }

    public function test_user_with_neither_permission_is_denied(): void
    {
        // A user with no roles at all holds neither create_material_request nor
        // view_pricing.
        $this->search(['q' => 'break'], User::factory()->create())->assertStatus(403);
    }

    /* ---------------- no price leakage ---------------- */

    public function test_results_expose_no_pricing_data(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Breaker 20A']);

        $row = $this->search(['q' => 'break'])->assertStatus(200)->json('data.items.0');

        foreach (['rate', 'current_vendor_rates', 'vendor', 'vendor_rates'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }

        // What it SHOULD carry for the picker.
        $this->assertSame($item->trade_category_id, $row['trade_category']['id']);
        $this->assertArrayHasKey('description', $row);
        $this->assertArrayHasKey('default_unit', $row);
    }

    /* ---------------- integration with the line-derivation rule ---------------- */

    public function test_searched_item_drives_derived_trade_category_and_unit_on_save(): void
    {
        $item = CatalogItem::factory()->tradeCategory('Electrical')->defaultUnit('ea')
            ->create(['name' => 'Breaker 20A single-pole']);

        $picked = $this->search(['q' => 'break'])->assertStatus(200)->json('data.items.0');

        $mrId = $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests",
            ['urgency_id' => Urgency::where('code', 'normal')->value('id')],
        )->assertStatus(201)->json('data.id');

        // The client sends ONLY what it captured from the picker — no
        // trade_category_id, no unit_id.
        $itemId = (int) $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items",
            ['catalog_item_id' => $picked['id'], 'quantity' => 10, 'notes' => 'For Electricity'],
        )->assertStatus(201)->json('data.id');

        $line = MaterialRequestItem::findOrFail($itemId);

        $this->assertSame($item->trade_category_id, $line->trade_category_id);
        $this->assertSame($item->default_unit_id, $line->unit_id);
    }
}
