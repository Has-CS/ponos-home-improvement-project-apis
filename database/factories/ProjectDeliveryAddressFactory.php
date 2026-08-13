<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectDeliveryAddress>
 *
 * Unlike ProjectFactory / CatalogItemFactory this needs no seeded lookups —
 * an address has no FK but its project.
 *
 * NOTE: `is_primary` defaults to FALSE here even though
 * ProjectDeliveryAddressService promotes a project's first address
 * automatically. The factory writes straight to the table, bypassing the
 * service, so making several addresses for one project would otherwise trip the
 * project_delivery_addresses_one_primary index. Use ->primary() to set it
 * deliberately.
 */
class ProjectDeliveryAddressFactory extends Factory
{
    protected $model = ProjectDeliveryAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'label' => fake()->company().' Site',
            'attention' => null,
            'street_1' => fake()->streetAddress(),
            'street_2' => null,
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'United States',
            'contact_phone' => null,
            'delivery_notes' => null,
            'is_primary' => false,
            'created_by' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    /**
     * A contact-led destination: a named person at a company location, with no
     * street, postal code or country. The second shape the print block must
     * handle, and the one that used to be impossible to store.
     */
    public function pwc(): static
    {
        return $this->state(fn () => [
            'attention' => 'Tyler Blake',
            'label' => 'PWC Companies – PWC Headquarters',
            'street_1' => null,
            'street_2' => null,
            'city' => 'Cornwall-on-Hudson',
            'state' => 'NY',
            'postal_code' => null,
            'country' => null,
        ]);
    }

    /** The exact address from the PO print demo, for formatting assertions. */
    public function harrington(): static
    {
        return $this->state(fn () => [
            'label' => 'Harrington Residence — Full Renovation',
            'street_1' => '88 Ridgeview Court',
            'street_2' => null,
            'city' => 'Wheaton',
            'state' => 'IL',
            'postal_code' => '60187',
            'country' => 'United States',
        ]);
    }
}
