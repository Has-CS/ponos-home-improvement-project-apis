<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeOrderLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'change_order_no' => $this->change_order_no,
            'title'          => $this->title,
            'value'          => $this->value,
            'type'           => $this->whenLoaded('type', fn() => $this->type?->code),
            'status'         => $this->whenLoaded('status', fn() => $this->status ? [
                'code' => $this->status->code,
                'label' => $this->status->label,
            ] : null),
            'gc_decision'    => $this->whenLoaded('gcDecision', fn() => $this->gcDecision?->code),
            'cost_code'      => $this->whenLoaded('costCode', fn() => $this->costCode?->code),
            'originator'     => $this->whenLoaded('originator', fn() => $this->originator ? [
                'id' => $this->originator->id,
                'name' => trim("{$this->originator->first_name} {$this->originator->last_name}"),
            ] : null),
            // The authorization chain (reconciled from change_order_approvals, not duplicated).
            'authorization_chain' => $this->whenLoaded(
                'approvals',
                fn() =>
                $this->approvals->map(fn($a) => [
                    'step_no'     => $a->step_no,
                    'action'      => $a->action,
                    'actor_role'  => $a->actor_role,
                    'actor'       => $a->relationLoaded('actor') && $a->actor
                        ? trim("{$a->actor->first_name} {$a->actor->last_name}") : null,
                    'from_status' => $a->relationLoaded('fromStatus') ? $a->fromStatus?->code : null,
                    'to_status'   => $a->relationLoaded('toStatus') ? $a->toStatus?->code : null,
                    'comments'    => $a->comments,
                    'acted_at'    => $a->acted_at?->toIso8601String(),
                ])->values()
            ),
            'became_active_at' => $this->became_active_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
