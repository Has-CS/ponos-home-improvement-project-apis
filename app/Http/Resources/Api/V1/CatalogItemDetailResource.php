<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogItemDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'is_custom' => (bool) $this->is_custom,
            'attributes' => $this->attributes,
            'trade_category' => $this->whenLoaded('tradeCategory', fn () => [
                'id' => $this->tradeCategory->id,
                'name' => $this->tradeCategory->name,
            ]),
            'catalog_item_type' => $this->whenLoaded('catalogItemType', fn () => [
                'id' => $this->catalogItemType->id,
                'code' => $this->catalogItemType->code,
                'label' => $this->catalogItemType->label,
            ]),
            'default_unit' => $this->whenLoaded('defaultUnit', fn () => [
                'id' => $this->defaultUnit->id,
                'code' => $this->defaultUnit->code,
                'label' => $this->defaultUnit->label,
            ]),
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null),
            'current_vendor_rates' => $this->whenLoaded('currentVendorRates', fn () => $this->currentVendorRates->map(fn ($rate) => [
                'id' => $rate->id,
                'vendor' => ['id' => $rate->vendor->id, 'name' => $rate->vendor->name],
                'rate' => $rate->rate,
                'currency' => $rate->currency,
                'unit' => ['id' => $rate->unit->id, 'code' => $rate->unit->code, 'label' => $rate->unit->label],
                'effective_from' => $rate->effective_from?->toDateString(),
                'source' => $rate->source,
            ])),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => trim("{$this->creator->first_name} {$this->creator->last_name}"),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
