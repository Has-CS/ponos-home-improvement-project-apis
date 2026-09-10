<?php

namespace Tests\Feature\CatalogItem;

use App\Models\CatalogItem;
use App\Models\Project;
use App\Models\TradeCategory;
use App\Services\CatalogItem\CatalogItemService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin list and the type-ahead picker must match on the SAME fields.
 *
 * They had drifted: the list matched name+sku, the picker matched
 * name+description+trade category. A buyer holding a supplier SKU got nothing
 * from the picker while the list found the item instantly. Both now share one
 * predicate, and these tests assert parity through both entry points so they
 * cannot drift again.
 *
 * Exercised through the service rather than HTTP: the two paths have different
 * routes, gates and response shapes, and what is under test is the query, not
 * the plumbing. CatalogItemSearchTest already covers the picker over HTTP.
 */
class CatalogItemSearchFieldsTest extends TestCase
{
    use RefreshDatabase;

    private CatalogItemService $service;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->service = app(CatalogItemService::class);
        $this->project = Project::factory()->create();
    }

    /** @return array<int,int> ids the PICKER returns */
    private function picker(string $term, array $extra = []): array
    {
        return $this->service->search($this->project, ['q' => $term, ...$extra])['items']
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int,int> ids the ADMIN LIST returns */
    private function list(string $term, array $extra = []): array
    {
        return collect($this->service->paginate(['search' => $term, ...$extra])->items())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /* ---------------- the reported bug ---------------- */

    public function test_the_picker_finds_an_item_by_its_sku(): void
    {
        $item = CatalogItem::factory()->create([
            'name' => 'Electric Wires',
            'sku' => 'EW-E1-64',
        ]);

        $this->assertContains($item->id, $this->picker('EW-E1-64'), 'exact SKU should match');
        $this->assertContains($item->id, $this->picker('EW-E1'), 'partial SKU should match');
    }

    public function test_the_admin_list_finds_an_item_by_its_description(): void
    {
        $item = CatalogItem::factory()->create([
            'name' => 'Flush door',
            'description' => 'Interwood Series 250, rectified edge',
        ]);

        $this->assertContains($item->id, $this->list('Interwood'));
    }

    /* ---------------- parity ---------------- */

    /**
     * The same item, found by every supported field, through BOTH entry points.
     * This is the assertion that fails the moment one path gains or loses a
     * field the other does not.
     */
    public function test_both_paths_match_on_name_sku_description_and_trade_category(): void
    {
        $category = TradeCategory::query()->where('name', 'Plumbing')->firstOrFail();

        $item = CatalogItem::factory()->create([
            'trade_category_id' => $category->id,
            'name' => 'Zephyr coupling',
            'sku' => 'ZC-9001',
            'description' => 'Rectified brass fitting',
        ]);

        foreach (['Zephyr', 'ZC-9001', 'Rectified', 'Plumbing'] as $term) {
            $this->assertContains($item->id, $this->picker($term), "picker should match on '{$term}'");
            $this->assertContains($item->id, $this->list($term), "admin list should match on '{$term}'");
        }
    }

    public function test_a_term_matching_nothing_returns_nothing_in_both(): void
    {
        CatalogItem::factory()->create(['name' => 'Zephyr coupling', 'sku' => 'ZC-9001']);

        $this->assertSame([], $this->picker('nothingmatchesthis'));
        $this->assertSame([], $this->list('nothingmatchesthis'));
    }

    /* ---------------- LIKE metacharacters are literal ---------------- */

    public function test_a_percent_sign_is_matched_literally_in_both_paths(): void
    {
        $literal = CatalogItem::factory()->create(['name' => 'Slope 50% grade marker', 'sku' => 'SL-50']);
        $other = CatalogItem::factory()->create(['name' => 'Plain marker', 'sku' => 'PM-01']);

        // '50%' must find the item literally named "50%", not act as a wildcard.
        $this->assertContains($literal->id, $this->picker('50%'));
        $this->assertNotContains($other->id, $this->picker('50%'));

        $this->assertContains($literal->id, $this->list('50%'));
        $this->assertNotContains($other->id, $this->list('50%'));
    }

    public function test_a_bare_wildcard_does_not_return_everything(): void
    {
        CatalogItem::factory()->create(['name' => 'Plain marker', 'sku' => 'PM-01']);

        $this->assertSame([], $this->picker('%'), 'a bare % must not behave as match-all');
        $this->assertSame([], $this->list('%'), 'a bare % must not behave as match-all');
    }

    public function test_an_underscore_is_matched_literally(): void
    {
        $withUnderscore = CatalogItem::factory()->create(['name' => 'Rail_A bracket', 'sku' => 'RA-1']);
        $withoutIt = CatalogItem::factory()->create(['name' => 'RailXA bracket', 'sku' => 'RX-1']);

        $this->assertContains($withUnderscore->id, $this->picker('Rail_A'));
        $this->assertNotContains($withoutIt->id, $this->picker('Rail_A'));
    }

    /* ---------------- the search term must not escape other constraints ---------------- */

    public function test_the_search_term_still_ands_with_a_trade_category_filter(): void
    {
        $plumbing = TradeCategory::query()->where('name', 'Plumbing')->firstOrFail();
        $doors = TradeCategory::query()->where('name', 'Doors')->firstOrFail();

        $wanted = CatalogItem::factory()->create(['trade_category_id' => $plumbing->id, 'name' => 'Zephyr coupling', 'sku' => 'ZC-1']);
        $wrongCategory = CatalogItem::factory()->create(['trade_category_id' => $doors->id, 'name' => 'Zephyr hinge', 'sku' => 'ZH-1']);

        $picked = $this->picker('Zephyr', ['trade_category_id' => $plumbing->id]);
        $this->assertContains($wanted->id, $picked);
        $this->assertNotContains($wrongCategory->id, $picked, 'the name match must not escape the category filter');

        $listed = $this->list('Zephyr', ['trade_category_id' => $plumbing->id]);
        $this->assertContains($wanted->id, $listed);
        $this->assertNotContains($wrongCategory->id, $listed, 'the name match must not escape the category filter');
    }

    /**
     * The important one. The picker's project scope is chained AFTER the match,
     * so if the predicates were ever un-grouped, AND binding tighter than OR
     * would let a name match leak ANOTHER project's custom item into this
     * project's picker.
     */
    public function test_a_matching_term_cannot_leak_another_projects_custom_item(): void
    {
        $otherProject = Project::factory()->create();

        $mine = CatalogItem::factory()->create(['name' => 'Zephyr coupling', 'sku' => 'ZC-1', 'project_id' => null]);
        $theirs = CatalogItem::factory()->create([
            'name' => 'Zephyr coupling (bespoke)',
            'sku' => 'ZC-2',
            'project_id' => $otherProject->id,
            'is_custom' => true,
        ]);

        $found = $this->picker('Zephyr');

        $this->assertContains($mine->id, $found);
        $this->assertNotContains($theirs->id, $found, "another project's custom item must never surface here");

        // Same guard when the term matches only by SKU — the newly added field.
        $this->assertNotContains($theirs->id, $this->picker('ZC-2'));
    }
}
