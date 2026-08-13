<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrder\IndexPendingRequestsRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\IndexPurchaseOrderRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Resources\Api\V1\MaterialRequestListResource;
use App\Http\Resources\Api\V1\PurchaseOrderDetailResource;
use App\Http\Resources\Api\V1\PurchaseOrderListResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrder\PurchaseOrderPdfService;
use App\Services\PurchaseOrder\PurchaseOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly PurchaseOrderPdfService $pdf,
    ) {}

    /**
     * GET /api/v1/purchase-orders/{purchase_order}/pdf — the PO document.
     *
     * Serves the STORED document once the order has been issued, so what a
     * vendor was sent can always be retrieved byte-for-byte. Before that there
     * is nothing stored, and a draft is still being edited, so it renders live
     * and prints with a DRAFT watermark.
     *
     * ?download=1 forces a save-as instead of inline display.
     */
    public function pdf(PurchaseOrder $purchase_order): Response
    {
        $stored = $this->pdf->storedDocument($purchase_order);

        $bytes = $stored && Storage::disk($stored->disk)->exists($stored->file_path)
            ? Storage::disk($stored->disk)->get($stored->file_path)
            : $this->pdf->render($purchase_order);

        $disposition = request()->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->pdf->fileName($purchase_order).'"',
        ]);
    }

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

    /**
     * GET /api/v1/purchase-orders/pending-requests
     *
     * The buyer's work queue — approved material requests across all projects
     * awaiting a PO, with the requester's free text and photos so a prose-only
     * request can be mapped to catalog lines here. Gated by
     * manage_purchase_orders rather than project membership, deliberately: see
     * PurchaseOrderService::pendingRequests().
     */
    public function pendingRequests(IndexPendingRequestsRequest $request): JsonResponse
    {
        $page = $this->purchaseOrders->pendingRequests($request->validated());

        return ApiResponse::success([
            'items' => MaterialRequestListResource::collection($page),
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
