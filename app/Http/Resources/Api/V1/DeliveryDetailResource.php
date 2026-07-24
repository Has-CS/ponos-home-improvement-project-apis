<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'bol_number' => $this->bol_number,
            'has_discrepancy' => (bool) $this->has_discrepancy,
            'notes' => $this->notes,
            'received_at' => $this->received_at?->toIso8601String(),
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy ? [
                'id' => $this->receivedBy->id,
                'name' => trim("{$this->receivedBy->first_name} {$this->receivedBy->last_name}"),
            ] : null),
            'items' => DeliveryItemResource::collection($this->whenLoaded('items')),
            'discrepancies' => DiscrepancyResource::collection($this->whenLoaded('discrepancies')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
