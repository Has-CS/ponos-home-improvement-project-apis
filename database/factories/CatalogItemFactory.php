<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\CatalogItemType;
use App\Models\TradeCategory;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogItem>
 *
 * Requires LookupSeeder to have already run — resolves trade_category_id,
 * catalog_item_type_id and default_unit_id from the seeded lookup rows, the
 * same dependency style as ProjectFactory.
 */
class CatalogItemFactory extends Factory
{
    protected $model = CatalogItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trade_category_id'    => TradeCategory::query()->orderBy('id')->value('id'),
            'catalog_item_type_id' => CatalogItemType::where('code', 'material')->value('id'),
            'default_unit_id'      => Unit::where('code', 'ea')->value('id'),
            'project_id'           => null,
            'sku'                  => fake()->unique()->bothify('SKU-####'),
            'name'                 => fake()->words(3, true),
            'description'          => null,
            'is_custom'            => false,
            'attributes'           => null,
            'created_by'           => null,
        ];
    }

    /** Pin the item to a named seeded trade category (e.g. 'Plumbing'). */
    public function tradeCategory(string $name): static
    {
        return $this->state(fn () => [
            'trade_category_id' => TradeCategory::where('name', $name)->value('id'),
        ]);
    }

    /** Pin the item's default unit to a seeded unit code (e.g. 'sf'). */
    public function defaultUnit(string $code): static
    {
        return $this->state(fn () => [
            'default_unit_id' => Unit::where('code', $code)->value('id'),
        ]);
    }
}
