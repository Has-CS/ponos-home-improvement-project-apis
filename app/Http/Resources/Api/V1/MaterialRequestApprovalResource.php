<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialRequestApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'step_no' => $this->step_no,
            'action' => $this->action,
            'comments' => $this->comments,
            'approver_role' => $this->approver_role,
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => trim("{$this->approver->first_name} {$this->approver->last_name}"),
            ] : null),
            'from_status' => $this->whenLoaded('fromStatus', fn () => $this->fromStatus ? [
                'id' => $this->fromStatus->id,
                'code' => $this->fromStatus->code,
                'label' => $this->fromStatus->label,
            ] : null),
            'to_status' => $this->whenLoaded('toStatus', fn () => $this->toStatus ? [
                'id' => $this->toStatus->id,
                'code' => $this->toStatus->code,
                'label' => $this->toStatus->label,
            ] : null),
            'acted_at' => $this->acted_at?->toIso8601String(),
        ];
    }
}
