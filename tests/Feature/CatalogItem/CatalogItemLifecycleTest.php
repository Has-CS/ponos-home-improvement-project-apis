<?php

namespace Tests\Feature\CatalogItem;

use App\Models\CatalogItem;
use App\Models\CatalogItemType;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\TradeCategory;
use App\Models\Unit;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRate;
use App\Services\CatalogItem\CatalogItemService;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Retiring a catalog item.
 *
 * An item referenced by a rate, estimate, material request or purchase order
 * cannot be deleted — correctly, those references must survive — so `is_active`
 * is how a discontinued product leaves the pickers instead.
 *
 * The contract in one line: deactivating affects only NEW selection. Every
 * historical reference keeps resolving, and the item stays visible and
 * reactivatable in the admin list.
 */
class CatalogItemLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $foreman;

    private Project $project;

    private CatalogItemService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('Admin');
        $this->foreman = $this->userWithRole('Foreman');
        $this->project = Project::factory()->create();
        $this->service = app(CatalogItemService::class);
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

    private function setStatus(CatalogItem $item, bool $active): TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/catalog-items/{$item->id}/status", ['is_active' => $active]);
    }

    /** @return array<int,int> ids the material-request picker returns */
    private function picker(string $term): array
    {
        return $this->service->search($this->project, ['q' => $term])['items']
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /* ---------------- the flag itself ---------------- */

    public function test_a_new_item_is_active_by_default(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/api/v1/catalog-items', [
            'trade_category_id' => TradeCategory::query()->orderBy('id')->value('id'),
            'catalog_item_type_id' => CatalogItemType::where('code', 'material')->value('id'),
            'default_unit_id' => Unit::where('code', 'ea')->value('id'),
            'name' => 'Zephyr coupling',
        ])->assertStatus(201);

        $this->assertTrue($response->json('data.is_active'));
        $this->assertTrue(CatalogItem::findOrFail($response->json('data.id'))->is_active);
    }

    public function test_the_status_endpoint_retires_and_restores(): void
    {
        $item = CatalogItem::factory()->create();

        $this->setStatus($item, false)->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($item->refresh()->is_active);

        $this->setStatus($item, true)->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertTrue($item->refresh()->is_active);
    }

    public function test_the_status_endpoint_requires_the_flag(): void
    {
        $item = CatalogItem::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/catalog-items/{$item->id}/status", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_the_flag_is_also_settable_through_the_ordinary_update(): void
    {
        $item = CatalogItem::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/catalog-items/{$item->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($item->refresh()->is_active);
    }

    /* ---------------- the point: out of the pickers ---------------- */

    public function test_a_retired_item_disappears_from_the_picker_and_returns_when_restored(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Zephyr coupling', 'sku' => 'ZC-1']);

        $this->assertContains($item->id, $this->picker('Zephyr'));

        $this->setStatus($item, false)->assertOk();
        $this->assertNotContains($item->id, $this->picker('Zephyr'), 'a retired item must not be offered');
        $this->assertNotContains($item->id, $this->picker('ZC-1'), 'nor by SKU');

        $this->setStatus($item, true)->assertOk();
        $this->assertContains($item->id, $this->picker('Zephyr'), 'restoring must bring it back');
    }

    /** All three pickers share CatalogItemService::search(), so all three must honour it. */
    public function test_all_three_pickers_exclude_a_retired_item(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Zephyr coupling', 'sku' => 'ZC-1']);
        $vendor = Vendor::create(['name' => 'Riverside Supply Co.', 'email' => 'p@r.test', 'is_active' => true]);

        $this->setStatus($item, false)->assertOk();

        // Material-request picker, over HTTP, as a Foreman without view_pricing.
        $mr = $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/catalog-items/search?q=Zephyr")
            ->assertOk()->json('data.items');
        $this->assertNotContains($item->id, array_column($mr, 'id'));

        // Purchase-order picker, over HTTP.
        $po = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/purchase-orders/catalog-items/search?'.http_build_query([
                'q' => 'Zephyr', 'project_id' => $this->project->id, 'vendor_id' => $vendor->id,
            ]))
            ->assertOk()->json('data.items');
        $this->assertNotContains($item->id, array_column($po, 'id'));

        // RFQ picker — same service, no project (pre-project).
        $rfq = $this->service->search(null, ['q' => 'Zephyr'])['items'];
        $this->assertNotContains($item->id, $rfq->pluck('id')->all());
    }

    /**
     * The exclusion must be an unconditional AND, not another OR branch inside
     * the grouped match. Folded into applySearch()'s closure, a retired item
     * whose name matched would still come back.
     */
    public function test_the_exclusion_holds_alongside_a_matching_term_and_project_scope(): void
    {
        $retired = CatalogItem::factory()->create(['name' => 'Zephyr coupling', 'sku' => 'ZC-1', 'project_id' => null]);
        $active = CatalogItem::factory()->create(['name' => 'Zephyr valve', 'sku' => 'ZV-1', 'project_id' => null]);

        $this->setStatus($retired, false)->assertOk();

        $found = $this->picker('Zephyr');

        $this->assertContains($active->id, $found);
        $this->assertNotContains($retired->id, $found);
    }

    /* ---------------- history must survive ---------------- */

    /**
     * The critical guarantee. Retiring is a listing concern; a material-request
     * line raised before the item was retired must still resolve it.
     */
    public function test_an_existing_material_request_line_still_resolves_a_retired_item(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Zephyr coupling']);

        $mr = MaterialRequest::create([
            'request_no' => 'MR-TEST-1',
            'project_id' => $this->project->id,
            'requested_by' => $this->foreman->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'draft')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->foreman->id,
        ]);

        $line = MaterialRequestItem::create([
            'material_request_id' => $mr->id,
            'catalog_item_id' => $item->id,
            'unit_id' => Unit::where('code', 'ea')->value('id'),
            'quantity' => 5,
        ]);

        $this->setStatus($item, false)->assertOk();

        $reloaded = MaterialRequestItem::with('catalogItem')->findOrFail($line->id);

        $this->assertNotNull($reloaded->catalogItem, 'a retired item must still resolve on an existing line');
        $this->assertSame('Zephyr coupling', $reloaded->catalogItem->name);
        $this->assertFalse($reloaded->catalogItem->is_active);
    }

    public function test_a_retired_item_is_still_readable_on_its_detail_endpoint(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Zephyr coupling']);
        $this->setStatus($item, false)->assertOk();

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/catalog-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.is_active', false);
    }

    /* ---------------- the admin list keeps it findable ---------------- */

    public function test_the_admin_list_shows_both_kinds_by_default_and_filters_on_request(): void
    {
        $active = CatalogItem::factory()->create(['name' => 'Active widget']);
        $retired = CatalogItem::factory()->create(['name' => 'Retired widget']);
        $this->setStatus($retired, false)->assertOk();

        $ids = fn (?string $query) => array_column(
            $this->actingAs($this->admin, 'api')
                ->getJson('/api/v1/catalog-items'.($query ? "?{$query}" : ''))
                ->assertOk()->json('data.items'),
            'id',
        );

        $all = $ids(null);
        $this->assertContains($active->id, $all);
        $this->assertContains($retired->id, $all, 'a retired item must stay findable, or it could never be reactivated');

        $this->assertContains($active->id, $ids('is_active=1'));
        $this->assertNotContains($retired->id, $ids('is_active=1'));

        $this->assertContains($retired->id, $ids('is_active=0'));
        $this->assertNotContains($active->id, $ids('is_active=0'));

        // Query-string booleans arrive as text; prepareForValidation normalises them.
        $this->assertContains($retired->id, $ids('is_active=false'));
        $this->assertContains($active->id, $ids('is_active=true'));
    }

    /* ---------------- no collateral damage ---------------- */

    public function test_retiring_one_item_leaves_others_untouched(): void
    {
        $a = CatalogItem::factory()->create(['name' => 'Zephyr coupling']);
        $b = CatalogItem::factory()->create(['name' => 'Zephyr valve']);

        $this->setStatus($a, false)->assertOk();

        $this->assertTrue($b->refresh()->is_active);
        $this->assertContains($b->id, $this->picker('Zephyr'));
    }

    /** The delete guards are unchanged; their messages now name the alternative. */
    public function test_the_delete_guard_points_at_deactivation(): void
    {
        $item = CatalogItem::factory()->create();

        VendorRate::create([
            'vendor_id' => Vendor::create(['name' => 'V', 'email' => 'v@v.test', 'is_active' => true])->id,
            'catalog_item_id' => $item->id,
            'unit_id' => Unit::where('code', 'ea')->value('id'),
            'rate' => '1.00',
            'currency' => 'USD',
            'effective_from' => now()->subDay()->toDateString(),
            'entered_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/catalog-items/{$item->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete: this item still has vendor rate history. Deactivate it instead.');
    }
}
