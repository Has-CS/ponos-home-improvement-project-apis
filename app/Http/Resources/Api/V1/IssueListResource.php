<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
