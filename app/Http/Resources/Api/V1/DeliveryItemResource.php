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
            // Numbers, not the stored "50.000" strings — see MaterialRequestItemResource.
            // quantity_accepted is nullable and stays null when not recorded:
            // (float) null would report 0 accepted, which is a different claim.
            'quantity_received' => (float) $this->quantity_received,
            'quantity_accepted' => $this->quantity_accepted === null ? null : (float) $this->quantity_accepted,
            'notes' => $this->notes,
            'purchase_order_item' => $this->whenLoaded('purchaseOrderItem', fn () => $this->purchaseOrderItem ? [
                'id' => $this->purchaseOrderItem->id,
                'quantity_ordered' => (float) $this->purchaseOrderItem->quantity_ordered,
                'catalog_item_id' => $this->purchaseOrderItem->catalog_item_id,
            ] : null),
        ];
    }
}
