<?php

namespace Tests\Feature\ProjectDeliveryAddress;

use App\Models\Project;
use App\Models\ProjectDeliveryAddress;

class DeliveryAddressCrudTest extends DeliveryAddressTestCase
{
    public function test_pm_can_create_a_delivery_address(): void
    {
        $this->postAddress(['label' => 'North Site'])
            ->assertStatus(201)
            ->assertJsonPath('data.label', 'North Site')
            ->assertJsonPath('data.city', 'Wheaton')
            // No column default any more — country prints only when entered.
            ->assertJsonPath('data.country', null);
    }

    public function test_country_is_stored_when_supplied(): void
    {
        $this->postAddress(['country' => 'United States'])
            ->assertStatus(201)
            ->assertJsonPath('data.country', 'United States');
    }

    /** The contact-led shape: a person at a company location, no street. */
    public function test_an_address_without_a_street_can_be_created(): void
    {
        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/delivery-addresses", [
                'attention' => 'Tyler Blake',
                'label' => 'PWC Companies – PWC Headquarters',
                'city' => 'Cornwall-on-Hudson',
                'state' => 'NY',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.attention', 'Tyler Blake')
            ->assertJsonPath('data.street_1', null);
    }

    public function test_a_contact_name_alone_satisfies_the_recipient_requirement(): void
    {
        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/delivery-addresses", [
                'attention' => 'Tyler Blake',
                'city' => 'Cornwall-on-Hudson',
            ])
            ->assertStatus(201);
    }

    public function test_an_address_with_neither_label_nor_contact_is_rejected(): void
    {
        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/delivery-addresses", [
                'city' => 'Cornwall-on-Hudson',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label', 'attention']);
    }

    public function test_patching_one_field_does_not_demand_the_other_recipient_field(): void
    {
        $id = (int) $this->postAddress(['label' => 'North Site'])->json('data.id');

        // label is stored but absent from this payload — the merged-state check
        // must read the row, not just the request.
        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$id}", ['city' => 'Aurora'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Aurora');
    }

    public function test_clearing_the_last_recipient_field_is_rejected(): void
    {
        $id = (int) $this->postAddress(['label' => 'North Site'])->json('data.id');

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$id}", ['label' => null])
            ->assertStatus(422);
    }

    public function test_foreman_cannot_write_addresses(): void
    {
        $this->postAddress(as: $this->foreman)->assertStatus(403);
    }

    public function test_a_recipient_and_city_are_required(): void
    {
        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/delivery-addresses", [])
            ->assertStatus(422)
            // street_1 is deliberately absent from this list — a contact-led
            // destination has no street.
            ->assertJsonValidationErrors(['label', 'attention', 'city'])
            ->assertJsonMissingValidationErrors(['street_1']);
    }

    public function test_a_project_member_can_list_addresses(): void
    {
        $this->makeAddress(['label' => 'North Site']);

        $this->actingAs($this->pm, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/delivery-addresses")
            ->assertOk()
            ->assertJsonPath('data.0.label', 'North Site');
    }

    /**
     * The regression the read gate exists for: purchase-order work is not
     * project-membership-scoped, so a buyer who was never staffed onto the
     * project must still be able to load the ship-to dropdown.
     */
    public function test_procurement_can_list_addresses_without_project_membership(): void
    {
        $this->makeAddress(['label' => 'North Site']);

        $this->assertDatabaseMissing('project_user', [
            'user_id' => $this->procurement->id,
            'project_id' => $this->project->id,
        ]);

        $this->actingAs($this->procurement, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/delivery-addresses")
            ->assertOk()
            ->assertJsonPath('data.0.label', 'North Site');
    }

    public function test_an_unstaffed_user_without_purchasing_rights_cannot_list_addresses(): void
    {
        $this->makeAddress();

        // Holds view_project (every role does) but is neither a member nor a buyer.
        $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/delivery-addresses")
            ->assertStatus(403);
    }

    public function test_the_first_address_becomes_primary_automatically(): void
    {
        $this->postAddress(['label' => 'Only Site'])
            ->assertStatus(201)
            ->assertJsonPath('data.is_primary', true);
    }

    public function test_promoting_a_new_primary_demotes_the_incumbent(): void
    {
        // Distinct STREETS, not just distinct labels: the label is not part of
        // an address's identity, so two labels on one street are a duplicate.
        $firstId = (int) $this->postAddress(['label' => 'North Site', 'street_1' => '88 Ridgeview Court'])->json('data.id');

        $secondId = (int) $this->postAddress(['label' => 'South Site', 'street_1' => '12 Riverside Drive', 'is_primary' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.is_primary', true)
            ->json('data.id');

        $this->assertFalse(ProjectDeliveryAddress::find($firstId)->is_primary);
        $this->assertTrue(ProjectDeliveryAddress::find($secondId)->is_primary);

        // The partial unique index must never see two live primaries.
        $this->assertSame(1, ProjectDeliveryAddress::where('project_id', $this->project->id)
            ->where('is_primary', true)->count());
    }

    public function test_promoting_via_patch_demotes_the_incumbent(): void
    {
        $firstId = (int) $this->postAddress(['label' => 'North Site', 'street_1' => '88 Ridgeview Court'])->json('data.id');
        $secondId = (int) $this->postAddress(['label' => 'South Site', 'street_1' => '12 Riverside Drive'])->json('data.id');

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$secondId}", ['is_primary' => true])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $this->assertFalse(ProjectDeliveryAddress::find($firstId)->is_primary);
    }

    public function test_the_last_primary_cannot_simply_be_cleared(): void
    {
        $id = (int) $this->postAddress(['label' => 'Only Site'])->json('data.id');

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$id}", ['is_primary' => false])
            ->assertStatus(422);
    }

    public function test_deleting_the_primary_promotes_a_survivor(): void
    {
        $firstId = (int) $this->postAddress(['label' => 'North Site', 'street_1' => '88 Ridgeview Court'])->json('data.id');
        $secondId = (int) $this->postAddress(['label' => 'South Site', 'street_1' => '12 Riverside Drive'])->json('data.id');

        $this->actingAs($this->pm, 'api')
            ->deleteJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$firstId}")
            ->assertOk();

        $this->assertSoftDeleted('project_delivery_addresses', ['id' => $firstId]);
        $this->assertTrue(ProjectDeliveryAddress::find($secondId)->is_primary);
    }

    public function test_an_address_from_another_project_is_not_found(): void
    {
        $other = $this->makeAddress(for: Project::factory()->create());

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$other->id}", ['label' => 'Hijacked'])
            ->assertStatus(404);
    }

    public function test_addresses_are_listed_primary_first(): void
    {
        $this->postAddress(['label' => 'Zulu Site', 'street_1' => '88 Ridgeview Court']);   // auto-primary
        $this->postAddress(['label' => 'Alpha Site', 'street_1' => '12 Riverside Drive']);

        $labels = $this->actingAs($this->pm, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/delivery-addresses")
            ->assertOk()
            ->json('data.*.label');

        $this->assertSame(['Zulu Site', 'Alpha Site'], $labels);
    }
}
