<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderTermsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            // Derived, not stored: "is this the company default" is exactly
            // "does it have no project", so a column would only be able to
            // disagree with project_id.
            'is_default' => $this->project_id === null,
            'title' => $this->title,
            'body' => $this->body,
            // The same split a PO's frozen copy uses, so a preview here matches
            // what the document will print.
            'clauses' => $this->clauses(),

            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => trim("{$this->creator->first_name} {$this->creator->last_name}"),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
