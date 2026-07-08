<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rbac\AssignProjectUserRequest;
use App\Http\Resources\Api\V1\ProjectMemberResource;
use App\Http\Resources\Api\V1\ProjectUserResource;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class ProjectAssignmentController extends Controller
{
    public function __construct(private readonly RoleAssignmentService $rbac) {}

    /**
     * POST /api/v1/projects/{project}/assign-user
     * Assign a user to a project with a role (writes project_user + model_has_roles).
     */
    public function assign(AssignProjectUserRequest $request, Project $project): JsonResponse
    {
        $user = User::findOrFail($request->validated('user_id'));
        $roleId = $request->validated('role_id');

        if (! $roleId) {
            $globalRoles = $this->rbac->globalRoles($user);

            if ($globalRoles->count() !== 1) {
                return ApiResponse::error(
                    $globalRoles->isEmpty()
                        ? 'This user has no default role yet. Specify a role for this project assignment.'
                        : 'This user has multiple default roles. Specify which one to use on this project.',
                    422,
                );
            }

            $role = $globalRoles->first();
        } else {
            $role = Role::where('id', $roleId)
                ->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
        }

        try {
            $projectUser = $this->rbac->assignProjectRole(
                $project,
                $user,
                $role,
                auth('api')->id(),
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(
            new ProjectUserResource($projectUser->load(['user.credential', 'role'])),
            'User assigned to project.',
            201,
        );
    }

    /**
     * GET /api/v1/projects/{project}/users — active members for a project,
     * one entry per user with all of their active roles grouped together.
     */
    public function users(Project $project): JsonResponse
    {
        $assignments = ProjectUser::with(['user.credential', 'role'])
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->get();

        $members = $assignments->groupBy('user_id')->values();

        return ApiResponse::success(
            ProjectMemberResource::collection($members),
            $assignments->isEmpty() ? 'No users are currently assigned to this project.' : 'OK',
        );
    }

    /**
     * DELETE /api/v1/projects/{project}/users/{user} — remove a user from a project
     * entirely (deactivates every role they hold on it).
     */
    public function remove(Project $project, User $user): JsonResponse
    {
        $this->rbac->removeUserFromProject($project, $user);

        return ApiResponse::success(null, 'User removed from project.');
    }

    /**
     * DELETE /api/v1/projects/{project}/users/{user}/roles/{role} — revoke a
     * single role from a user on a project, leaving their other roles there intact.
     */
    public function revokeRole(Project $project, User $user, Role $role): JsonResponse
    {
        $exists = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            return ApiResponse::error('User does not hold this role on this project.', 404);
        }

        $this->rbac->revokeProjectRole($project, $user, $role);

        return ApiResponse::success(null, 'Role revoked from project assignment.');
    }
}
