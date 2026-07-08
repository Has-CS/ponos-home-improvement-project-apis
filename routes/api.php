<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Lookup\GenderLookupController;
use App\Http\Controllers\Api\V1\Lookup\MilestonePhaseLookupController;
use App\Http\Controllers\Api\V1\Lookup\MilestoneStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\ProjectStatusLookupController;
use App\Http\Controllers\Api\V1\Lookup\ProjectTypeLookupController;
use App\Http\Controllers\Api\V1\Lookup\UserStatusLookupController;
use App\Http\Controllers\Api\V1\MilestoneController;
use App\Http\Controllers\Api\V1\ProjectAssignmentController;
use App\Http\Controllers\Api\V1\ProjectChangeOrderController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RbacController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---- Public ----
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/reset-password-request', [AuthController::class, 'resetPasswordRequest']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    // ---- Authenticated (single outer group: JWT guard + global permission scope) ----
    Route::middleware(['auth:api', 'team.global'])->group(function () {

        // ---- Auth (any authenticated user) ----
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/roles-permissions', [RbacController::class, 'catalog']);
        Route::get('auth/permissions', [RbacController::class, 'myAccess']);

        // ---- Users (gated by the `manage_users` capability, not a hardcoded role —
        // reassignable at runtime via PUT /roles/{role}/permissions or the
        // per-user `permissions` field on PATCH /users/{user}, no route change needed) ----
        Route::middleware('permission:manage_users')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::patch('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
        });

        // ---- RBAC administration (Admin / PM) — intentionally role-gated, not
        // permission-gated: these actions define/grant permissions themselves
        // (roles catalog, global role assignment, project staffing), so gating
        // them by permission would let a holder grant themselves broader access —
        // a privilege-escalation path. No permission in the seeded catalog maps
        // to "administer RBAC" for this reason; identity-based gating stays here
        // by design.

        Route::middleware('role:Admin|Project Manager')->group(function () {
            Route::post('permissions', [RbacController::class, 'storePermission']);
            Route::post('roles', [RbacController::class, 'storeRole']);
            Route::put('roles/{role}/permissions', [RbacController::class, 'updateRolePermissions']);

            Route::post('users/{user}/assign-role', [UserRoleController::class, 'assign']);
            Route::delete('users/{user}/roles/{role}', [UserRoleController::class, 'revoke']);

            // Project staffing (writes project_user + model_has_roles)
            Route::post('projects/{project}/assign-user', [ProjectAssignmentController::class, 'assign']);
            Route::delete('projects/{project}/users/{user}', [ProjectAssignmentController::class, 'remove']);
            Route::delete('projects/{project}/users/{user}/roles/{role}', [ProjectAssignmentController::class, 'revokeRole']);
        });

        // ---- Projects: any authenticated user ----
        Route::get('projects/mine', [ProjectController::class, 'mine']);      // switchable projects
        Route::get('projects', [ProjectController::class, 'index']);          // scoped list

        // ---- Lookup tables (genders, statuses, types, phases): dropdown/
        // reference data. Index open to any authenticated user, no
        // project-membership check — these are global reference tables, not
        // project-scoped. Writes gated by manage_lookups (Admin-only).
        Route::get('genders', [GenderLookupController::class, 'index']);
        Route::get('genders/{gender}', [GenderLookupController::class, 'show']);
        Route::get('user-statuses', [UserStatusLookupController::class, 'index']);
        Route::get('user-statuses/{user_status}', [UserStatusLookupController::class, 'show']);
        Route::get('project-statuses', [ProjectStatusLookupController::class, 'index']);
        Route::get('project-statuses/{project_status}', [ProjectStatusLookupController::class, 'show']);
        Route::get('project-types', [ProjectTypeLookupController::class, 'index']);
        Route::get('project-types/{project_type}', [ProjectTypeLookupController::class, 'show']);
        Route::get('milestone-phases', [MilestonePhaseLookupController::class, 'index']);
        Route::get('milestone-phases/{milestone_phase}', [MilestonePhaseLookupController::class, 'show']);
        Route::get('milestone-statuses', [MilestoneStatusLookupController::class, 'index']);
        Route::get('milestone-statuses/{milestone_status}', [MilestoneStatusLookupController::class, 'show']);

        Route::middleware('permission:manage_lookups')->group(function () {
            Route::post('genders', [GenderLookupController::class, 'store']);
            Route::patch('genders/{gender}', [GenderLookupController::class, 'update']);
            Route::delete('genders/{gender}', [GenderLookupController::class, 'destroy']);

            Route::post('user-statuses', [UserStatusLookupController::class, 'store']);
            Route::patch('user-statuses/{user_status}', [UserStatusLookupController::class, 'update']);
            Route::delete('user-statuses/{user_status}', [UserStatusLookupController::class, 'destroy']);

            Route::post('project-statuses', [ProjectStatusLookupController::class, 'store']);
            Route::patch('project-statuses/{project_status}', [ProjectStatusLookupController::class, 'update']);
            Route::delete('project-statuses/{project_status}', [ProjectStatusLookupController::class, 'destroy']);

            Route::post('project-types', [ProjectTypeLookupController::class, 'store']);
            Route::patch('project-types/{project_type}', [ProjectTypeLookupController::class, 'update']);
            Route::delete('project-types/{project_type}', [ProjectTypeLookupController::class, 'destroy']);

            Route::post('milestone-phases', [MilestonePhaseLookupController::class, 'store']);
            Route::patch('milestone-phases/{milestone_phase}', [MilestonePhaseLookupController::class, 'update']);
            Route::delete('milestone-phases/{milestone_phase}', [MilestonePhaseLookupController::class, 'destroy']);

            Route::post('milestone-statuses', [MilestoneStatusLookupController::class, 'store']);
            Route::patch('milestone-statuses/{milestone_status}', [MilestoneStatusLookupController::class, 'update']);
            Route::delete('milestone-statuses/{milestone_status}', [MilestoneStatusLookupController::class, 'destroy']);
        });

        // ---- Projects: single-project reads — must have access to THIS project.
        // NOTE: intentionally NOT permission-gated in addition to `project.access`.
        // A user's role/permission grant for a specific project lives under that
        // project's Spatie team scope, but `team.global` (wrapping this whole
        // group) fixes the scope to global (0) — so a permission check here would
        // evaluate the wrong scope and could reject a legitimately project-scoped
        // member. `project.access` already enforces membership directly against
        // project_user, independent of Spatie scope, which is correct as-is.
        Route::middleware('project.access')->group(function () {
            Route::get('projects/{project}', [ProjectController::class, 'show']);
            Route::get('projects/{project}/users', [ProjectAssignmentController::class, 'users']);
            Route::get('projects/{project}/milestones', [MilestoneController::class, 'index']);
            Route::get('projects/{project}/change-orders', [ProjectChangeOrderController::class, 'index']);
        });

        // ---- Projects: management writes — gated by capability, evaluated
        // under the global scope this whole group already runs under (same
        // scope the old role:Admin gate used, so this is a drop-in replacement,
        // not a new scoping concern).
        Route::middleware('permission:create_project')->group(function () {
            Route::post('projects', [ProjectController::class, 'store']);
        });

        Route::middleware('permission:edit_project')->group(function () {
            Route::patch('projects/{project}', [ProjectController::class, 'update']);
            Route::patch('projects/{project}/status', [ProjectController::class, 'updateStatus']);
        });

        Route::middleware('permission:delete_project')->group(function () {
            Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
        });

        // Milestones (writes) — gated by the manage_milestones capability, evaluated
        // under the global scope this whole outer group runs under (same scope the
        // old role:Admin gate used, so this is a drop-in replacement).
        Route::middleware('permission:manage_milestones')->group(function () {
            Route::post('projects/{project}/milestones', [MilestoneController::class, 'store']);
            Route::patch('projects/{project}/milestones/{milestone}', [MilestoneController::class, 'update']);
            Route::delete('projects/{project}/milestones/{milestone}', [MilestoneController::class, 'destroy']);
        });
    });
});
