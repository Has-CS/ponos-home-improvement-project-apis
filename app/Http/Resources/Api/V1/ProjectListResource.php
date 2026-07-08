<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'code'   => $this->code,
            'name'   => $this->name,
            'budget' => $this->budget,
            'client' => $this->whenLoaded('client', fn() => $this->client?->name),
            'type'   => $this->whenLoaded('type', fn() => $this->type?->label),
            'status' => $this->whenLoaded('status', fn() => $this->status ? [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'label' => $this->status->label,
            ] : null),
            'start_date' => $this->start_date?->toDateString(),
            'end_date'   => $this->end_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
