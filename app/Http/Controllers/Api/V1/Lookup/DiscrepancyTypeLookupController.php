<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreDiscrepancyTypeLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateDiscrepancyTypeLookupRequest;
use App\Models\DiscrepancyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DiscrepancyTypeLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return DiscrepancyType::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return DB::table('discrepancies')->where('discrepancy_type_id', $lookup->id)->whereNull('deleted_at')->exists();
    }

    public function show(DiscrepancyType $discrepancy_type): JsonResponse
    {
        return $this->handleShow($discrepancy_type);
    }

    public function store(StoreDiscrepancyTypeLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateDiscrepancyTypeLookupRequest $request, DiscrepancyType $discrepancy_type): JsonResponse
    {
        return $this->handleUpdate($discrepancy_type, $request->validated());
    }

    public function destroy(DiscrepancyType $discrepancy_type): JsonResponse
    {
        return $this->handleDestroy($discrepancy_type);
    }
}
