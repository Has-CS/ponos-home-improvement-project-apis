<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreGcDecisionLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateGcDecisionLookupRequest;
use App\Models\ChangeOrder;
use App\Models\GcDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class GcDecisionLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return GcDecision::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return ChangeOrder::where('gc_decision_id', $lookup->id)->exists();
    }

    public function show(GcDecision $gc_decision): JsonResponse
    {
        return $this->handleShow($gc_decision);
    }

    public function store(StoreGcDecisionLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateGcDecisionLookupRequest $request, GcDecision $gc_decision): JsonResponse
    {
        return $this->handleUpdate($gc_decision, $request->validated());
    }

    public function destroy(GcDecision $gc_decision): JsonResponse
    {
        return $this->handleDestroy($gc_decision);
    }
}
