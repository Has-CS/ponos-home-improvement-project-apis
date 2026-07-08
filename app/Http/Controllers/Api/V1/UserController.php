<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\IndexUserRequest;
use App\Http\Requests\Api\V1\User\StoreUserRequest;
use App\Http\Requests\Api\V1\User\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserDetailResource;
use App\Models\User;
use App\Services\User\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(private readonly UserService $userService) {}

    /**
     * GET /api/v1/users  — Admin only. Paginated list.
     */
    public function index(IndexUserRequest $request): JsonResponse
    {
        $users = $this->userService->paginate($request->validated());

        return ApiResponse::success([
            'items'      => UserDetailResource::collection($users),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
                'last_page'    => $users->lastPage(),
            ],
        ], 'OK');
    }

    /**
     * POST /api/v1/users  — admin-create-user (R-2).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create(
            $request->validated(),
            $request->user()->id,
        );

        return ApiResponse::success(
            new UserDetailResource($user),
            'User created successfully.',
            201,
        );
    }

    /**
     * GET /api/v1/users/{user}  — Admin only. Single user.
     * Route model binding auto-404s on a missing/soft-deleted id.
     */
    public function show(User $user): JsonResponse
    {
        $user = $this->userService->findDetailed($user);

        return ApiResponse::success(new UserDetailResource($user), 'OK');
    }

    /**
     * PATCH /api/v1/users/{user}  — Admin only. Partial update.
     *
     * NOTE: to upload a `picture` (multipart/form-data), PHP does not parse
     * multipart bodies for real PATCH requests. Send a POST with a `_method=PATCH`
     * field instead (Laravel's method-spoofing convention) — the route still
     * dispatches here. Plain JSON/x-www-form-urlencoded PATCH requests (no file)
     * work as a genuine PATCH.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated());

        return ApiResponse::success(new UserDetailResource($user), 'User updated successfully.');
    }

    /**
     * DELETE /api/v1/users/{user}  — Admin only. Soft delete.
     */
    public function destroy(User $user): JsonResponse
    {
        $this->userService->delete($user);

        return ApiResponse::success(null, 'User deleted successfully.');
    }
}
