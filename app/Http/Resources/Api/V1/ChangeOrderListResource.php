<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'change_order_no' => $this->change_order_no,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'value' => $this->value,
            'type' => $this->whenLoaded('type', fn () => [
                'id' => $this->type->id,
                'code' => $this->type->code,
                'label' => $this->type->label,
            ]),
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ]),
            'gc_decision' => $this->whenLoaded('gcDecision', fn () => [
                'id' => $this->gcDecision->id,
                'code' => $this->gcDecision->code,
                'label' => $this->gcDecision->label,
            ]),
            'originator' => $this->whenLoaded('originator', fn () => $this->originator ? [
                'id' => $this->originator->id,
                'name' => trim("{$this->originator->first_name} {$this->originator->last_name}"),
            ] : null),
            'became_active_at' => $this->became_active_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
