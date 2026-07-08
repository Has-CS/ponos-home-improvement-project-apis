<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Support\ApiResponse;
use App\Support\Concerns\ScopesProjectAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must be registered behind `team.global` (EnsureGlobalPermissionScope) in the
 * route middleware stack — this middleware's ScopesProjectAccess::isGlobalAdmin()
 * check trusts that the permissions team scope is already set to global (0).
 */
class EnsureProjectAccess
{
    use ScopesProjectAccess;

    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');
        $projectId = $project instanceof Project ? $project->id : (int) $project;
        $user = $request->user();

        if (! $user || ! $this->canAccessProject($user, $projectId)) {
            return ApiResponse::error('You do not have access to this project.', 403);
        }

        return $next($request);
    }
}
