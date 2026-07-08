<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'deliverable' => $this->deliverable,
            'sequence' => $this->sequence,
            'planned_date' => $this->planned_date?->toDateString(),
            'actual_date' => $this->actual_date?->toDateString(),
            'is_completed' => $this->is_completed,
            'is_payment_milestone' => (bool) $this->is_payment_milestone,
            'payment_amount' => $this->payment_amount,

            'phase' => $this->whenLoaded('phase', fn () => $this->phase ? [
                'id' => $this->phase->id,
                'code' => $this->phase->code,
                'label' => $this->phase->label,
            ] : null),

            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ] : null),

            'predecessor' => $this->whenLoaded('predecessor', fn () => $this->predecessor ? [
                'id' => $this->predecessor->id,
                'code' => $this->predecessor->code,
                'name' => $this->predecessor->name,
            ] : null),

            'responsible' => [
                'user' => $this->whenLoaded('responsibleUser', fn () => $this->responsibleUser ? [
                    'id' => $this->responsibleUser->id,
                    'name' => trim("{$this->responsibleUser->first_name} {$this->responsibleUser->last_name}"),
                ] : null),
                'label' => $this->responsible_party_label,
            ],

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => trim("{$this->creator->first_name} {$this->creator->last_name}"),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
