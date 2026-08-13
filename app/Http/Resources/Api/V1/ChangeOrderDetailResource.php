<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeOrderDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'change_order_no' => $this->change_order_no,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'scope' => $this->scope,
            'location' => $this->location,
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
            'cost_code' => $this->whenLoaded('costCode', fn () => $this->costCode ? [
                'id' => $this->costCode->id,
                'code' => $this->costCode->code,
                'name' => $this->costCode->name,
            ] : null),
            'urgency' => $this->whenLoaded('urgency', fn () => $this->urgency ? [
                'id' => $this->urgency->id,
                'code' => $this->urgency->code,
                'label' => $this->urgency->label,
            ] : null),
            'originator' => $this->whenLoaded('originator', fn () => $this->originator ? [
                'id' => $this->originator->id,
                'name' => trim("{$this->originator->first_name} {$this->originator->last_name}"),
            ] : null),
            'counter_signed_by' => $this->whenLoaded('counterSignedBy', fn () => $this->counterSignedBy ? [
                'id' => $this->counterSignedBy->id,
                'name' => trim("{$this->counterSignedBy->first_name} {$this->counterSignedBy->last_name}"),
            ] : null),
            'counter_signed_at' => $this->counter_signed_at?->toIso8601String(),
            'gc_decision_by' => $this->whenLoaded('gcDecisionBy', fn () => $this->gcDecisionBy ? [
                'id' => $this->gcDecisionBy->id,
                'name' => trim("{$this->gcDecisionBy->first_name} {$this->gcDecisionBy->last_name}"),
            ] : null),
            'gc_decision_at' => $this->gc_decision_at?->toIso8601String(),
            'gc_decision_notes' => $this->gc_decision_notes,
            'became_active_at' => $this->became_active_at?->toIso8601String(),
            'document_attachment_id' => $this->document_attachment_id,
            'authorization_chain' => ChangeOrderApprovalResource::collection($this->whenLoaded('approvals')),
            'signatures' => ChangeOrderSignatureResource::collection($this->whenLoaded('signatures')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
