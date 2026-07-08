<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'sort_order' => $this->sort_order,
            'is_system' => (bool) $this->is_system,
        ];

        // Only present for tables that actually have this column (ProjectStatus,
        // MilestoneStatus) — omitted entirely elsewhere, not returned as null.
        if (array_key_exists('is_terminal', $this->resource->getAttributes())) {
            $data['is_terminal'] = (bool) $this->is_terminal;
        }

        return $data;
    }
}
