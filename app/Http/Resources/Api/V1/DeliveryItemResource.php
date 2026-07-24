<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'quantity_received' => $this->quantity_received,
            'quantity_accepted' => $this->quantity_accepted,
            'notes' => $this->notes,
            'purchase_order_item' => $this->whenLoaded('purchaseOrderItem', fn () => $this->purchaseOrderItem ? [
                'id' => $this->purchaseOrderItem->id,
                'quantity_ordered' => $this->purchaseOrderItem->quantity_ordered,
                'catalog_item_id' => $this->purchaseOrderItem->catalog_item_id,
            ] : null),
        ];
    }
}
