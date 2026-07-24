<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Delivery\IndexDeliveryRequest;
use App\Http\Requests\Api\V1\Delivery\StoreDeliveryRequest;
use App\Http\Requests\Api\V1\Delivery\StoreDiscrepancyRequest;
use App\Http\Resources\Api\V1\DeliveryDetailResource;
use App\Http\Resources\Api\V1\DeliveryListResource;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Services\Delivery\DeliveryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DeliveryController extends Controller
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    /** GET /api/v1/deliveries */
    public function index(IndexDeliveryRequest $request): JsonResponse
    {
        $page = $this->deliveries->paginate($request->validated());

        return ApiResponse::success([
            'items' => DeliveryListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/deliveries/{delivery} */
    public function show(Delivery $delivery): JsonResponse
    {
        return ApiResponse::success(new DeliveryDetailResource($this->deliveries->findDetailed($delivery)), 'OK');
    }

    /** POST /api/v1/purchase-orders/{purchase_order}/deliveries — record a receipt. */
    public function store(StoreDeliveryRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        $delivery = $this->deliveries->record($purchase_order, $request->validated(), $request->user()->id);
        return ApiResponse::success(new DeliveryDetailResource($delivery), 'Delivery recorded.', 201);
    }

    /** POST /api/v1/deliveries/{delivery}/discrepancies — flag a discrepancy. */
    public function storeDiscrepancy(StoreDiscrepancyRequest $request, Delivery $delivery): JsonResponse
    {
        $delivery = $this->deliveries->addDiscrepancy($delivery, $request->validated(), $request->user()->id);
        return ApiResponse::success(new DeliveryDetailResource($delivery), 'Discrepancy recorded.', 201);
    }
}
