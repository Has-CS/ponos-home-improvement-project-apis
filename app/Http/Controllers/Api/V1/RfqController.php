<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rfq\IndexRfqRequest;
use App\Http\Requests\Api\V1\Rfq\SearchRfqCatalogItemRequest;
use App\Http\Requests\Api\V1\Rfq\StoreRfqItemRequest;
use App\Http\Requests\Api\V1\Rfq\StoreRfqRequest;
use App\Http\Requests\Api\V1\Rfq\UpdateRfqItemRequest;
use App\Http\Requests\Api\V1\Rfq\UpdateRfqRequest;
use App\Http\Resources\Api\V1\PurchaseOrderCatalogItemSearchResource;
use App\Http\Resources\Api\V1\RfqDetailResource;
use App\Http\Resources\Api\V1\RfqItemResource;
use App\Http\Resources\Api\V1\RfqListResource;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Services\CatalogItem\CatalogItemService;
use App\Services\Rfq\RfqPdfService;
use App\Services\Rfq\RfqService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class RfqController extends Controller
{
    public function __construct(
        private readonly RfqService $rfqs,
        private readonly RfqPdfService $pdf,
        private readonly CatalogItemService $catalogItems,
    ) {}

    /** GET /api/v1/rfqs */
    public function index(IndexRfqRequest $request): JsonResponse
    {
        $page = $this->rfqs->paginate($request->validated());

        return ApiResponse::success([
            'items' => RfqListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/rfqs/{rfq} */
    public function show(Rfq $rfq): JsonResponse
    {
        return ApiResponse::success(new RfqDetailResource($this->rfqs->findDetailed($rfq)), 'OK');
    }

    /**
     * GET /api/v1/rfqs/{rfq}/pdf
     *
     * Serves the STORED document once the RFQ has been sent, so what the
     * vendor was emailed can always be retrieved byte-for-byte. Before that
     * there is nothing stored, and a draft is still being built, so it renders
     * live and prints with a DRAFT watermark.
     *
     * ?download=1 forces a save-as instead of inline display.
     */
    public function pdf(Rfq $rfq): Response
    {
        $stored = $this->pdf->storedDocument($rfq);

        $bytes = $stored && Storage::disk($stored->disk)->exists($stored->file_path)
            ? Storage::disk($stored->disk)->get($stored->file_path)
            : $this->pdf->render($rfq);

        $disposition = request()->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->pdf->fileName($rfq).'"',
        ]);
    }

    /**
     * GET /api/v1/rfqs/{rfq}/catalog-items/search
     *
     * Type-ahead item picker for RFQ lines. Reuses CatalogItemService::search()
     * scoped to this RFQ's own project (nullable — an RFQ is pre-project by
     * default) and target vendor, so no project_id/vendor_id query params are
     * needed. Returns PurchaseOrderCatalogItemSearchResource's shape (has_rate/
     * current_rate against the target vendor): useful context here too — "we
     * don't have a rate for this yet, that's why we're asking."
     */
    public function searchCatalogItems(SearchRfqCatalogItemRequest $request, Rfq $rfq): JsonResponse
    {
        $result = $this->catalogItems->search($rfq->project, $request->validated(), $rfq->vendor_id);

        return ApiResponse::success([
            'items' => PurchaseOrderCatalogItemSearchResource::collection($result['items']),
            'limit' => $result['limit'],
            'has_more' => $result['has_more'],
        ], 'OK');
    }

    /** POST /api/v1/rfqs */
    public function store(StoreRfqRequest $request): JsonResponse
    {
        $rfq = $this->rfqs->create($request->validated(), $request->user());
        return ApiResponse::success(new RfqDetailResource($rfq), 'RFQ created.', 201);
    }

    /** PATCH /api/v1/rfqs/{rfq} — draft only. */
    public function update(UpdateRfqRequest $request, Rfq $rfq): JsonResponse
    {
        $rfq = $this->rfqs->update($rfq, $request->validated(), $request->user());
        return ApiResponse::success(new RfqDetailResource($rfq), 'RFQ updated.');
    }

    /** DELETE /api/v1/rfqs/{rfq} — draft only. */
    public function destroy(Rfq $rfq): JsonResponse
    {
        $this->rfqs->delete($rfq, request()->user());
        return ApiResponse::success(null, 'RFQ deleted.');
    }

    // ---- Line items ----

    public function storeItem(StoreRfqItemRequest $request, Rfq $rfq): JsonResponse
    {
        $item = $this->rfqs->addItem($rfq, $request->validated(), $request->user());
        return ApiResponse::success(new RfqItemResource($item), 'Line item added.', 201);
    }

    public function updateItem(UpdateRfqItemRequest $request, Rfq $rfq, RfqItem $item): JsonResponse
    {
        $this->assertItemInRfq($rfq, $item);
        $item = $this->rfqs->updateItem($rfq, $item, $request->validated(), $request->user());
        return ApiResponse::success(new RfqItemResource($item), 'Line item updated.');
    }

    public function destroyItem(Rfq $rfq, RfqItem $item): JsonResponse
    {
        $this->assertItemInRfq($rfq, $item);
        $this->rfqs->removeItem($rfq, $item, request()->user());
        return ApiResponse::success(null, 'Line item removed.');
    }

    // ---- Workflow ----

    /** POST /api/v1/rfqs/{rfq}/submit — files the PDF and emails the vendor. */
    public function submit(Rfq $rfq): JsonResponse
    {
        $rfq = $this->rfqs->submit($rfq, request()->user());
        return ApiResponse::success(new RfqDetailResource($rfq), 'RFQ submitted and emailed to the vendor.');
    }

    // ---- Guards ----

    private function assertItemInRfq(Rfq $rfq, RfqItem $item): void
    {
        abort_if($item->rfq_id !== $rfq->id, 404, 'Line item not found on this RFQ.');
    }
}
