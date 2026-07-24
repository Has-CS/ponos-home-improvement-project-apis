<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreMaterialRequestStatusLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateMaterialRequestStatusLookupRequest;
use App\Models\MaterialRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MaterialRequestStatusLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return MaterialRequestStatus::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return DB::table('material_requests')->where('material_request_status_id', $lookup->id)->whereNull('deleted_at')->exists();
    }

    public function show(MaterialRequestStatus $material_request_status): JsonResponse
    {
        return $this->handleShow($material_request_status);
    }

    public function store(StoreMaterialRequestStatusLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateMaterialRequestStatusLookupRequest $request, MaterialRequestStatus $material_request_status): JsonResponse
    {
        return $this->handleUpdate($material_request_status, $request->validated());
    }

    public function destroy(MaterialRequestStatus $material_request_status): JsonResponse
    {
        return $this->handleDestroy($material_request_status);
    }
}
