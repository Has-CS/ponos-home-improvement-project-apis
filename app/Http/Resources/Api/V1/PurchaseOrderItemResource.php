<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'quantity_ordered' => $this->quantity_ordered,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'catalog_item' => $this->whenLoaded('catalogItem', fn () => $this->catalogItem ? [
                'id' => $this->catalogItem->id,
                'name' => $this->catalogItem->name,
                'sku' => $this->catalogItem->sku,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'label' => $this->unit->label,
            ]),
            'cost_code' => $this->whenLoaded('costCode', fn () => $this->costCode ? [
                'id' => $this->costCode->id,
                'code' => $this->costCode->code,
                'name' => $this->costCode->name,
            ] : null),
            'vendor_rate_id' => $this->vendor_rate_id,
            'material_request_item_id' => $this->material_request_item_id,
        ];
    }
}
