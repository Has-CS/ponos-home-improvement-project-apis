<?php

namespace Tests\Feature\ProjectDeliveryAddress;

use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;

class PurchaseOrderShipToTest extends DeliveryAddressTestCase
{
    public function test_a_po_snapshots_the_selected_address(): void
    {
        $address = $this->makeAddress(['label' => 'North Site', 'city' => 'Wheaton']);

        $poId = $this->createPurchaseOrder(['ship_to_address_id' => $address->id]);

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.ship_to.address_id', $address->id)
            ->assertJsonPath('data.ship_to.label', 'North Site')
            ->assertJsonPath('data.ship_to.city', 'Wheaton')
            ->assertJsonPath('data.ship_to.project_code', $this->project->code)
            ->assertJsonPath('data.ship_to.project_name', $this->project->name);
    }

    public function test_a_po_falls_back_to_the_projects_primary_address(): void
    {
        $primary = $this->makeAddress(['label' => 'Primary Site', 'is_primary' => true]);
        $this->makeAddress(['label' => 'Other Site']);

        $poId = $this->createPurchaseOrder(); // no ship_to_address_id

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.ship_to.address_id', $primary->id)
            ->assertJsonPath('data.ship_to.label', 'Primary Site');
    }

    public function test_a_po_with_no_address_and_no_primary_has_no_ship_to(): void
    {
        $poId = $this->createPurchaseOrder();

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.ship_to', null);
    }

    public function test_an_address_from_another_project_is_rejected(): void
    {
        $foreign = $this->makeAddress(for: Project::factory()->create());

        $this->postPurchaseOrder(['ship_to_address_id' => $foreign->id])
            ->assertStatus(422);
    }

    /**
     * The core guarantee. Once issued, nothing done to the address — or to the
     * project it belongs to — may alter what the order says.
     */
    public function test_an_issued_po_is_immune_to_later_address_and_project_edits(): void
    {
        $address = $this->makeAddress([
            'label' => 'North Site',
            'street_1' => '88 Ridgeview Court',
            'city' => 'Wheaton',
        ]);

        $poId = $this->createPurchaseOrder(['ship_to_address_id' => $address->id]);

        // Captured BEFORE the rename below: save() calls syncOriginal(), so
        // getOriginal() afterwards would already hold the new values — the same
        // trap ActivityLogger::diff() documents.
        $originalName = $this->project->name;
        $originalCode = $this->project->code;

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$poId}/issue")
            ->assertOk();

        // Rewrite the address, then remove it entirely.
        $address->update([
            'label' => 'RELOCATED Site',
            'street_1' => '1 Somewhere Else',
            'city' => 'Naperville',
        ]);
        $address->delete();

