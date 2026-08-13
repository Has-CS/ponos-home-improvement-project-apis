<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreChangeOrderTypeLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateChangeOrderTypeLookupRequest;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class ChangeOrderTypeLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return ChangeOrderType::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return ChangeOrder::where('change_order_type_id', $lookup->id)->exists();
    }

    public function show(ChangeOrderType $change_order_type): JsonResponse
    {
        return $this->handleShow($change_order_type);
    }

    public function store(StoreChangeOrderTypeLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateChangeOrderTypeLookupRequest $request, ChangeOrderType $change_order_type): JsonResponse
    {
        return $this->handleUpdate($change_order_type, $request->validated());
    }

    public function destroy(ChangeOrderType $change_order_type): JsonResponse
    {
        return $this->handleDestroy($change_order_type);
    }
}
