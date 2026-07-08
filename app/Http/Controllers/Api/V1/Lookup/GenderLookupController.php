<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreGenderLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateGenderLookupRequest;
use App\Models\Gender;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class GenderLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return Gender::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return User::where('gender_id', $lookup->id)->exists();
    }

    public function show(Gender $gender): JsonResponse
    {
        return $this->handleShow($gender);
    }

    public function store(StoreGenderLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateGenderLookupRequest $request, Gender $gender): JsonResponse
    {
        return $this->handleUpdate($gender, $request->validated());
    }

    public function destroy(Gender $gender): JsonResponse
    {
        return $this->handleDestroy($gender);
    }
}
