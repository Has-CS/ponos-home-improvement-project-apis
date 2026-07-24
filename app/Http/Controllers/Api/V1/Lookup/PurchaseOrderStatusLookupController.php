<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StorePurchaseOrderStatusLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdatePurchaseOrderStatusLookupRequest;
use App\Models\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PurchaseOrderStatusLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return PurchaseOrderStatus::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return DB::table('purchase_orders')->where('purchase_order_status_id', $lookup->id)->whereNull('deleted_at')->exists();
    }

    public function show(PurchaseOrderStatus $purchase_order_status): JsonResponse
    {
        return $this->handleShow($purchase_order_status);
    }

    public function store(StorePurchaseOrderStatusLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdatePurchaseOrderStatusLookupRequest $request, PurchaseOrderStatus $purchase_order_status): JsonResponse
    {
        return $this->handleUpdate($purchase_order_status, $request->validated());
    }

    public function destroy(PurchaseOrderStatus $purchase_order_status): JsonResponse
    {
        return $this->handleDestroy($purchase_order_status);
    }
}
