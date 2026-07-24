<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialRequestDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_no' => $this->request_no,
            'project_id' => $this->project_id,
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ]),
            'urgency' => $this->whenLoaded('urgency', fn () => [
                'id' => $this->urgency->id,
                'code' => $this->urgency->code,
                'label' => $this->urgency->label,
            ]),
            'requester' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => trim("{$this->requester->first_name} {$this->requester->last_name}"),
            ] : null),
            'needed_by_date' => $this->needed_by_date?->toDateString(),
            'notes' => $this->notes,
            'items' => MaterialRequestItemResource::collection($this->whenLoaded('items')),
            'approvals' => MaterialRequestApprovalResource::collection($this->whenLoaded('approvals')),
            'purchase_orders' => $this->whenLoaded('purchaseOrders', fn () => $this->purchaseOrders->map(fn ($po) => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'total_amount' => $po->total_amount,
                'purchase_order_status_id' => $po->purchase_order_status_id,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
