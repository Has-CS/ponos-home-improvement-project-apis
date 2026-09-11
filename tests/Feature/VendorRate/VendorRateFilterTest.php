<?php

namespace Tests\Feature\VendorRate;

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
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Filtering the vendor-rate ledger for planning and estimating.
 *
 * The ledger is append-only and effective-dated: one OPEN rate per
 * (vendor, catalog_item) — guaranteed by the vendor_rates_open_unique partial
 * index — with superseded rows retained as history.
 *
 * Two date questions matter and they are different:
 *   date_from/date_to  which prices MOVED in a window
 *   as_of              what was IN EFFECT on a day  (the estimating one)
 *
 * The subtle part under test is that `current_only` defaults to true and must
 * yield to both, or either returns a confidently wrong answer.
 *
 * This is the first test coverage this endpoint has had, so the first case is a
 * plain backward-compatibility guard.
 */
class VendorRateFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vendor $riverside;

    private Vendor $northgate;

    private CatalogItem $coupling;

    private CatalogItem $tile;

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

        $this->riverside = Vendor::create(['name' => 'Riverside Supply Co.', 'email' => 'p@r.test', 'is_active' => true]);
        $this->northgate = Vendor::create(['name' => 'Northgate Trading Ltd.', 'email' => 's@n.test', 'is_active' => true]);

        $plumbing = TradeCategory::where('name', 'Plumbing')->firstOrFail();
        $tiles = TradeCategory::where('name', 'Tiles')->firstOrFail();

        $this->coupling = CatalogItem::factory()->create([
            'name' => 'Zephyr coupling',
            'sku' => 'ZC-9001',
            'trade_category_id' => $plumbing->id,
        ]);

        $this->tile = CatalogItem::factory()->create([
            'name' => 'Porcelain floor tile',
            'sku' => 'PFT-600',
            'trade_category_id' => $tiles->id,
        ]);
    }

    /** A rate row, written directly so history can be built at chosen dates. */
    private function rate(
        Vendor $vendor,
        CatalogItem $item,
        string $amount,
        string $from,
        ?string $to = null,
    ): VendorRate {
        return VendorRate::create([
            'vendor_id' => $vendor->id,
            'catalog_item_id' => $item->id,
            'unit_id' => Unit::where('code', 'ea')->value('id'),
            'rate' => $amount,
            'currency' => 'USD',
            'effective_from' => $from,
            'effective_to' => $to,
            'entered_by' => $this->admin->id,
        ]);
    }

    /** @param array<string,mixed> $query */
    private function fetch(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/vendor-rates?'.http_build_query($query));
    }

    /** @return array<int,int> returned rate ids */
    private function ids(array $query = []): array
    {
        return array_column($this->fetch($query)->assertOk()->json('data.items'), 'id');
    }

    /**
     * Three rates for one vendor+item, only the last open:
     *   100.00  01 Aug -> 14 Aug
     *   200.00  15 Aug -> 31 Aug   <- in effect mid-August
     *   300.00  01 Sep -> open
     * Plus a second vendor with a single open rate on the same item.
     *
     * @return array{0:VendorRate,1:VendorRate,2:VendorRate,3:VendorRate}
     */
    private function buildHistory(): array
    {
        $aug1 = $this->rate($this->riverside, $this->coupling, '100.00', '2026-08-01', '2026-08-14');
        $aug15 = $this->rate($this->riverside, $this->coupling, '200.00', '2026-08-15', '2026-08-31');
        $sep1 = $this->rate($this->riverside, $this->coupling, '300.00', '2026-09-01', null);
        $other = $this->rate($this->northgate, $this->coupling, '275.00', '2026-07-01', null);

        return [$aug1, $aug15, $sep1, $other];
    }

    /* ---------------- backward compatibility ---------------- */

    /** The guard this endpoint never had: a bare request must behave as before. */
    public function test_with_no_filters_it_returns_current_rates_only_one_per_vendor(): void
    {
        [$aug1, $aug15, $sep1, $other] = $this->buildHistory();

        $ids = $this->ids();

        $this->assertContains($sep1->id, $ids);
        $this->assertContains($other->id, $ids);
        $this->assertNotContains($aug1->id, $ids);
        $this->assertNotContains($aug15->id, $ids);
        $this->assertCount(2, $ids, 'one row per vendor');
    }

    public function test_current_only_false_still_returns_the_full_history(): void
    {
        $this->buildHistory();

        $this->assertCount(4, $this->ids(['current_only' => 0]));
    }

    public function test_the_existing_vendor_and_item_filters_still_work(): void
    {
        [, , $sep1, $other] = $this->buildHistory();

        $this->assertSame([$sep1->id], $this->ids(['vendor_id' => $this->riverside->id]));
        $this->assertCount(2, $this->ids(['catalog_item_id' => $this->coupling->id]));
        $this->assertNotContains($other->id, $this->ids(['vendor_id' => $this->riverside->id]));
    }

    /* ---------------- as_of: the estimating question ---------------- */

    /**
     * Mid-August the answer is 200.00 — neither the current rate (300) nor the
     * first one (100), so a wrong implementation cannot pass by coincidence.
     */
    public function test_as_of_returns_the_rate_that_was_in_effect_on_that_date(): void
    {
        [$aug1, $aug15, $sep1] = $this->buildHistory();

        $ids = $this->ids(['as_of' => '2026-08-20', 'catalog_item_id' => $this->coupling->id]);

        $this->assertContains($aug15->id, $ids, 'the rate in effect on 20 Aug');
        $this->assertNotContains($aug1->id, $ids, 'expired before that date');
        $this->assertNotContains($sep1->id, $ids, 'had not started yet');
    }

    public function test_as_of_returns_one_row_per_vendor(): void
    {
        [, $aug15, , $other] = $this->buildHistory();

        $ids = $this->ids(['as_of' => '2026-08-20', 'catalog_item_id' => $this->coupling->id]);

        // Riverside's mid-August rate, and Northgate's (open since July).
        $this->assertEqualsCanonicalizing([$aug15->id, $other->id], $ids);
    }

    public function test_as_of_includes_a_still_open_rate_that_began_earlier(): void
    {
        [, , $sep1] = $this->buildHistory();

        $this->assertContains($sep1->id, $this->ids(['as_of' => '2026-09-15']));
    }

    public function test_as_of_before_any_rate_existed_returns_nothing(): void
    {
        $this->buildHistory();

        $this->assertSame([], $this->ids(['as_of' => '2026-06-01']));
    }

    /** as_of and current_only contradict each other — "current" is as_of=today. */
    public function test_as_of_cannot_be_combined_with_current_only(): void
    {
        $this->fetch(['as_of' => '2026-08-20', 'current_only' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['as_of']);
    }

    /* ---------------- date range: what moved ---------------- */

    /**
     * The assertion that fails if the current_only default is not suppressed:
     * both August rates are long superseded, and both must still be returned.
     */
    public function test_a_date_range_returns_rates_set_in_it_including_superseded_ones(): void
    {
        [$aug1, $aug15, $sep1, $other] = $this->buildHistory();

        $ids = $this->ids(['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);

        $this->assertContains($aug1->id, $ids);
        $this->assertContains($aug15->id, $ids);
        $this->assertNotContains($sep1->id, $ids, 'set in September');
        $this->assertNotContains($other->id, $ids, 'set in July');
    }

    /** An explicit current_only must still win over the suppressed default. */
    public function test_an_explicit_current_only_still_applies_alongside_a_date_range(): void
    {
        [$aug1, $aug15] = $this->buildHistory();

        $ids = $this->ids([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
            'current_only' => 1,
        ]);

        $this->assertNotContains($aug1->id, $ids);
        $this->assertNotContains($aug15->id, $ids);
        $this->assertSame([], $ids, 'neither August rate is still open');
    }

    public function test_an_inverted_date_range_is_rejected(): void
    {
        $this->fetch(['date_from' => '2026-08-31', 'date_to' => '2026-08-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    /* ---------------- search ---------------- */

    public function test_search_matches_item_name_sku_and_vendor_name(): void
    {
        [, , $sep1, $other] = $this->buildHistory();
        $tileRate = $this->rate($this->northgate, $this->tile, '55.00', '2026-08-01');

        $this->assertContains($sep1->id, $this->ids(['search' => 'Zephyr']), 'item name');
        $this->assertContains($sep1->id, $this->ids(['search' => 'ZC-9001']), 'item sku');

        $byVendor = $this->ids(['search' => 'Northgate']);
        $this->assertContains($other->id, $byVendor, 'vendor name');
        $this->assertContains($tileRate->id, $byVendor);

        $this->assertNotContains($sep1->id, $byVendor, "Riverside's rate must not match Northgate");
    }

    public function test_search_treats_like_metacharacters_literally(): void
    {
        $odd = CatalogItem::factory()->create(['name' => 'Rail_A bracket', 'sku' => 'RA-1']);
        $plain = CatalogItem::factory()->create(['name' => 'RailXA bracket', 'sku' => 'RX-1']);

        $oddRate = $this->rate($this->riverside, $odd, '10.00', '2026-08-01');
        $plainRate = $this->rate($this->riverside, $plain, '11.00', '2026-08-01');

        $ids = $this->ids(['search' => 'Rail_A']);

        $this->assertContains($oddRate->id, $ids);
        $this->assertNotContains($plainRate->id, $ids, 'the underscore must not act as a wildcard');
    }

    /**
     * The leak guard. `search` ORs across two subqueries and `vendor_id` is
     * chained after it — un-grouped, a matching item name would escape the
     * vendor filter and return another supplier's pricing.
     */
    public function test_search_does_not_escape_the_vendor_filter(): void
    {
        [, , $sep1, $other] = $this->buildHistory();

        $ids = $this->ids(['search' => 'Zephyr', 'vendor_id' => $this->riverside->id]);

        $this->assertSame([$sep1->id], $ids);
        $this->assertNotContains($other->id, $ids, "another vendor's rate for the same item must not leak");
    }

    /**
     * Same guard for the as_of OR branch, and the decoy has to be chosen with
     * care.
     *
     * Un-grouped, the clause collapses to
     *   (item = X AND effective_from <= d AND effective_to IS NULL) OR (effective_to >= d)
     * so the leaking branch is `effective_to >= d` — which only fires on a row
     * whose effective_to is NOT NULL and falls on or after the as-of date.
     *
     * An OPEN decoy (effective_to NULL) can never trip it, because NULL >= date
     * is not true — this test used one at first and passed against a
     * deliberately broken implementation. The decoy below is therefore a rate
     * on a DIFFERENT item, closed AFTER the as-of date.
     */
    public function test_as_of_does_not_escape_the_item_filter(): void
    {
        $this->buildHistory();

        $tileRate = $this->rate($this->northgate, $this->tile, '55.00', '2026-07-01', '2026-09-30');

        $ids = $this->ids(['as_of' => '2026-08-20', 'catalog_item_id' => $this->coupling->id]);

        $this->assertNotContains($tileRate->id, $ids, 'a different item must not leak through the OR');
    }

    /* ---------------- category and price band ---------------- */

    public function test_trade_category_filters_to_that_trades_items(): void
    {
        [, , $sep1] = $this->buildHistory();
        $tileRate = $this->rate($this->northgate, $this->tile, '55.00', '2026-08-01');

        $plumbing = TradeCategory::where('name', 'Plumbing')->firstOrFail();
        $ids = $this->ids(['trade_category_id' => $plumbing->id]);

        $this->assertContains($sep1->id, $ids);
        $this->assertNotContains($tileRate->id, $ids);
    }

    public function test_the_rate_band_filters_and_ands_with_the_vendor(): void
    {
        [, , $sep1, $other] = $this->buildHistory();

        $this->assertContains($sep1->id, $this->ids(['rate_min' => 280]));
        $this->assertNotContains($other->id, $this->ids(['rate_min' => 280]), '275 is below the floor');

        $this->assertContains($other->id, $this->ids(['rate_min' => 100, 'rate_max' => 280]));
        $this->assertNotContains($sep1->id, $this->ids(['rate_min' => 100, 'rate_max' => 280]));

        $this->assertSame(
            [$sep1->id],
            $this->ids(['rate_min' => 100, 'vendor_id' => $this->riverside->id]),
        );
    }

    public function test_an_inverted_rate_band_is_rejected(): void
    {
        $this->fetch(['rate_min' => 500, 'rate_max' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rate_max']);
    }

    /* ---------------- sorting ---------------- */

    public function test_it_sorts_by_rate_in_both_directions(): void
    {
        $this->buildHistory();

        $asc = $this->fetch(['current_only' => 0, 'sort_by' => 'rate', 'sort_dir' => 'asc'])
            ->assertOk()->json('data.items');
        $rates = array_map('floatval', array_column($asc, 'rate'));
        $sorted = $rates;
        sort($sorted);
        $this->assertSame($sorted, $rates);

        $desc = $this->fetch(['current_only' => 0, 'sort_by' => 'rate', 'sort_dir' => 'desc'])
            ->assertOk()->json('data.items');
        $this->assertSame(
            array_reverse($sorted),
            array_map('floatval', array_column($desc, 'rate')),
        );
    }

    public function test_the_default_ordering_is_unchanged(): void
    {
        $this->buildHistory();

        $dates = array_column(
            $this->fetch(['current_only' => 0])->assertOk()->json('data.items'),
            'effective_from',
        );
        $sorted = $dates;
        rsort($sorted);

        $this->assertSame($sorted, $dates, 'newest effective_from first');
    }

    public function test_an_unwhitelisted_sort_column_is_rejected(): void
    {
        $this->fetch(['sort_by' => 'entered_by'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);

        $this->fetch(['sort_dir' => 'sideways'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_dir']);
    }

    /**
     * Rows sharing a sort value have no inherent order; the id tiebreaker is
     * what stops pagination repeating or skipping them.
     */
    public function test_rows_with_an_equal_sort_value_paginate_without_repeating(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $item = CatalogItem::factory()->create(['name' => "Same-date item {$i}", 'sku' => "SD-{$i}"]);
            $this->rate($this->riverside, $item, '50.00', '2026-08-01');
        }

        $page1 = $this->ids(['per_page' => 3, 'page' => 1, 'sort_by' => 'rate']);
        $page2 = $this->ids(['per_page' => 3, 'page' => 2, 'sort_by' => 'rate']);

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertSame([], array_intersect($page1, $page2), 'pages must not overlap');
    }

    /* ---------------- combined ---------------- */

    public function test_filters_compose(): void
    {
        [, $aug15] = $this->buildHistory();
        $plumbing = TradeCategory::where('name', 'Plumbing')->firstOrFail();

        $ids = $this->ids([
            'as_of' => '2026-08-20',
            'vendor_id' => $this->riverside->id,
            'trade_category_id' => $plumbing->id,
            'rate_min' => 150,
            'search' => 'Zephyr',
        ]);

        $this->assertSame([$aug15->id], $ids);
    }
}
