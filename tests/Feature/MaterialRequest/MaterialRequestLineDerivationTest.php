<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;
use App\Models\Urgency;

/**
 * Covers the derive-or-require rule for material-request lines:
 *
 *   catalog line     → trade_category_id DERIVED from the item (client value
 *                      ignored); unit_id defaults to the item's default_unit_id
 *   free-text line   → both are REQUIRED from the caller, since there is
 *                      nothing to derive them from
 *
 * Setup and request helpers live in MaterialRequestLineTestCase.
 */
class MaterialRequestLineDerivationTest extends MaterialRequestLineTestCase
{
    /* ---------------- derivation: adding a catalog line ---------------- */

    public function test_catalog_line_derives_trade_category_from_the_item(): void
    {
        $item = CatalogItem::factory()->tradeCategory('Plumbing')->create();
        $mrId = $this->createDraft();

        $response = $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $item->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.trade_category.id', $item->trade_category_id);

        $this->assertDatabaseHas('material_request_items', [
            'material_request_id' => $mrId,
            'catalog_item_id' => $item->id,
            'trade_category_id' => $item->trade_category_id,
        ]);
    }

    public function test_catalog_line_ignores_a_conflicting_client_trade_category(): void
    {
        $item = CatalogItem::factory()->tradeCategory('Doors')->create();
        $mrId = $this->createDraft();

        $response = $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $item->id,
            'trade_category_id' => $this->tradeCategoryId('Plumbing'), // deliberately wrong
            'quantity' => 2,
        ]);

        $response->assertStatus(201);

        // The server's value wins — a Doors item can never be filed under Plumbing.
        $this->assertDatabaseHas('material_request_items', [
            'material_request_id' => $mrId,
            'trade_category_id' => $this->tradeCategoryId('Doors'),
        ]);
        $this->assertDatabaseMissing('material_request_items', [
            'material_request_id' => $mrId,
            'trade_category_id' => $this->tradeCategoryId('Plumbing'),
        ]);
    }

    public function test_catalog_line_defaults_unit_from_the_item(): void
    {
        $item = CatalogItem::factory()->defaultUnit('sf')->create();
        $mrId = $this->createDraft();

        $response = $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $item->id,
            'quantity' => 12,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.unit.code', 'sf');

        $this->assertDatabaseHas('material_request_items', [
            'material_request_id' => $mrId,
            'unit_id' => $this->unitId('sf'),
        ]);
    }

    public function test_catalog_line_keeps_an_explicit_unit(): void
    {
        $item = CatalogItem::factory()->defaultUnit('sf')->create();
        $mrId = $this->createDraft();

        $response = $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $item->id,
            'unit_id' => $this->unitId('box'),
            'quantity' => 3,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.unit.code', 'box');

        $this->assertDatabaseHas('material_request_items', [
            'material_request_id' => $mrId,
            'unit_id' => $this->unitId('box'),
        ]);
    }

    /* ---------------- free-text lines ---------------- */

    public function test_free_text_line_requires_a_trade_category(): void
    {
        $mrId = $this->createDraft();

        $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'unit_id' => $this->unitId('ea'),
            'description' => '2x 8ft pressure-treated 4x4',
            'quantity' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['trade_category_id']);
    }

    public function test_free_text_line_requires_a_unit(): void
    {
        $mrId = $this->createDraft();

        $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'trade_category_id' => $this->tradeCategoryId('Framing & Carpentry'),
            'description' => '2x 8ft pressure-treated 4x4',
            'quantity' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['unit_id']);
    }

    public function test_free_text_line_stores_the_values_it_was_given(): void
    {
        $mrId = $this->createDraft();

        $response = $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'trade_category_id' => $this->tradeCategoryId('Framing & Carpentry'),
            'unit_id' => $this->unitId('ea'),
            'description' => '2x 8ft pressure-treated 4x4',
            'quantity' => 2,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('material_request_items', [
            'material_request_id' => $mrId,
            'catalog_item_id' => null,
            'trade_category_id' => $this->tradeCategoryId('Framing & Carpentry'),
            'unit_id' => $this->unitId('ea'),
        ]);
    }

    /* ---------------- nested items[] on create ---------------- */

    public function test_nested_items_derive_per_line_independently(): void
    {
        $item = CatalogItem::factory()->tradeCategory('Tiles')->defaultUnit('sf')->create();

        $mrId = $this->createDraft([
            'items' => [
                [
                    'cost_code_id' => $this->costCodeId(),
                    'catalog_item_id' => $item->id,
                    'quantity' => 40,
                ],
                [
                    'cost_code_id' => $this->costCodeId(),
                    'trade_category_id' => $this->tradeCategoryId('Painting'),
                    'unit_id' => $this->unitId('gal'),
                    'description' => 'Eggshell interior white',
                    'quantity' => 6,
                ],
            ],
        ]);

        // Catalog line: both derived.
        $this->assertDatabaseHas('material_request_items', [
            'material_request_id' => $mrId,
            'catalog_item_id' => $item->id,
            'trade_category_id' => $this->tradeCategoryId('Tiles'),
            'unit_id' => $this->unitId('sf'),
        ]);

        // Free-text line: taken as sent.
        $this->assertDatabaseHas('material_request_items', [
            'material_request_id' => $mrId,
            'catalog_item_id' => null,
            'trade_category_id' => $this->tradeCategoryId('Painting'),
            'unit_id' => $this->unitId('gal'),
        ]);
    }

    public function test_nested_free_text_line_missing_trade_category_is_rejected_by_index(): void
    {
        $response = $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests",
            [
                'urgency_id' => Urgency::where('code', 'normal')->value('id'),
                'items' => [[
                    'cost_code_id' => $this->costCodeId(),
                    'unit_id' => $this->unitId('ea'),
                    'description' => 'Shim pack',
                    'quantity' => 1,
                ]],
            ],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['items.0.trade_category_id']);
    }

    /* ---------------- update path ---------------- */

    public function test_swapping_the_catalog_item_re_derives_the_trade_category(): void
    {
        $doors = CatalogItem::factory()->tradeCategory('Doors')->create();
        $tiles = CatalogItem::factory()->tradeCategory('Tiles')->create();

        $mrId = $this->createDraft();
        $itemId = (int) $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $doors->id,
            'quantity' => 1,
        ])->assertStatus(201)->json('data.id');

        $this->patchLine($mrId, $itemId, ['catalog_item_id' => $tiles->id])
            ->assertStatus(200)
            ->assertJsonPath('data.trade_category.id', $tiles->trade_category_id);

        $this->assertDatabaseHas('material_request_items', [
            'id' => $itemId,
            'catalog_item_id' => $tiles->id,
            'trade_category_id' => $this->tradeCategoryId('Tiles'),
        ]);
    }

    public function test_clearing_the_catalog_item_without_a_trade_category_is_rejected(): void
    {
        $item = CatalogItem::factory()->tradeCategory('Doors')->create();

        $mrId = $this->createDraft();
        $itemId = (int) $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $item->id,
            'quantity' => 1,
        ])->json('data.id');

        // The line already has a DERIVED category, but it belonged to the item
        // being removed — the caller must state one explicitly.
        $this->patchLine($mrId, $itemId, [
            'catalog_item_id' => null,
            'trade_category_id' => null,
            'description' => 'Salvaged door, reuse',
        ])->assertStatus(422)->assertJsonValidationErrors(['trade_category_id']);
    }

    public function test_clearing_the_catalog_item_with_a_trade_category_is_accepted(): void
    {
        $item = CatalogItem::factory()->tradeCategory('Doors')->create();

        $mrId = $this->createDraft();
        $itemId = (int) $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $item->id,
            'quantity' => 1,
        ])->json('data.id');

        $this->patchLine($mrId, $itemId, [
            'catalog_item_id' => null,
            'trade_category_id' => $this->tradeCategoryId('Demolition'),
            'description' => 'Salvaged door, reuse',
        ])->assertStatus(200);

        $this->assertDatabaseHas('material_request_items', [
            'id' => $itemId,
            'catalog_item_id' => null,
            'trade_category_id' => $this->tradeCategoryId('Demolition'),
        ]);
    }

    public function test_unrelated_patch_leaves_the_derived_category_intact(): void
    {
        $item = CatalogItem::factory()->tradeCategory('Roofing')->create();

        $mrId = $this->createDraft();
        $itemId = (int) $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $item->id,
            'quantity' => 1,
        ])->json('data.id');

        $this->patchLine($mrId, $itemId, ['quantity' => 9])->assertStatus(200);

        $this->assertDatabaseHas('material_request_items', [
            'id' => $itemId,
            'quantity' => '9.000',
            'trade_category_id' => $this->tradeCategoryId('Roofing'),
        ]);
    }

    public function test_unrelated_patch_does_not_blank_a_free_text_lines_category(): void
    {
        $mrId = $this->createDraft();
        $itemId = (int) $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'trade_category_id' => $this->tradeCategoryId('Painting'),
            'unit_id' => $this->unitId('gal'),
            'description' => 'Eggshell interior white',
            'quantity' => 6,
        ])->json('data.id');

        $this->patchLine($mrId, $itemId, ['quantity' => 8])->assertStatus(200);

        $this->assertDatabaseHas('material_request_items', [
            'id' => $itemId,
            'trade_category_id' => $this->tradeCategoryId('Painting'),
        ]);
    }

    /* ---------------- regression ---------------- */

    public function test_line_with_neither_catalog_item_nor_description_is_still_rejected(): void
    {
        $mrId = $this->createDraft();

        $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'trade_category_id' => $this->tradeCategoryId('Painting'),
            'unit_id' => $this->unitId('ea'),
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['description']);
    }
}
