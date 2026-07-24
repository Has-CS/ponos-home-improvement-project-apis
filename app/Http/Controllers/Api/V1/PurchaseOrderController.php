<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrder\IndexPurchaseOrderRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Resources\Api\V1\PurchaseOrderDetailResource;
use App\Http\Resources\Api\V1\PurchaseOrderListResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrder\PurchaseOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $purchaseOrders) {}

    /** GET /api/v1/purchase-orders */
    public function index(IndexPurchaseOrderRequest $request): JsonResponse
    {
        $page = $this->purchaseOrders->paginate($request->validated());

        return ApiResponse::success([
            'items' => PurchaseOrderListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/purchase-orders/{purchase_order} */
    public function show(PurchaseOrder $purchase_order): JsonResponse
    {
        return ApiResponse::success(new PurchaseOrderDetailResource($this->purchaseOrders->findDetailed($purchase_order)), 'OK');
    }

    /** POST /api/v1/purchase-orders — create from an approved material request. */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->purchaseOrders->create($request->validated(), $request->user()->id);
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order created.', 201);
    }

    /** PATCH /api/v1/purchase-orders/{purchase_order} — draft only. */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->update($purchase_order, $request->validated());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order updated.');
    }

    /** DELETE /api/v1/purchase-orders/{purchase_order} — draft only. */
    public function destroy(PurchaseOrder $purchase_order): JsonResponse
    {
        $this->purchaseOrders->delete($purchase_order);
        return ApiResponse::success(null, 'Purchase order deleted.');
    }

    public function issue(PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->issue($purchase_order, request()->user()->id);
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order issued.');
    }

    public function send(PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->send($purchase_order);
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order marked as sent.');
    }

    public function cancel(PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->cancel($purchase_order);
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order cancelled.');
    }
}
