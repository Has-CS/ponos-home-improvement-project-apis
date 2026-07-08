<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'guard_name'  => $this->guard_name,
            'scope'       => $this->project_id === null ? 'global' : 'project',
            'project_id'  => $this->project_id,
            'permissions' => $this->whenLoaded('permissions', fn() =>
            $this->permissions->pluck('name')->values()),
        ];
    }
}
