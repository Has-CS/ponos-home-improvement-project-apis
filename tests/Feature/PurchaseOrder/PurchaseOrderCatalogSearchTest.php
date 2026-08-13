<?php

namespace Tests\Feature\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Rbac\RoleAssignmentService;
use App\Services\VendorRate\VendorRateService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The catalog type-ahead for purchase-order lines.
 *
 * Shares CatalogItemService::search() with the material-request picker; what is
 * specific here is the vendor's current rate on each row, and the tighter
 * permission gate that lets it carry pricing at all.
 */
class PurchaseOrderCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $procurement;

    private Project $project;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->procurement = $this->userWithRole('Procurement');
        $this->project = Project::factory()->create();
        $this->vendor = Vendor::create(['name' => 'Acme Supply']);
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
        return $this->actingAs($as ?? $this->procurement, 'api')->getJson(
            '/api/v1/purchase-orders/catalog-items/search?'.http_build_query([
                'project_id' => $this->project->id,
                ...$params,
            ]),
        );
    }

    /**
     * $effectiveFrom must be given when superseding an existing rate: addRate()
     * closes the incumbent at effective_from minus one day, so two rates dated
     * the same day trip the vendor_rates_dates_check constraint.
     */
    private function rateFor(CatalogItem $item, string $rate, ?Vendor $vendor = null, ?string $effectiveFrom = null): void
    {
        app(VendorRateService::class)->addRate(array_filter([
            'vendor_id' => ($vendor ?? $this->vendor)->id,
            'catalog_item_id' => $item->id,
            'unit_id' => Unit::where('code', 'ea')->value('id'),
            'rate' => $rate,
            'effective_from' => $effectiveFrom,
        ]), $this->procurement->id);
    }

    /* ---------------- authorization ---------------- */

    public function test_procurement_can_search(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A']);

        $this->search(['q' => 'break'])->assertStatus(200);
    }

    public function test_admin_can_search(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A']);

        $this->search(['q' => 'break'], $this->userWithRole('Admin'))->assertStatus(200);
    }

    /** The pricing boundary: a field role must not reach a priced endpoint. */
    public function test_foreman_is_denied(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A']);

        $this->search(['q' => 'break'], $this->userWithRole('Foreman'))->assertStatus(403);
    }

    public function test_user_with_no_roles_is_denied(): void
    {
        $this->search(['q' => 'break'], User::factory()->create())->assertStatus(403);
    }

    /* ---------------- pricing ---------------- */

    public function test_a_priced_item_carries_the_vendors_current_rate(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Breaker 20A']);
        $this->rateFor($item, '12.50');

        $row = $this->search(['q' => 'break', 'vendor_id' => $this->vendor->id])
            ->assertStatus(200)
            ->json('data.items.0');

        $this->assertTrue($row['has_rate']);
        $this->assertSame('12.50', (string) $row['current_rate']['rate']);
        $this->assertSame('USD', $row['current_rate']['currency']);
        $this->assertNotNull($row['current_rate']['vendor_rate_id']);
    }

    /**
     * Unpriced items are returned and flagged, never hidden — buying off the
     * rate card with an explicit unit_price is a supported path in
     * persistLine(), and hiding these items would block it.
     */
    public function test_an_unpriced_item_is_returned_and_flagged(): void
    {
        CatalogItem::factory()->create(['name' => 'Breaker 20A']);

        $row = $this->search(['q' => 'break', 'vendor_id' => $this->vendor->id])
            ->assertStatus(200)
            ->json('data.items.0');

        $this->assertFalse($row['has_rate']);
        $this->assertNull($row['current_rate']);
        $this->assertSame('Breaker 20A', $row['name']);
    }

    public function test_another_vendors_rate_never_leaks(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Breaker 20A']);
        $other = Vendor::create(['name' => 'Other Supply']);
        $this->rateFor($item, '99.99', $other);

        $row = $this->search(['q' => 'break', 'vendor_id' => $this->vendor->id])
            ->assertStatus(200)
            ->json('data.items.0');

        $this->assertFalse($row['has_rate']);
        $this->assertNull($row['current_rate']);
    }

    /** Only the OPEN rate counts; superseded history must not surface. */
    public function test_a_superseded_rate_is_not_returned(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Breaker 20A']);
        $this->rateFor($item, '10.00');
        $this->rateFor($item, '14.00', effectiveFrom: now()->addDay()->toDateString()); // closes the first

        $row = $this->search(['q' => 'break', 'vendor_id' => $this->vendor->id])
            ->assertStatus(200)
            ->json('data.items.0');

        $this->assertSame('14.00', (string) $row['current_rate']['rate']);
    }

    public function test_without_a_vendor_no_pricing_is_returned(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Breaker 20A']);
        $this->rateFor($item, '12.50');

        $row = $this->search(['q' => 'break'])->assertStatus(200)->json('data.items.0');

        $this->assertFalse($row['has_rate']);
        $this->assertNull($row['current_rate']);
        $this->assertSame('Breaker 20A', $row['name']);
    }

    /* ---------------- shared behaviour still applies ---------------- */

    public function test_project_scoping_is_preserved(): void
    {
        CatalogItem::factory()->create(['name' => 'Global breaker', 'project_id' => null]);
        CatalogItem::factory()->create(['name' => 'Mine breaker', 'project_id' => $this->project->id]);
        CatalogItem::factory()->create(['name' => 'Foreign breaker', 'project_id' => Project::factory()->create()->id]);

        $names = $this->search(['q' => 'breaker'])->assertStatus(200)->json('data.items.*.name');

        $this->assertContains('Global breaker', $names);
        $this->assertContains('Mine breaker', $names);
        $this->assertNotContains('Foreign breaker', $names);
    }

    public function test_shared_rules_are_enforced(): void
    {
        $this->search(['q' => 'b'])->assertStatus(422);            // min:2
        $this->search(['q' => 'break', 'limit' => 99])->assertStatus(422); // max:50
    }

    public function test_project_id_is_required(): void
    {
        $this->actingAs($this->procurement, 'api')
            ->getJson('/api/v1/purchase-orders/catalog-items/search?q=break')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_the_result_is_capped_and_flags_has_more(): void
    {
        CatalogItem::factory()->count(5)->create(['name' => 'Breaker unit']);

        $response = $this->search(['q' => 'breaker', 'limit' => 2])->assertStatus(200);

        $response->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.has_more', true)
            ->assertJsonPath('data.limit', 2);
    }

    /**
     * The rates must load in ONE constrained eager-load, not per row. This is
     * the thing that would degrade silently and invisibly as the catalog grows,
     * so it is pinned rather than left to review.
     */
    public function test_rates_do_not_trigger_a_query_per_row(): void
    {
        foreach (range(1, 10) as $i) {
            $this->rateFor(CatalogItem::factory()->create(['name' => "Breaker {$i}A"]), '10.00');
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->search(['q' => 'breaker', 'vendor_id' => $this->vendor->id, 'limit' => 10])
            ->assertStatus(200)
            ->assertJsonCount(10, 'data.items');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Items + the three lookup eager-loads + rates + auth/permission reads.
        // A per-row lookup would add 10 on top of this and blow the ceiling.
        $this->assertLessThan(20, $count, "Expected a bounded query count, got {$count}.");
    }
}
