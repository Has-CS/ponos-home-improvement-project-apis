<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreChangeOrderStatusLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateChangeOrderStatusLookupRequest;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderApproval;
use App\Models\ChangeOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class ChangeOrderStatusLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return ChangeOrderStatus::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return ChangeOrder::where('change_order_status_id', $lookup->id)->exists()
            || ChangeOrderApproval::where('from_status_id', $lookup->id)
                ->orWhere('to_status_id', $lookup->id)
                ->exists();
    }

    public function show(ChangeOrderStatus $change_order_status): JsonResponse
    {
        return $this->handleShow($change_order_status);
    }

    public function store(StoreChangeOrderStatusLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateChangeOrderStatusLookupRequest $request, ChangeOrderStatus $change_order_status): JsonResponse
    {
        return $this->handleUpdate($change_order_status, $request->validated());
    }

    public function destroy(ChangeOrderStatus $change_order_status): JsonResponse
    {
        return $this->handleDestroy($change_order_status);
    }
}