        // Rename the project too — ship_to_project_name/_code are snapshotted
        // precisely because projects.name and .code are editable.
        $this->project->update(['name' => 'Renamed Project', 'code' => 'PRJ-CHANGED']);

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.ship_to.label', 'North Site')
            ->assertJsonPath('data.ship_to.street_1', '88 Ridgeview Court')
            ->assertJsonPath('data.ship_to.city', 'Wheaton')
            ->assertJsonPath('data.ship_to.project_name', $originalName)
            ->assertJsonPath('data.ship_to.project_code', $originalCode);
    }

    public function test_a_draft_po_can_be_repointed_and_the_snapshot_follows(): void
    {
        $first = $this->makeAddress(['label' => 'North Site']);
        $second = $this->makeAddress(['label' => 'South Site', 'city' => 'Aurora']);

        $poId = $this->createPurchaseOrder(['ship_to_address_id' => $first->id]);

        $this->actingAs($this->procurement, 'api')
            ->patchJson("/api/v1/purchase-orders/{$poId}", ['ship_to_address_id' => $second->id])
            ->assertOk();

        $this->showPurchaseOrder($poId)
            ->assertJsonPath('data.ship_to.address_id', $second->id)
            ->assertJsonPath('data.ship_to.label', 'South Site')
            ->assertJsonPath('data.ship_to.city', 'Aurora');
    }

    public function test_clearing_the_address_on_a_draft_clears_the_whole_snapshot(): void
    {
        $address = $this->makeAddress(['label' => 'North Site']);
        $poId = $this->createPurchaseOrder(['ship_to_address_id' => $address->id]);

        $this->actingAs($this->procurement, 'api')
            ->patchJson("/api/v1/purchase-orders/{$poId}", ['ship_to_address_id' => null])
            ->assertOk();

        $this->showPurchaseOrder($poId)->assertJsonPath('data.ship_to', null);

        // No stale snapshot left behind the null FK.
        $po = PurchaseOrder::find($poId);
        $this->assertNull($po->ship_to_label);
        $this->assertNull($po->ship_to_project_code);
    }

    public function test_an_issued_po_can_no_longer_be_repointed(): void
    {
        $first = $this->makeAddress(['label' => 'North Site']);
        $second = $this->makeAddress(['label' => 'South Site']);

        $poId = $this->createPurchaseOrder(['ship_to_address_id' => $first->id]);

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$poId}/issue")->assertOk();

        $this->actingAs($this->procurement, 'api')
            ->patchJson("/api/v1/purchase-orders/{$poId}", ['ship_to_address_id' => $second->id])
            ->assertStatus(409);
    }

    public function test_a_po_without_a_delivery_address_cannot_be_issued(): void
    {
        $poId = $this->createPurchaseOrder(); // no addresses on the project at all

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$poId}/issue")
            ->assertStatus(422);
    }

    public function test_issuing_succeeds_once_an_address_is_set(): void
    {
        $poId = $this->createPurchaseOrder();
        $address = $this->makeAddress(['label' => 'North Site']);

        $this->actingAs($this->procurement, 'api')
            ->patchJson("/api/v1/purchase-orders/{$poId}", ['ship_to_address_id' => $address->id])
            ->assertOk();

        $this->actingAs($this->procurement, 'api')
            ->postJson("/api/v1/purchase-orders/{$poId}/issue")
            ->assertOk();
    }

    /** The block as it prints, per the agreed PO layout. */
    public function test_formatted_lines_match_the_print_layout(): void
    {
        $address = ProjectDeliveryAddress::factory()
            ->harrington()
            ->create(['project_id' => $this->project->id]);

        $this->project->update([
            'name' => 'Harrington Residence — Full Renovation',
            'code' => 'PNS-2026-014',
        ]);

        $poId = $this->createPurchaseOrder([
            'ship_to_address_id' => $address->id,
            'expected_delivery_date' => '2026-08-14',
        ]);

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.ship_to.formatted_lines', [
                'Harrington Residence — Full Renovation',
                '88 Ridgeview Court',
                'Wheaton, IL 60187',
                'United States',
                'Project PNS-2026-014',
                'Deliver by 14 Aug 2026',
            ]);
    }

    /**
     * The contact-led block: person first and unprefixed, company/site second,
     * and none of the street / postal / country lines the site shape carries.
     */
    public function test_formatted_lines_render_a_contact_led_address(): void
    {
        $address = ProjectDeliveryAddress::factory()
            ->pwc()
            ->create(['project_id' => $this->project->id]);

        $poId = $this->createPurchaseOrder(['ship_to_address_id' => $address->id]);

        $this->showPurchaseOrder($poId)
            ->assertOk()
            ->assertJsonPath('data.ship_to.formatted_lines', [
                'Tyler Blake',
                'PWC Companies – PWC Headquarters',
                'Cornwall-on-Hudson, NY',
                'Project '.$this->project->code,
            ]);
    }

    /**
     * A street-less address must still count as a destination — it is a real
     * place, and keying the guard on the street would block the PO.
     */
    public function test_a_street_less_address_still_allows_issuing(): void
    {
        $address = ProjectDeliveryAddress::factory()
            ->pwc()
            ->create(['project_id' => $this->project->id]);

        $poId = $this->createPurchaseOrder(['ship_to_address_id' => $address->id]);

        $this->issuePurchaseOrder($poId)->assertOk();
    }

    public function test_the_po_list_carries_the_ship_to_label(): void
    {
        $address = $this->makeAddress(['label' => 'North Site']);
        $this->createPurchaseOrder(['ship_to_address_id' => $address->id]);

        $this->actingAs($this->procurement, 'api')
            ->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('data.items.0.ship_to_label', 'North Site');
    }
}
