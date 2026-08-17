<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeOrderTermsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paragraphs = $this->paragraphs();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'is_default' => $this->isDefault(),

            'payment_terms_body' => $this->payment_terms_body,
            'changes_body' => $this->changes_body,
            'acceptance_body' => $this->acceptance_body,

            // Pre-split so the API and the PDF break each body into paragraphs by
            // identical rules — neither re-derives it.
            'payment_terms_paragraphs' => $paragraphs['payment_terms'],
            'changes_paragraphs' => $paragraphs['changes'],
            'acceptance_paragraphs' => $paragraphs['acceptance'],

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
