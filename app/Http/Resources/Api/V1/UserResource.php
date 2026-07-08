<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'first_name'  => $this->first_name,
            'last_name'   => $this->last_name,
            'email'       => $this->whenLoaded('credential', fn() => $this->credential->email),
            'gender'      => $this->whenLoaded('gender', fn() => $this->gender?->label),
            'status'      => $this->whenLoaded('status', fn() => $this->status?->code),
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'picture_url' => $this->picture_path ? Storage::disk('public')->url($this->picture_path) : null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
