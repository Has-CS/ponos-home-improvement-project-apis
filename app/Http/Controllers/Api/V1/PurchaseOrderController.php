<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrder\IndexPendingRequestsRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\IndexPurchaseOrderRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\StorePurchaseOrderAttachmentRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\StorePurchaseOrderItemRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\UpdatePurchaseOrderItemRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Resources\Api\V1\MaterialRequestListResource;
use App\Http\Resources\Api\V1\PurchaseOrderDetailResource;
use App\Http\Resources\Api\V1\PurchaseOrderListResource;
use App\Models\Attachment;
use App\Models\EmailLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrder\PurchaseOrderPdfMergeService;
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
        private readonly PurchaseOrderPdfMergeService $merge,
    ) {}

    /**
     * GET /api/v1/purchase-orders/{purchase_order}/pdf — the PO document.
     *
     * Serves the STORED document once the order has been issued, so what a
     * vendor was sent can always be retrieved byte-for-byte. Before that there
     * is nothing stored, and a draft is still being edited, so it renders live
     * and prints with a DRAFT watermark.
     *
     * ?preview=1 forces a live render even when a filed copy exists. The filed
     * bytes are never touched or replaced — this only changes what THIS response
     * returns.
     *
     * It exists because the two things the stored copy freezes are not the same
     * thing. The order's DATA is frozen by design and must never be re-rendered.
     * The TEMPLATE is not: change the Blade file — or a value the masthead reads
     * from config, such as the company address — and every already-issued order
     * keeps printing the old layout forever, with no way to see the new one
     * against real data short of cutting a fresh PO and issuing it. That is a
     * genuine gap for anyone reviewing a layout change, and it is what this flag
     * answers.
     *
     * Deliberately NOT a way to refresh the filed copy: an issued document must
     * stay exactly as issued, so nothing here writes. Mirrors
     * ChangeOrderController::pdf().
     *
     * ?download=1 forces a save-as instead of inline display.
     */
    public function pdf(PurchaseOrder $purchase_order): Response
    {
        $this->purchaseOrders->assertAccessible($purchase_order, request()->user());

        $stored = request()->boolean('preview')
            ? null
            : $this->pdf->storedDocument($purchase_order);

        $bytes = $stored && Storage::disk($stored->disk)->exists($stored->file_path)
            ? Storage::disk($stored->disk)->get($stored->file_path)
            : $this->pdf->render($purchase_order);

        // Supporting files are appended so the vendor gets ONE document. ?base=1
        // returns the order alone — useful for checking the order itself hasn't
        // changed. With no attachments, merge() hands back these exact bytes.
        if (! request()->boolean('base')) {
            $bytes = $this->merge->merge($purchase_order, $bytes);
        }

        $disposition = request()->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->pdf->fileName($purchase_order).'"',
        ]);
    }

    /** GET /api/v1/purchase-orders */
    public function index(IndexPurchaseOrderRequest $request): JsonResponse
    {
        $page = $this->purchaseOrders->paginate($request->user(), $request->validated());

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
        $page = $this->purchaseOrders->pendingRequests($request->user(), $request->validated());

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
        return ApiResponse::success(new PurchaseOrderDetailResource($this->purchaseOrders->findDetailed($purchase_order, request()->user())), 'OK');
    }

    /** POST /api/v1/purchase-orders — create from an approved material request. */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->purchaseOrders->create($request->validated(), $request->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order created.', 201);
    }

    /** PATCH /api/v1/purchase-orders/{purchase_order} — draft only. */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->update($purchase_order, $request->validated(), $request->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order updated.');
    }

    /** DELETE /api/v1/purchase-orders/{purchase_order} — draft only. */
    /**
     * POST /api/v1/purchase-orders/{purchase_order}/items
     *
     * Line items of a DRAFT order, mirroring the material-request item
     * endpoints. All three return the whole purchase order rather than the line
     * alone: every line edit moves `total_amount`, and a fresh line beside a
     * stale total is worse than one more round trip.
     */
    public function storeItem(StorePurchaseOrderItemRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->addItem($purchase_order, $request->validated(), $request->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Line item added.', 201);
    }

    /** PATCH /api/v1/purchase-orders/{purchase_order}/items/{item} */
    public function updateItem(UpdatePurchaseOrderItemRequest $request, PurchaseOrder $purchase_order, PurchaseOrderItem $item): JsonResponse
    {
        $this->assertItemInPurchaseOrder($purchase_order, $item);
        $po = $this->purchaseOrders->updateItem($purchase_order, $item, $request->validated(), $request->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Line item updated.');
    }

    /** DELETE /api/v1/purchase-orders/{purchase_order}/items/{item} */
    public function destroyItem(PurchaseOrder $purchase_order, PurchaseOrderItem $item): JsonResponse
    {
        $this->assertItemInPurchaseOrder($purchase_order, $item);
        $po = $this->purchaseOrders->removeItem($purchase_order, $item, request()->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Line item removed.');
    }

    /**
     * POST /api/v1/purchase-orders/{purchase_order}/attachments
     *
     * Supporting files (multipart `files[]`) that get appended to the PDF the
     * vendor receives. Returns the whole order so the client sees the updated
     * attachment list.
     */
    public function storeAttachments(StorePurchaseOrderAttachmentRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->addAttachments($purchase_order, $request->file('files'), $request->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Attachments added.', 201);
    }

    /** DELETE /api/v1/purchase-orders/{purchase_order}/attachments/{attachment} */
    public function destroyAttachment(PurchaseOrder $purchase_order, Attachment $attachment): JsonResponse
    {
        $this->assertAttachmentInPurchaseOrder($purchase_order, $attachment);
        $po = $this->purchaseOrders->removeAttachment($purchase_order, $attachment, request()->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Attachment removed.');
    }

    public function destroy(PurchaseOrder $purchase_order): JsonResponse
    {
        $this->purchaseOrders->delete($purchase_order, request()->user());
        return ApiResponse::success(null, 'Purchase order deleted.');
    }

    public function issue(PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->issue($purchase_order, request()->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order issued.');
    }

    public function send(PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->send($purchase_order, request()->user());

        // Same delivery block the RFQ submit response carries, so a client can
        // show who it went to and whether it actually left. Status is read from
        // the EmailLog, never assumed.
        $status = EmailLog::where('mailable_type', PurchaseOrder::class)
            ->where('mailable_id', $po->id)
            ->latest('id')
            ->value('status') ?? 'queued';

        $vendorName = $po->vendor?->name ?? 'the vendor';

        return ApiResponse::success(
            [
                ...(new PurchaseOrderDetailResource($po))->toArray(request()),
                'delivery' => [
                    'to' => $po->vendor?->email,
                    'vendor_name' => $po->vendor?->name,
                    'document' => $this->pdf->fileName($po),
                    'status' => $status,
                ],
            ],
            "{$po->po_number} has been sent to {$vendorName} with the purchase order document attached.",
        );
    }

    public function cancel(PurchaseOrder $purchase_order): JsonResponse
    {
        $po = $this->purchaseOrders->cancel($purchase_order, request()->user());
        return ApiResponse::success(new PurchaseOrderDetailResource($po), 'Purchase order cancelled.');
    }

    // ---- Guards ----

    /**
     * Both ids are bound independently, so a line from another order would
     * otherwise be edited through this order's URL. 404, not 403: as far as this
     * order is concerned the line does not exist. Mirrors
     * RfqController::assertItemInRfq().
     */
    private function assertItemInPurchaseOrder(PurchaseOrder $po, PurchaseOrderItem $item): void
    {
        abort_if($item->purchase_order_id !== $po->id, 404, 'Line item not found on this purchase order.');
    }

    /**
     * Both the type and the id are checked: the attachments table is polymorphic,
     * so without the type check a material-request photo with a matching id could
     * be deleted through a purchase-order URL.
     */
    private function assertAttachmentInPurchaseOrder(PurchaseOrder $po, Attachment $attachment): void
    {
        abort_if(
            $attachment->attachable_type !== PurchaseOrder::class
                || (int) $attachment->attachable_id !== $po->id
                || $attachment->attachment_type !== 'supporting',
            404,
            'Attachment not found on this purchase order.',
        );
    }
}
