<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeOrderApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'step_no' => $this->step_no,
            'action' => $this->action,
            'comments' => $this->comments,
            'actor_role' => $this->actor_role,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => trim("{$this->actor->first_name} {$this->actor->last_name}"),
            ] : null),
            'from_status' => $this->whenLoaded('fromStatus', fn () => $this->fromStatus ? [
                'code' => $this->fromStatus->code,
                'label' => $this->fromStatus->label,
            ] : null),
            'to_status' => $this->whenLoaded('toStatus', fn () => $this->toStatus ? [
                'code' => $this->toStatus->code,
                'label' => $this->toStatus->label,
            ] : null),
            'acted_at' => $this->acted_at?->toIso8601String(),
        ];
    }
}
