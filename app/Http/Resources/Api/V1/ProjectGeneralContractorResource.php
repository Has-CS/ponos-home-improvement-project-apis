<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectGeneralContractorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'street_1' => $this->street_1,
            'street_2' => $this->street_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
            'is_primary' => (bool) $this->is_primary,

            // Pre-formatted so the API and the change-order PDF cannot drift —
            // neither re-derives the layout.
            'address_lines' => $this->addressLines(),

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => trim("{$this->creator->first_name} {$this->creator->last_name}"),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
