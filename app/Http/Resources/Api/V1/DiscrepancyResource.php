<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscrepancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->delivery_id,
            'delivery_item_id' => $this->delivery_item_id,
            'description' => $this->description,
            'type' => $this->whenLoaded('discrepancyType', fn () => [
                'id' => $this->discrepancyType->id,
                'code' => $this->discrepancyType->code,
                'label' => $this->discrepancyType->label,
            ]),
            'reported_by' => $this->whenLoaded('reportedBy', fn () => $this->reportedBy ? [
                'id' => $this->reportedBy->id,
                'name' => trim("{$this->reportedBy->first_name} {$this->reportedBy->last_name}"),
            ] : null),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
