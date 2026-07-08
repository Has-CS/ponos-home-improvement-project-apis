<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rbac\StorePermissionRequest;
use App\Http\Requests\Api\V1\Rbac\StoreRoleRequest;
use App\Http\Requests\Api\V1\Rbac\UpdateRolePermissionsRequest;
use App\Http\Resources\Api\V1\PermissionResource;
use App\Http\Resources\Api\V1\RoleResource;
use App\Services\Rbac\RoleAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacController extends Controller
{
    public function __construct(private readonly RoleAssignmentService $rbac) {}

    /**
     * GET /api/v1/auth/roles-permissions — list every role and permission.
     */
    public function catalog(): JsonResponse
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0); // 0 = global sentinel (NOT-NULL pivot columns)

        $roles = Role::with('permissions')->get();
        $permissions = Permission::orderBy('name')->get();

        return ApiResponse::success([
            'roles'       => RoleResource::collection($roles),
            'permissions' => PermissionResource::collection($permissions),
        ], 'OK');
    }

    /**
     * GET /api/v1/auth/permissions — current user's effective access
     * (global + per-project). Also returned at login.
     */
    public function myAccess(): JsonResponse
    {
        $user = auth('api')->user();

        return ApiResponse::success(
            $this->rbac->effectiveAccess($user),
            'OK'
        );
    }

    /**
     * POST /api/v1/permissions — create a permission (runtime-extensible).
     */
    public function storePermission(StorePermissionRequest $request): JsonResponse
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0); // 0 = global sentinel (NOT-NULL pivot columns)

        $permission = Permission::create([
            'name' => $request->validated('name'),
            'guard_name' => 'api',
        ]);

        return ApiResponse::success(new PermissionResource($permission), 'Permission created.', 201);
    }

    /**
     * POST /api/v1/roles — create a global role, optionally with permissions.
     */
    public function storeRole(StoreRoleRequest $request): JsonResponse
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0); // 0 = global sentinel (NOT-NULL pivot columns)

        $role = Role::create([
            'name' => $request->validated('name'),
            'guard_name' => 'api',
            'project_id' => null, // role DEFINITIONS are global; NULL per roles migration (R-6)
        ]);

        if ($request->filled('permissions')) {
            $role->syncPermissions($request->validated('permissions'));
        }

        return ApiResponse::success(new RoleResource($role->load('permissions')), 'Role created.', 201);
    }

    /**
     * PUT /api/v1/roles/{role}/permissions — replace a role's permission set.
     */
    public function updateRolePermissions(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0); // 0 = global sentinel (NOT-NULL pivot columns)

        $role->syncPermissions($request->validated('permissions'));

        return ApiResponse::success(new RoleResource($role->load('permissions')), 'Role permissions updated.');
    }
}
