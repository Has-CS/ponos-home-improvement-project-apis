<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor' => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->id, 'name' => $this->vendor->name]),
            'catalog_item' => $this->whenLoaded('catalogItem', fn () => [
                'id' => $this->catalogItem->id,
                'name' => $this->catalogItem->name,
                'sku' => $this->catalogItem->sku,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => ['id' => $this->unit->id, 'code' => $this->unit->code, 'label' => $this->unit->label]),
            'rate' => $this->rate,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_current' => $this->effective_to === null,
            'source' => $this->source,
            'notes' => $this->notes,
            'superseded_by_id' => $this->superseded_by_id,
            'entered_by' => $this->whenLoaded('enteredBy', fn () => $this->enteredBy ? [
                'id' => $this->enteredBy->id,
                'name' => trim("{$this->enteredBy->first_name} {$this->enteredBy->last_name}"),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
