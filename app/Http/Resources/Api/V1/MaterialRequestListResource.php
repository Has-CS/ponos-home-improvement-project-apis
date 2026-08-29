<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialRequestListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_no' => $this->request_no,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
                'is_terminal' => (bool) $this->status->is_terminal,
            ]),
            'urgency' => $this->whenLoaded('urgency', fn () => [
                'id' => $this->urgency->id,
                'code' => $this->urgency->code,
                'label' => $this->urgency->label,
            ]),
            'requester' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => trim("{$this->requester->first_name} {$this->requester->last_name}"),
            ] : null),
            // Loaded only by the cross-project buyer queue; omitted from the
            // project-scoped list, where the project is already implied.
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ] : null),
            'needed_by_date' => $this->needed_by_date?->toDateString(),

            // The requester's own words, and any photos they attached. Present
            // here — not just on the detail payload — because the buyer queue
            // has to be actionable without opening every row.
            'request_text' => $this->request_text,
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id' => $p->id,
                'file_name' => $p->file_name,
                'mime_type' => $p->mime_type,
                'url' => url("/api/v1/attachments/{$p->id}"),
            ])),

            'items_count' => $this->whenCounted('items'),
            'photos_count' => $this->whenCounted('photos'),
            // Lets an office user filter the queue for requests that arrived as
            // prose and still have to be mapped to catalog items. Derived from
            // the counts already loaded — no extra query, nothing stored.
            'needs_structuring' => $this->whenCounted('items', fn () => filled($this->request_text) && $this->items_count === 0),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
