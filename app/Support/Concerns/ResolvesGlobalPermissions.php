<?php

namespace App\Support\Concerns;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

trait ResolvesGlobalPermissions
{
    /**
     * Run a callback with Spatie scoped to GLOBAL (team_id = null), then
     * restore the previous team context. Model role/permission relation
     * caches are cleared before and after so reads reflect the global scope.
     *
     * These admin user-management endpoints deal only with GLOBAL roles
     * (e.g. Admin). Project-scoped assignment is a separate concern handled
     * by the project_user wizard.
     */
    protected function withGlobalTeam(callable $callback, ?User $user = null): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId(0); // 0 = global sentinel (NOT-NULL pivot columns)
        $user?->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $user?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
