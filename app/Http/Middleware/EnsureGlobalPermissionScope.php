<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class EnsureGlobalPermissionScope
{
    public function handle(Request $request, Closure $next): Response
    {
        // 0 is the "global" sentinel for the NOT-NULL team-key pivot columns
        // (model_has_roles/model_has_permissions); roles.project_id itself
        // stays NULL for a global role definition (see RoleSeeder).
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        return $next($request);
    }
}
