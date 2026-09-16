<?php

namespace Tests\Feature\Vendor;

use App\Models\CatalogItem;
use App\Models\TradeCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRate;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Classifying vendors by the trades they supply.
 *
 * Vendors reuse the existing trade_categories taxonomy — the one catalog items
 * are already classified by, with its own CRUD and seed data — through a
 * many-to-many pivot, because suppliers rarely serve a single trade.
 *
 * The vendor endpoints had no tests before this file, so it doubles as their
 * first regression guard: every case that omits the new field asserts the
 * endpoint still behaves as it always did.
 */
class VendorTradeCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TradeCategory $doors;

    private TradeCategory $carpentry;

    private TradeCategory $plumbing;

    private TradeCategory $electrical;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $this->admin,
            Role::where('name', 'Admin')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        $this->doors = TradeCategory::where('name', 'Doors')->firstOrFail();
        $this->carpentry = TradeCategory::where('name', 'Framing & Carpentry')->firstOrFail();
        $this->plumbing = TradeCategory::where('name', 'Plumbing')->firstOrFail();
        $this->electrical = TradeCategory::where('name', 'Electrical')->firstOrFail();
    }

    /* ---------------- helpers ---------------- */

    /** A vendor built directly, for fixtures, with the given trades attached. */
    private function vendor(string $name, array $trades = [], bool $active = true): Vendor
    {
        $vendor = Vendor::create(['name' => $name, 'is_active' => $active]);
        $vendor->tradeCategories()->sync(array_map(fn (TradeCategory $t) => $t->id, $trades));

        return $vendor;
    }

    /** @param array<string,mixed> $payload */
    private function createVendor(array $payload): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->postJson('/api/v1/vendors', $payload);
    }

    /** @param array<string,mixed> $payload */
    private function updateVendor(Vendor $vendor, array $payload): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->patchJson("/api/v1/vendors/{$vendor->id}", $payload);
    }

    /** @param array<string,mixed> $query */
    private function list(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/vendors?'.http_build_query($query));
    }

    /** @return array<int,int> vendor ids returned by the list */
    private function listedIds(array $query = []): array
    {
        return array_column($this->list($query)->assertOk()->json('data.items'), 'id');
    }

    /** @return array<int,int> the trade ids actually stored for a vendor, sorted */
    private function storedTradeIds(Vendor|int $vendor): array
    {
        $id = $vendor instanceof Vendor ? $vendor->id : $vendor;

        $ids = DB::table('trade_category_vendor')->where('vendor_id', $id)
            ->pluck('trade_category_id')->map(fn ($v) => (int) $v)->all();
        sort($ids);

        return $ids;
    }

    /** @param array<int,TradeCategory> $trades */
    private function sortedIds(array $trades): array
    {
        $ids = array_map(fn (TradeCategory $t) => $t->id, $trades);
        sort($ids);

        return $ids;
    }

    private function assertValidationErrorOn(TestResponse $response, string $field): void
    {
        $response->assertStatus(422);

        $keys = array_keys($response->json('errors') ?? []);
        $this->assertTrue(
            collect($keys)->contains(fn (string $k) => $k === $field || str_starts_with($k, "{$field}.")),
            "Expected a validation error on {$field}; got: ".implode(', ', $keys),
        );
    }

    /* ---------------- assigning trades ---------------- */

    public function test_a_vendor_can_be_created_with_trades(): void
    {
        $response = $this->createVendor([
            'name' => 'Crown Doors & Woodworks',
            'trade_category_ids' => [$this->doors->id, $this->carpentry->id],
        ])->assertStatus(201);

        $this->assertSame(
            $this->sortedIds([$this->doors, $this->carpentry]),
            $this->storedTradeIds($response->json('data.id')),
        );

        // Returned in sort_order: Doors (1) before Framing & Carpentry (12).
        $this->assertSame(
            [
                ['id' => $this->doors->id, 'name' => 'Doors'],
                ['id' => $this->carpentry->id, 'name' => 'Framing & Carpentry'],
            ],
            $response->json('data.trade_categories'),
        );
    }

    /** Backward compatibility: omitting the new field changes nothing. */
    public function test_a_vendor_created_without_trades_behaves_exactly_as_before(): void
    {
        $response = $this->createVendor([
            'name' => 'Riverside Supply Co.',
            'email' => 'sales@riverside.test',
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Riverside Supply Co.')
            ->assertJsonPath('data.email', 'sales@riverside.test')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.trade_categories', []);

        $this->assertSame([], $this->storedTradeIds($response->json('data.id')));
    }

    public function test_updating_with_trades_replaces_the_whole_set(): void
    {
        $vendor = $this->vendor('Mixed Supplies', [$this->doors]);

        $this->updateVendor($vendor, [
            'trade_category_ids' => [$this->plumbing->id, $this->electrical->id],
        ])->assertOk();

        $this->assertSame($this->sortedIds([$this->plumbing, $this->electrical]), $this->storedTradeIds($vendor));
    }

    public function test_updating_without_the_key_leaves_trades_untouched(): void
    {
        $vendor = $this->vendor('Crown Doors', [$this->doors, $this->carpentry]);

        $this->updateVendor($vendor, ['phone' => '(203) 555-0100'])
            ->assertOk()
            ->assertJsonPath('data.phone', '(203) 555-0100');

        $this->assertSame($this->sortedIds([$this->doors, $this->carpentry]), $this->storedTradeIds($vendor));
    }

    public function test_updating_with_an_empty_array_clears_trades(): void
    {
        $vendor = $this->vendor('Crown Doors', [$this->doors, $this->carpentry]);

        $this->updateVendor($vendor, ['trade_category_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.trade_categories', []);

        $this->assertSame([], $this->storedTradeIds($vendor));
    }

    /* ---------------- validation ---------------- */

    public function test_an_unknown_trade_is_rejected_and_nothing_is_written(): void
    {
        $this->assertValidationErrorOn(
            $this->createVendor(['name' => 'Bad Vendor', 'trade_category_ids' => [999999]]),
            'trade_category_ids',
        );

        $this->assertSame(0, Vendor::count());
    }

    public function test_a_soft_deleted_trade_cannot_be_assigned(): void
    {
        $retired = TradeCategory::create(['name' => 'Retired Trade']);
        $retired->delete();

        $this->assertValidationErrorOn(
            $this->createVendor(['name' => 'Bad Vendor', 'trade_category_ids' => [$retired->id]]),
            'trade_category_ids',
        );

        $this->assertSame(0, Vendor::count());
    }

    public function test_duplicate_trades_are_rejected(): void
    {
        $this->assertValidationErrorOn(
            $this->createVendor([
                'name' => 'Bad Vendor',
                'trade_category_ids' => [$this->doors->id, $this->doors->id],
            ]),
            'trade_category_ids',
        );

        $this->assertSame(0, Vendor::count());
    }

    public function test_a_non_array_value_is_rejected(): void
    {
        $this->assertValidationErrorOn(
            $this->createVendor(['name' => 'Bad Vendor', 'trade_category_ids' => 'doors']),
            'trade_category_ids',
        );
    }

    public function test_an_invalid_update_leaves_the_existing_trades_alone(): void
    {
        $vendor = $this->vendor('Crown Doors', [$this->doors]);

        $this->assertValidationErrorOn(
            $this->updateVendor($vendor, ['trade_category_ids' => [999999]]),
            'trade_category_ids',
        );

        $this->assertSame([$this->doors->id], $this->storedTradeIds($vendor));
    }

    /* ---------------- filtering ---------------- */

    public function test_filtering_by_one_trade(): void
    {
        $doorVendor = $this->vendor('Crown Doors', [$this->doors]);
        $plumbVendor = $this->vendor('Royal Sanitary', [$this->plumbing]);
        $untagged = $this->vendor('Unclassified Co.');

        $ids = $this->listedIds(['trade_category_ids' => [$this->doors->id]]);

        $this->assertSame([$doorVendor->id], $ids);
        $this->assertNotContains($plumbVendor->id, $ids);
        $this->assertNotContains($untagged->id, $ids);
    }

    public function test_filtering_by_several_trades_matches_any_of_them(): void
    {
        $doorVendor = $this->vendor('Crown Doors', [$this->doors]);
        $plumbVendor = $this->vendor('Royal Sanitary', [$this->plumbing]);
        $electricVendor = $this->vendor('202 Electrical', [$this->electrical]);

        $ids = $this->listedIds(['trade_category_ids' => [$this->doors->id, $this->plumbing->id]]);

        $this->assertEqualsCanonicalizing([$doorVendor->id, $plumbVendor->id], $ids);
        $this->assertNotContains($electricVendor->id, $ids);
    }

    /**
     * The design choice that matters most. A vendor serving BOTH requested
     * trades must come back once — an EXISTS subquery (whereHas) guarantees
     * that, where a join would return it once per matching trade and inflate
     * the pagination total.
     */
    public function test_a_vendor_matching_two_requested_trades_appears_once(): void
    {
        $both = $this->vendor('Doors & Plumbing Ltd.', [$this->doors, $this->plumbing]);
        $single = $this->vendor('Crown Doors', [$this->doors]);

        $response = $this->list(['trade_category_ids' => [$this->doors->id, $this->plumbing->id]])->assertOk();
        $ids = array_column($response->json('data.items'), 'id');

        $this->assertSame(1, array_count_values($ids)[$both->id] ?? 0, 'the dual-trade vendor must not be duplicated');
        $this->assertEqualsCanonicalizing([$both->id, $single->id], $ids);
        $this->assertSame(2, $response->json('data.pagination.total'), 'the total must count vendors, not matches');
    }

    public function test_a_single_scalar_trade_id_is_accepted(): void
    {
        $doorVendor = $this->vendor('Crown Doors', [$this->doors]);
        $this->vendor('Royal Sanitary', [$this->plumbing]);

        // No brackets — a plain ?trade_category_ids=<id>.
        $this->assertSame([$doorVendor->id], $this->listedIds(['trade_category_ids' => $this->doors->id]));
    }

    public function test_the_trade_filter_ands_with_search_and_is_active(): void
    {
        $wanted = $this->vendor('Crown Doors', [$this->doors]);
        $wrongTrade = $this->vendor('Crown Plumbing', [$this->plumbing]);
        $inactive = $this->vendor('Crown Oak Doors', [$this->doors], active: false);
        $wrongName = $this->vendor('Summit Doors', [$this->doors]);

        $ids = $this->listedIds([
            'trade_category_ids' => [$this->doors->id],
            'search' => 'Crown',
            'is_active' => 1,
        ]);

        $this->assertSame([$wanted->id], $ids);
        $this->assertNotContains($wrongTrade->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($wrongName->id, $ids);
    }

    public function test_without_a_trade_filter_every_vendor_is_listed(): void
    {
        $tagged = $this->vendor('Crown Doors', [$this->doors]);
        $untagged = $this->vendor('Unclassified Co.');

        $this->assertEqualsCanonicalizing([$tagged->id, $untagged->id], $this->listedIds());
    }

    /* ---------------- responses ---------------- */

    public function test_the_list_and_the_detail_both_carry_trade_categories(): void
    {
        $vendor = $this->vendor('Crown Doors', [$this->carpentry, $this->doors]);
        $expected = [
            ['id' => $this->doors->id, 'name' => 'Doors'],
            ['id' => $this->carpentry->id, 'name' => 'Framing & Carpentry'],
        ];

        $listed = collect($this->list()->assertOk()->json('data.items'))->firstWhere('id', $vendor->id);
        $this->assertSame($expected, $listed['trade_categories']);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/vendors/{$vendor->id}")
            ->assertOk()
            ->assertJsonPath('data.trade_categories', $expected);
    }

    /**
     * Trades must be eager-loaded for the whole page, not fetched per vendor.
     * A warm-up request is discarded first: the first request of a process
     * pays a cold Spatie permission cache and reads artificially high.
     */
    public function test_the_list_does_not_n_plus_one_on_trades(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->list()->assertOk();

            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->vendor('Vendor 1', [$this->doors, $this->plumbing]);
        $this->vendor('Vendor 2', [$this->electrical]);

        $count(); // discarded warm-up
        $withTwo = $count();

        foreach (range(3, 8) as $i) {
            $this->vendor("Vendor {$i}", [$this->doors, $this->carpentry]);
        }

        $withEight = $count();

        $this->assertSame(
            $withTwo,
            $withEight,
            "Query count moved from {$withTwo} (2 vendors) to {$withEight} (8 vendors) — trades are loaded per row.",
        );
    }

    /* ---------------- deleting a trade ---------------- */

    public function test_a_trade_assigned_to_a_vendor_cannot_be_deleted(): void
    {
        $this->vendor('202 Electrical', [$this->electrical]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/trade-categories/{$this->electrical->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete: this trade category is still assigned to vendors.');

        $this->assertNotSoftDeleted($this->electrical);
    }

    public function test_once_detached_the_trade_can_be_deleted(): void
    {
        $vendor = $this->vendor('202 Electrical', [$this->electrical]);

        $this->updateVendor($vendor, ['trade_category_ids' => []])->assertOk();

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/trade-categories/{$this->electrical->id}")
            ->assertOk();

        $this->assertSoftDeleted($this->electrical);
    }

    /** A vendor that has itself been deleted no longer holds its trades hostage. */
    public function test_a_trade_used_only_by_a_deleted_vendor_can_be_deleted(): void
    {
        $vendor = $this->vendor('Defunct Electrical', [$this->electrical]);

        $this->actingAs($this->admin, 'api')->deleteJson("/api/v1/vendors/{$vendor->id}")->assertOk();

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/trade-categories/{$this->electrical->id}")
            ->assertOk();

        $this->assertSoftDeleted($this->electrical);
    }

    /* ---------------- the taxonomy is dynamic ---------------- */

    /** A trade added at runtime through the EXISTING CRUD is usable at once. */
    public function test_a_trade_created_through_the_existing_crud_is_immediately_usable(): void
    {
        $newTradeId = (int) $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/trade-categories', ['name' => 'Glass & Glazing'])
            ->assertSuccessful()
            ->json('data.id');

        $vendorId = (int) $this->createVendor([
            'name' => 'Clearview Glass',
            'trade_category_ids' => [$newTradeId],
        ])->assertStatus(201)->json('data.id');

        $this->assertSame([$vendorId], $this->listedIds(['trade_category_ids' => [$newTradeId]]));
    }

    /* ---------------- existing vendor behaviour intact ---------------- */

    public function test_the_status_endpoint_still_works_and_returns_trades(): void
    {
        $vendor = $this->vendor('Crown Doors', [$this->doors]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/vendors/{$vendor->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.trade_categories.0.id', $this->doors->id);

        $this->assertSame([$this->doors->id], $this->storedTradeIds($vendor), 'toggling status must not touch trades');
    }

    public function test_the_rate_history_delete_guard_is_unchanged(): void
    {
        $vendor = $this->vendor('Riverside Supply Co.', [$this->plumbing]);

        VendorRate::create([
            'vendor_id' => $vendor->id,
            'catalog_item_id' => CatalogItem::factory()->create()->id,
            'unit_id' => Unit::where('code', 'ea')->value('id'),
            'rate' => '12.50',
            'currency' => 'USD',
            'effective_from' => now()->subDay()->toDateString(),
            'entered_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/vendors/{$vendor->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete a vendor that has rate history.');
    }
}
