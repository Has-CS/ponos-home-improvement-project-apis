<?php

namespace Tests\Feature\ProjectDeliveryAddress;

use App\Models\Project;
use App\Models\ProjectDeliveryAddress;

/**
 * Duplicate prevention on project delivery addresses.
 *
 * Identity is location + contact; the label is a display name and is excluded
 * (except when it is the only distinguishing detail). See
 * ProjectDeliveryAddress::fingerprintFor().
 */
class DeliveryAddressDuplicateTest extends DeliveryAddressTestCase
{
    /** The reported bug: the same address accepted twice. */
    public function test_the_same_address_cannot_be_added_twice(): void
    {
        $firstId = (int) $this->postAddress(['label' => 'North Site'])
            ->assertStatus(201)
            ->json('data.id');

        $this->postAddress(['label' => 'North Site'])
            ->assertStatus(409)
            ->assertJsonPath('errors.existing_address_id', $firstId);
    }

    /** The id 1 vs id 3 case on dev: one street, two labels. */
    public function test_the_label_does_not_make_an_address_distinct(): void
    {
        $firstId = (int) $this->postAddress(['label' => 'Silcone Village — North Site'])->json('data.id');

        $this->postAddress(['label' => 'Harrington Residence — Full Renovation'])
            ->assertStatus(409)
            ->assertJsonPath('errors.existing_address_id', $firstId);
    }

    public function test_case_and_whitespace_differences_are_still_duplicates(): void
    {
        $this->postAddress(['street_1' => '88 Ridgeview Court', 'city' => 'Wheaton'])->assertStatus(201);

        $this->postAddress(['street_1' => '88  RIDGEVIEW   court', 'city' => '  wheaton '])
            ->assertStatus(409);
    }

    /** Two named recipients at one building are distinct destinations. */
    public function test_a_different_contact_at_the_same_address_is_allowed(): void
    {
        $this->postAddress(['attention' => 'Tyler Blake'])->assertStatus(201);
        $this->postAddress(['attention' => 'Jane Doe'])->assertStatus(201);

        $this->assertSame(2, ProjectDeliveryAddress::where('project_id', $this->project->id)->count());
    }

    /**
     * With no street and no contact the label is all there is, so it counts —
     * otherwise both of these reduce to an empty fingerprint and collide.
     */
    public function test_label_only_addresses_in_one_city_are_distinct(): void
    {
        $payload = ['street_1' => null, 'state' => null, 'postal_code' => null, 'city' => 'Wheaton'];

        $this->postAddress([...$payload, 'label' => 'North Site'])->assertStatus(201);
        $this->postAddress([...$payload, 'label' => 'South Site'])->assertStatus(201);
    }

    public function test_a_soft_deleted_address_does_not_block_recreating_it(): void
    {
        $id = (int) $this->postAddress(['label' => 'North Site'])->json('data.id');

        $this->actingAs($this->pm, 'api')
            ->deleteJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$id}")
            ->assertOk();

        $this->postAddress(['label' => 'North Site'])->assertStatus(201);
    }

    public function test_the_same_address_on_two_projects_is_allowed(): void
    {
        $other = Project::factory()->create();

        $this->postAddress()->assertStatus(201);
        $this->postAddress(to: $other)->assertStatus(201);
    }

    public function test_patching_an_address_onto_another_is_rejected(): void
    {
        $firstId = (int) $this->postAddress(['street_1' => '88 Ridgeview Court'])->json('data.id');
        $secondId = (int) $this->postAddress(['street_1' => '12 Riverside Drive'])->json('data.id');

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$secondId}", [
                'street_1' => '88 Ridgeview Court',
            ])
            ->assertStatus(409)
            ->assertJsonPath('errors.existing_address_id', $firstId);
    }

    /** Re-saving a row unchanged must not collide with itself. */
    public function test_patching_an_address_with_its_own_values_succeeds(): void
    {
        $id = (int) $this->postAddress(['street_1' => '88 Ridgeview Court'])->json('data.id');

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$id}", [
                'street_1' => '88 Ridgeview Court',
                'delivery_notes' => 'Gate code 4417.',
            ])
            ->assertOk()
            ->assertJsonPath('data.delivery_notes', 'Gate code 4417.');
    }

    /** The fingerprint must move with the row, not stick to its first value. */
    public function test_editing_an_address_frees_its_old_identity(): void
    {
        $id = (int) $this->postAddress(['street_1' => '88 Ridgeview Court'])->json('data.id');

        $this->actingAs($this->pm, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/delivery-addresses/{$id}", [
                'street_1' => '12 Riverside Drive',
            ])
            ->assertOk();

        // The original address is now unused, so it can be created afresh.
        $this->postAddress(['street_1' => '88 Ridgeview Court'])->assertStatus(201);
    }

    public function test_the_fingerprint_is_not_settable_from_request_input(): void
    {
        $this->postAddress(['address_fingerprint' => 'spoofed'])->assertStatus(201);

        $this->assertNotSame(
            'spoofed',
            ProjectDeliveryAddress::where('project_id', $this->project->id)->value('address_fingerprint'),
        );
    }
}
