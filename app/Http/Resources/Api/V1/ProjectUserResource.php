<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'assignment_id' => $this->id,
            'project_id'    => $this->project_id,
            'is_active'     => (bool) $this->is_active,
            'assigned_at'   => $this->assigned_at?->toIso8601String(),
            'user'          => [
                'id'         => $this->user?->id,
                'first_name' => $this->user?->first_name,
                'last_name'  => $this->user?->last_name,
                'email'      => $this->user?->credential?->email,
            ],
            'role'          => [
                'id'   => $this->role?->id,
                'name' => $this->role?->name,
            ],
        ];
    }
}
