<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;
use Illuminate\Support\Facades\DB;

/**
 * The three text fields on a line have three distinct jobs, and the API must
 * keep them distinguishable:
 *
 *   description             — the item's identity, only when no catalog item
 *                             supplies one (free-text line)
 *   notes                   — per-line commentary
 *   catalog_item.description— the catalog's standard product blurb
 *
 * Setup and request helpers live in MaterialRequestLineTestCase.
 */
class MaterialRequestItemDescriptionTest extends MaterialRequestLineTestCase
{
    public function test_catalog_line_exposes_the_catalog_description(): void
    {
        $item = CatalogItem::factory()->create([
            'name' => 'Electric Wires',
            'description' => 'Electric Wires cover all the wiring, copper and aluminium.',
        ]);

        $mrId = $this->createDraft();

        $this->addLine($mrId, [
            'catalog_item_id' => $item->id,
            'quantity' => 15,
            'notes' => 'For Electricity',
        ])
            ->assertStatus(201)
            // The line's own description stays null — correct on a catalog line,
            // it means "no free-text identity needed", not "missing data".
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.notes', 'For Electricity')
            ->assertJsonPath('data.catalog_item.name', 'Electric Wires')
            ->assertJsonPath('data.catalog_item.description', 'Electric Wires cover all the wiring, copper and aluminium.');
    }

    public function test_free_text_line_keeps_its_own_description_and_has_no_catalog_item(): void
    {
        $mrId = $this->createDraft();

        $this->addLine($mrId, [
            'trade_category_id' => $this->tradeCategoryId('Framing & Carpentry'),
            'unit_id' => $this->unitId('ea'),
            'description' => '2x 8ft pressure-treated 4x4',
            'quantity' => 2,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.description', '2x 8ft pressure-treated 4x4')
            ->assertJsonPath('data.catalog_item', null);
    }

    public function test_line_description_is_not_overwritten_by_the_catalog_description(): void
    {
        $item = CatalogItem::factory()->create([
            'name' => 'Electric Wires',
            'description' => 'Catalog blurb',
        ]);

        $mrId = $this->createDraft();

        // A catalog line MAY still carry its own description; the two must remain
        // separately readable rather than one clobbering the other.
        $this->addLine($mrId, [
            'catalog_item_id' => $item->id,
            'description' => 'Use the 2.5mm reel, not the 1.5mm',
            'quantity' => 5,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.description', 'Use the 2.5mm reel, not the 1.5mm')
            ->assertJsonPath('data.catalog_item.description', 'Catalog blurb');
    }

    public function test_catalog_description_appears_on_the_request_detail_payload(): void
    {
        $item = CatalogItem::factory()->create([
            'name' => 'Electric Breakers',
            'description' => 'Twenty amp, single pole.',
        ]);

        $mrId = $this->createDraft([
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 10]],
        ]);

        // Detail reads sit behind project.access, unlike the writes above.
        $this->joinProject();

        $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}")
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.catalog_item.description', 'Twenty amp, single pole.');
    }

    public function test_surfacing_the_catalog_description_adds_no_per_line_lookups(): void
    {
        $threeItems = CatalogItem::factory()->count(3)->create(['description' => 'Blurb']);
        $threeLineMr = $this->createDraft([
            'items' => $threeItems->map(fn ($i) => ['catalog_item_id' => $i->id, 'quantity' => 1])->all(),
        ]);

        $oneLineMr = $this->createDraft([
            'items' => [['catalog_item_id' => CatalogItem::factory()->create(['description' => 'Blurb'])->id, 'quantity' => 1]],
        ]);

        $this->joinProject();

        // Count only queries against catalog_items. Total query count is a poor
        // proxy here — the first request of a test pays cold-cache costs
        // (permission resolution etc.) that have nothing to do with this resource.
        $catalogQueries = function (int $mrId): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($this->foreman, 'api')
                ->getJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}")
                ->assertStatus(200);

            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return count(array_filter($log, fn ($q) => str_contains($q['query'], 'catalog_items')));
        };

        $catalogQueries($oneLineMr); // warm caches; result deliberately discarded

        // One eager-load query either way: three lines must not mean three lookups.
        $this->assertSame(1, $catalogQueries($oneLineMr));
        $this->assertSame(1, $catalogQueries($threeLineMr));
    }
}
