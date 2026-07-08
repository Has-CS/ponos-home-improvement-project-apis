<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One project member with all of their active roles on that project grouped
 * together, instead of one duplicate row per role (see ProjectUserResource,
 * which still returns a single flat row — used by the single-assignment
 * create response, where grouping doesn't apply).
 */
class ProjectMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection $assignments all active project_user rows for one user */
        $assignments = $this->resource;
        $first = $assignments->first();

        return [
            'user' => [
                'id'         => $first->user?->id,
                'first_name' => $first->user?->first_name,
                'last_name'  => $first->user?->last_name,
                'email'      => $first->user?->credential?->email,
            ],
            'roles' => $assignments->map(fn($assignment) => [
                'assignment_id' => $assignment->id,
                'role'          => [
                    'id'   => $assignment->role?->id,
                    'name' => $assignment->role?->name,
                ],
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
