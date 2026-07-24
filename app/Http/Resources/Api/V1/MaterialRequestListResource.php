<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialRequestListResource extends JsonResource
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
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
