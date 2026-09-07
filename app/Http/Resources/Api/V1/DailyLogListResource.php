<?php

namespace App\Http\Resources\Api\V1;

use App\Support\ProjectRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class DailyLogListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'log_date' => $this->log_date?->toDateString(),
            'work_description' => Str::limit((string) $this->work_description, 140),
            'weather' => $this->weather,
            'crew_count' => $this->crew_count,
            'has_issue' => (bool) $this->has_issue,
            // Count only — the list deliberately does not carry photo rows. The
            // detail endpoint serves the URLs when someone opens a log.
            'photos_count' => $this->whenCounted('photos'),
            'logged_by' => $this->whenLoaded('loggedBy', fn () => $this->loggedBy ? [
                'id' => $this->loggedBy->id,
                'name' => trim("{$this->loggedBy->first_name} {$this->loggedBy->last_name}"),
                'role' => ProjectRole::label($this->loggedBy, $this->project_id),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
