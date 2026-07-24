<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'project_id' => $this->project_id,
            'material_request_id' => $this->material_request_id,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
            ]),
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ]),
            'total_amount' => $this->total_amount,
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
