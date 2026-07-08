<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'email'         => $this->credential?->email,
            'picture_url'   => $this->picture_path ? Storage::disk('public')->url($this->picture_path) : null,
            'gender'        => $this->whenLoaded('gender', fn() => $this->gender ? [
                'id'    => $this->gender->id,
                'code'  => $this->gender->code,
                'label' => $this->gender->label,
            ] : null),
            'status'        => $this->whenLoaded('status', fn() => $this->status ? [
                'id'    => $this->status->id,
                'code'  => $this->status->code,
                'label' => $this->status->label,
            ] : null),
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'must_change_password' => (bool) ($this->credential?->must_change_password),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),

            // Populated by the service under global team scope.
            'roles'         => $this->getRoleNames(),
            'permissions'   => $this->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
