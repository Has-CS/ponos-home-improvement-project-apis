<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreUrgencyLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateUrgencyLookupRequest;
use App\Models\ChangeOrder;
use App\Models\Urgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UrgencyLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return Urgency::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return ChangeOrder::where('urgency_id', $lookup->id)->exists()
            || DB::table('material_requests')->where('urgency_id', $lookup->id)->whereNull('deleted_at')->exists();
    }

    public function show(Urgency $urgency): JsonResponse
    {
        return $this->handleShow($urgency);
    }

    public function store(StoreUrgencyLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateUrgencyLookupRequest $request, Urgency $urgency): JsonResponse
    {
        return $this->handleUpdate($urgency, $request->validated());
    }

    public function destroy(Urgency $urgency): JsonResponse
    {
        return $this->handleDestroy($urgency);
    }
}
