<?php

namespace Tests\Feature\Rfq;

use App\Models\CatalogItem;
use App\Models\Rfq;
use App\Models\TradeCategory;
use App\Models\Unit;

class RfqCrudTest extends RfqTestCase
{
    public function test_an_admin_can_create_a_draft_rfq(): void
    {
        $id = $this->createDraftAs($this->admin);

        $rfq = Rfq::findOrFail($id);
        $this->assertStringStartsWith('RFQ-', $rfq->rfq_no);
        $this->assertSame('draft', $rfq->status->code);
        $this->assertSame($this->admin->id, $rfq->created_by);
    }

    public function test_a_pm_can_create_a_draft_rfq(): void
    {
        $this->createDraftAs($this->pm);
        $this->assertDatabaseCount('rfqs', 1);
    }

    public function test_a_foreman_cannot_create_or_read_rfqs(): void
    {
        $this->actingAs($this->foreman, 'api')->postJson('/api/v1/rfqs', [
            'vendor_id' => $this->vendor->id,
            'title' => 'Should not be allowed',
        ])->assertForbidden();

        $this->actingAs($this->foreman, 'api')->getJson('/api/v1/rfqs')->assertForbidden();
    }

    public function test_project_id_is_optional_and_nullable(): void
    {
        $id = $this->createDraftAs($this->pm);

        $rfq = Rfq::findOrFail($id);
        $this->assertNull($rfq->project_id);
    }

    public function test_vendor_id_must_reference_a_real_vendor(): void
    {
        $this->actingAs($this->pm, 'api')->postJson('/api/v1/rfqs', [
            'vendor_id' => 999999,
            'title' => 'Bad vendor',
        ])->assertStatus(422)->assertJsonValidationErrors('vendor_id');
    }

    // ---- Item derivation ----

    public function test_adding_a_catalog_item_line_derives_trade_category_and_unit(): void
    {
        $id = $this->createDraftAs($this->pm);

        $response = $this->addItemAs($this->pm, $id, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 10,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.trade_category.id', $this->catalogItem->trade_category_id);
        $response->assertJsonPath('data.unit.id', $this->catalogItem->default_unit_id);
    }

    public function test_an_explicit_unit_overrides_the_catalog_default(): void
    {
        $id = $this->createDraftAs($this->pm);
        $box = Unit::where('code', 'box')->value('id');

        $response = $this->addItemAs($this->pm, $id, [
            'catalog_item_id' => $this->catalogItem->id,
            'unit_id' => $box,
            'quantity' => 4,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.unit.id', $box);
        $this->assertNotEquals($this->catalogItem->default_unit_id, $box);
    }

    public function test_a_free_text_line_requires_description_trade_category_and_unit(): void
    {
        $id = $this->createDraftAs($this->pm);

        $this->addItemAs($this->pm, $id, ['quantity' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description', 'trade_category_id', 'unit_id']);
    }

    public function test_a_free_text_line_is_accepted_when_fully_described(): void
    {
        $id = $this->createDraftAs($this->pm);
        $trade = TradeCategory::where('name', 'Electrical')->value('id');
        $unit = Unit::where('code', 'ea')->value('id');

        $response = $this->addItemAs($this->pm, $id, [
            'description' => 'Not-yet-catalogued 200A panel, custom brand',
            'trade_category_id' => $trade,
            'unit_id' => $unit,
            'quantity' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.description', 'Not-yet-catalogued 200A panel, custom brand');
    }

    public function test_updating_an_item_does_not_re_derive_a_caller_set_unit(): void
    {
        $id = $this->createDraftAs($this->pm);
        $box = Unit::where('code', 'box')->value('id');

        $itemId = $this->addItemAs($this->pm, $id, [
            'catalog_item_id' => $this->catalogItem->id,
            'unit_id' => $box,
            'quantity' => 4,
        ])->json('data.id');

        // Swap to a different catalog item WITHOUT touching unit_id. Trade
        // category re-derives (server-owned), unit must NOT — it's the
        // caller's own input, only ever defaulted at create time.
        $other = CatalogItem::factory()->create([
            'trade_category_id' => TradeCategory::where('name', 'Electrical')->value('id'),
            'default_unit_id' => Unit::where('code', 'ea')->value('id'),
        ]);

        $response = $this->updateItemAs($this->pm, $id, $itemId, [
            'catalog_item_id' => $other->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.trade_category.id', $other->trade_category_id);
        $response->assertJsonPath('data.unit.id', $box);
    }

    public function test_removing_an_item(): void
    {
        $id = $this->draftWithItem();
        $itemId = Rfq::findOrFail($id)->items->first()->id;

        $this->removeItemAs($this->pm, $id, $itemId)->assertOk();

        $this->assertDatabaseMissing('rfq_items', ['id' => $itemId, 'deleted_at' => null]);
    }

    // ---- Header edits / delete ----

    public function test_the_header_can_be_updated_while_draft(): void
    {
        $id = $this->createDraftAs($this->pm);

        $this->updateRfqAs($this->pm, $id, ['title' => 'Updated title', 'notes' => 'Call before delivery'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated title');
    }

    public function test_a_draft_can_be_deleted_by_its_creator(): void
    {
        $id = $this->createDraftAs($this->pm);

        $this->deleteRfqAs($this->pm, $id)->assertOk();

        $this->assertSoftDeleted('rfqs', ['id' => $id]);
    }

    public function test_admin_can_delete_someone_elses_draft(): void
    {
        $id = $this->createDraftAs($this->pm);

        $this->deleteRfqAs($this->admin, $id)->assertOk();
    }

    public function test_a_pm_cannot_delete_another_pms_draft(): void
    {
        $otherPm = $this->globalUser('Project Manager');
        $id = $this->createDraftAs($this->pm);

        $this->deleteRfqAs($otherPm, $id)->assertForbidden();
    }
}
