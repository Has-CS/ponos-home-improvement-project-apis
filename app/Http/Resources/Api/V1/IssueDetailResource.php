<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'daily_log_id' => $this->daily_log_id,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity,
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ]),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'name' => trim("{$this->assignedTo->first_name} {$this->assignedTo->last_name}"),
            ] : null),
            'raised_by' => $this->whenLoaded('raisedBy', fn () => $this->raisedBy ? [
                'id' => $this->raisedBy->id,
                'name' => trim("{$this->raisedBy->first_name} {$this->raisedBy->last_name}"),
            ] : null),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy ? [
                'id' => $this->resolvedBy->id,
                'name' => trim("{$this->resolvedBy->first_name} {$this->resolvedBy->last_name}"),
            ] : null),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
