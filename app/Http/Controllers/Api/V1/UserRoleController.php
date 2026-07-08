<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rbac\AssignGlobalRoleRequest;
use App\Http\Resources\Api\V1\UserDetailResource;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class UserRoleController extends Controller
{
    public function __construct(private readonly RoleAssignmentService $rbac) {}

    /**
     * POST /api/v1/users/{user}/assign-role — assign a GLOBAL role.
     */
    public function assign(AssignGlobalRoleRequest $request, User $user): JsonResponse
    {
        $role = Role::findOrFail($request->validated('role_id'));
        $user = $this->rbac->assignGlobalRole($user, $role);

        return ApiResponse::success(
            new UserDetailResource($user),
            'Global role assigned.'
        );
    }

    /**
     * DELETE /api/v1/users/{user}/roles/{role} — revoke a GLOBAL role.
     */
    public function revoke(User $user, Role $role): JsonResponse
    {
        $this->rbac->revokeGlobalRole($user, $role);

        return ApiResponse::success(null, 'Global role revoked.');
    }
}
