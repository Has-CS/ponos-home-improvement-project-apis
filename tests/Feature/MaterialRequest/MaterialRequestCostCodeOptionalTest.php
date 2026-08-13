<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;

/**
 * cost_code_id is no longer required at request time.
 *
 * Cost coding belongs to the estimation / job-costing phase (not yet built) and
 * is not work field staff can do, so a line may be filed with no cost code and a
 * PM/Admin fills it in later — while the request is still editable (draft,
 * sent_back_to_foreman, sent_back_to_pm).
 *
 * Setup and request helpers live in MaterialRequestLineTestCase.
 */
class MaterialRequestCostCodeOptionalTest extends MaterialRequestLineTestCase
{
    /* ---------------- creating without a cost code ---------------- */

    public function test_catalog_line_can_be_created_without_a_cost_code(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft();

        $itemId = (int) $this->addLine($mrId, [
            'catalog_item_id' => $item->id,
            'quantity' => 10,
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('material_request_items', [
            'id' => $itemId,
            'catalog_item_id' => $item->id,
            'cost_code_id' => null,
        ]);
    }

    public function test_free_text_line_can_be_created_without_a_cost_code(): void
    {
        $mrId = $this->createDraft();

        $itemId = (int) $this->addLine($mrId, [
            'trade_category_id' => $this->tradeCategoryId('Framing & Carpentry'),
            'unit_id' => $this->unitId('ea'),
            'description' => '2x 8ft pressure-treated 4x4',
            'quantity' => 2,
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('material_request_items', [
            'id' => $itemId,
            'catalog_item_id' => null,
            'cost_code_id' => null,
        ]);
    }

    public function test_nested_items_can_be_created_without_cost_codes(): void
    {
        $first = CatalogItem::factory()->create();
        $second = CatalogItem::factory()->create();

        $mrId = $this->createDraft([
            'items' => [
                ['catalog_item_id' => $first->id, 'quantity' => 10],
                ['catalog_item_id' => $second->id, 'quantity' => 4],
            ],
        ]);

        $this->assertSame(
            2,
            \App\Models\MaterialRequestItem::where('material_request_id', $mrId)
                ->whereNull('cost_code_id')
                ->count(),
        );
    }

    /* ---------------- serialization must not blow up on null ---------------- */

    public function test_line_response_serializes_a_null_cost_code(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft();

        // whenLoaded() short-circuits on a null relation rather than invoking the
        // closure — this pins that behaviour so a refactor can't reintroduce a 500.
        $this->addLine($mrId, ['catalog_item_id' => $item->id, 'quantity' => 1])
            ->assertStatus(201)
            ->assertJsonPath('data.cost_code', null);
    }

    public function test_detail_endpoint_renders_a_line_with_no_cost_code(): void
    {
        $this->joinProject(); // reads sit behind project.access, unlike writes

        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft([
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 3]],
        ]);

        $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}")
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.cost_code', null);
    }

    /* ---------------- filling it in later ---------------- */

    public function test_cost_code_can_be_set_on_a_line_that_had_none(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft();

        $itemId = (int) $this->addLine($mrId, [
            'catalog_item_id' => $item->id,
            'quantity' => 1,
        ])->json('data.id');

        $this->patchLine($mrId, $itemId, ['cost_code_id' => $this->costCodeId()])
            ->assertStatus(200)
            ->assertJsonPath('data.cost_code.id', $this->costCodeId());

        $this->assertDatabaseHas('material_request_items', [
            'id' => $itemId,
            'cost_code_id' => $this->costCodeId(),
        ]);
    }

    public function test_cost_code_can_be_cleared_again(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft();

        $itemId = (int) $this->addLine($mrId, [
            'cost_code_id' => $this->costCodeId(),
            'catalog_item_id' => $item->id,
            'quantity' => 1,
        ])->json('data.id');

        $this->patchLine($mrId, $itemId, ['cost_code_id' => null])->assertStatus(200);

        $this->assertDatabaseHas('material_request_items', [
            'id' => $itemId,
            'cost_code_id' => null,
        ]);
    }

    /* ---------------- the workflow is not gated on it ---------------- */

    public function test_request_with_uncoded_lines_can_still_be_submitted(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft([
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 5]],
        ]);

        $this->actingAs($this->foreman, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.status.code', 'pending_pm');
    }

    /* ---------------- a supplied value is still validated ---------------- */

    public function test_a_nonexistent_cost_code_is_still_rejected(): void
    {
        $item = CatalogItem::factory()->create();
        $mrId = $this->createDraft();

        $this->addLine($mrId, [
            'cost_code_id' => 999999,
            'catalog_item_id' => $item->id,
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['cost_code_id']);
    }
}
