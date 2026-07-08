<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequestRequest;
use App\Http\Resources\Api\V1\AuthUserResource;
use App\Services\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly \App\Services\Auth\PasswordResetService $passwordResetService,
    ) {}

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->validated('email'),
            $request->validated('password'),
            $request,
        );

        $user = $result['user']->load(['credential', 'status']);

        // Global roles/permissions live under the team-scope sentinel (0), not
        // whatever the ambient scope is at request start — see me().
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(0);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $roles = $user->getRoleNames();
        $permissions = $user->getAllPermissions()->pluck('name');
        $registrar->setPermissionsTeamId($previous);

        return ApiResponse::success([
            'token'       => $result['token'],
            'token_type'  => 'bearer',
            'expires_in' => config('jwt.ttl') * 60, // seconds
            'user'        => new AuthUserResource($user),
            'roles'       => $roles,
            'permissions' => $permissions,
        ], 'Login successful.');
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    /**
     * GET /api/v1/auth/me  — any authenticated user.
     */
    public function me(): JsonResponse
    {
        $user = $this->authService->me(); // loads credential, status, gender

        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(0); // 0 = global sentinel (NOT-NULL pivot columns)
        $user->unsetRelation('roles')->unsetRelation('permissions')
            ->load(['roles', 'permissions', 'roles.permissions']);
        $registrar->setPermissionsTeamId($previous);

        return ApiResponse::success(
            new \App\Http\Resources\Api\V1\UserDetailResource($user),
            'OK'
        );
    }

    /**
     * POST /api/v1/auth/refresh
     */
    public function refresh(): JsonResponse
    {
        $result = $this->authService->refresh();
        $user = $result['user'];

        return ApiResponse::success([
            'token'      => $result['token'],
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => new AuthUserResource($user),
        ], 'Token refreshed.');
    }

    /**
     * POST /api/v1/auth/reset-password-request
     * Always returns the same generic success (no account enumeration).
     */
    public function resetPasswordRequest(
        ResetPasswordRequestRequest $request
    ): JsonResponse {
        $this->passwordResetService->sendResetLink($request->validated('email'));

        return ApiResponse::success(
            null,
            'If an account exists for that email, a password reset link has been sent.'
        );
    }
    /**
     * POST /api/v1/auth/change-password
     */
    public function changePassword(
        ChangePasswordRequest $request
    ): JsonResponse {
        $this->passwordResetService->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return ApiResponse::success(null, 'Password has been reset. You can now log in.');
    }
}
