<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyLogDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'log_date' => $this->log_date?->toDateString(),
            'work_description' => $this->work_description,
            'weather' => $this->weather,
            'crew_count' => $this->crew_count,
            'has_issue' => (bool) $this->has_issue,
            'logged_by' => $this->whenLoaded('loggedBy', fn () => $this->loggedBy ? [
                'id' => $this->loggedBy->id,
                'name' => trim("{$this->loggedBy->first_name} {$this->loggedBy->last_name}"),
            ] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => trim("{$this->creator->first_name} {$this->creator->last_name}"),
            ] : null),
            'issues' => $this->whenLoaded('issues', fn () => $this->issues->map(fn ($issue) => [
                'id' => $issue->id,
                'title' => $issue->title,
                'severity' => $issue->severity,
                'status' => $issue->relationLoaded('status') && $issue->status ? [
                    'id' => $issue->status->id,
                    'code' => $issue->status->code,
                    'label' => $issue->status->label,
                ] : null,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
