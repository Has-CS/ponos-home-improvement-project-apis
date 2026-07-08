<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreUserStatusLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateUserStatusLookupRequest;
use App\Models\User;
use App\Models\UserStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class UserStatusLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return UserStatus::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return User::where('user_status_id', $lookup->id)->exists();
    }

    public function show(UserStatus $user_status): JsonResponse
    {
        return $this->handleShow($user_status);
    }

    public function store(StoreUserStatusLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateUserStatusLookupRequest $request, UserStatus $user_status): JsonResponse
    {
        return $this->handleUpdate($user_status, $request->validated());
    }

    public function destroy(UserStatus $user_status): JsonResponse
    {
        return $this->handleDestroy($user_status);
    }
}
