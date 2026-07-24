<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        $data = [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
        ];

        // sort_order / is_system / is_terminal are only present for tables that
        // actually have the column — omitted entirely elsewhere, not returned
        // as null/false (e.g. units, catalog_item_types have neither).
        if (array_key_exists('sort_order', $attributes)) {
            $data['sort_order'] = $this->sort_order;
        }

        if (array_key_exists('is_system', $attributes)) {
            $data['is_system'] = (bool) $this->is_system;
        }

        if (array_key_exists('is_terminal', $attributes)) {
            $data['is_terminal'] = (bool) $this->is_terminal;
        }

        return $data;
    }
}
