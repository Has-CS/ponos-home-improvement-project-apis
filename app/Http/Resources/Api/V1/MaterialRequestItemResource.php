<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
            'cost_code' => $this->whenLoaded('costCode', fn () => [
                'id' => $this->costCode->id,
                'code' => $this->costCode->code,
                'name' => $this->costCode->name,
            ]),
            'catalog_item' => $this->whenLoaded('catalogItem', fn () => $this->catalogItem ? [
                'id' => $this->catalogItem->id,
                'name' => $this->catalogItem->name,
                'sku' => $this->catalogItem->sku,
            ] : null),
            'trade_category' => $this->whenLoaded('tradeCategory', fn () => $this->tradeCategory ? [
                'id' => $this->tradeCategory->id,
                'name' => $this->tradeCategory->name,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'label' => $this->unit->label,
            ]),
        ];
    }
}
