<?php

namespace App\Support\Concerns;

use App\Models\ProjectUser;
use App\Models\User;

trait ScopesProjectAccess
{
    /**
     * True if the user holds the global Admin role (sees everything).
     *
     * Relies on the `team.global` middleware (EnsureGlobalPermissionScope) having
     * already set the permissions team scope to 0 for this request — every caller
     * of this trait is reached only through routes wrapped in that middleware, so
     * scope is not re-established here.
     */
    protected function isGlobalAdmin(User $user): bool
    {
        $user->unsetRelation('roles');

        return $user->hasRole('Admin');
    }

    /**
     * IDs of projects the user has active access to (empty for admin → means "all").
     *
     * @return array<int>
     */
    public function accessibleProjectIds(User $user): array
    {
        return ProjectUser::where('user_id', $user->id)
            ->where('is_active', true)
            ->distinct()
            ->pluck('project_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    protected function canAccessProject(User $user, int $projectId): bool
    {
        return $this->isGlobalAdmin($user)
            || in_array($projectId, $this->accessibleProjectIds($user), true);
    }
}
