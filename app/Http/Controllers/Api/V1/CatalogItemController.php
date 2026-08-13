<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogItem\IndexCatalogItemRequest;
use App\Http\Requests\Api\V1\CatalogItem\SearchCatalogItemRequest;
use App\Http\Requests\Api\V1\CatalogItem\StoreCatalogItemRequest;
use App\Http\Requests\Api\V1\CatalogItem\UpdateCatalogItemRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\SearchPurchaseOrderCatalogItemRequest;
use App\Http\Resources\Api\V1\CatalogItemDetailResource;
use App\Http\Resources\Api\V1\CatalogItemListResource;
use App\Http\Resources\Api\V1\CatalogItemSearchResource;
use App\Http\Resources\Api\V1\PurchaseOrderCatalogItemSearchResource;
use App\Models\CatalogItem;
use App\Models\Project;
use App\Services\CatalogItem\CatalogItemService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CatalogItemController extends Controller
{
    public function __construct(private readonly CatalogItemService $catalogItems) {}

    /** GET /api/v1/catalog-items — paginated list (search, filters, sort). */
    public function index(IndexCatalogItemRequest $request): JsonResponse
    {
        $page = $this->catalogItems->paginate($request->validated());

        return ApiResponse::success([
            'items' => CatalogItemListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /**
     * GET /api/v1/projects/{project}/catalog-items/search — type-ahead picker
     * for material-request lines.
     *
     * Unlike index() above, this is reachable WITHOUT view_pricing, so it returns
     * the price-free CatalogItemSearchResource. Capped, no total (see the service).
     */
    public function search(SearchCatalogItemRequest $request, Project $project): JsonResponse
    {
        $result = $this->catalogItems->search($project, $request->validated());

        return ApiResponse::success([
            'items' => CatalogItemSearchResource::collection($result['items']),
            'limit' => $result['limit'],
            'has_more' => $result['has_more'],
        ], 'OK');
    }

    /**
     * GET /api/v1/purchase-orders/catalog-items/search — the buyer's type-ahead.
     *
     * Same catalog, same query, same project scoping as search() above — only
     * the audience differs, so the service is reused and only the response shape
     * changes: rows carry the named vendor's current rate, which the
     * material-request picker must never expose.
     *
     * Project comes in as a query parameter rather than a path segment because
     * purchase orders are top-level, not project-nested; the buyer has the id
     * from the material request or from /purchase-orders/pending-requests.
     */
    public function searchForPurchaseOrder(SearchPurchaseOrderCatalogItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $project = Project::findOrFail($data['project_id']);

        $result = $this->catalogItems->search(
            $project,
            $data,
            isset($data['vendor_id']) ? (int) $data['vendor_id'] : null,
        );

        return ApiResponse::success([
            'items' => PurchaseOrderCatalogItemSearchResource::collection($result['items']),
            'limit' => $result['limit'],
            'has_more' => $result['has_more'],
        ], 'OK');
    }

    /** POST /api/v1/catalog-items — create (edit_pricing). */
    public function store(StoreCatalogItemRequest $request): JsonResponse
    {
        $item = $this->catalogItems->create($request->validated(), $request->user()->id);
        return ApiResponse::success(new CatalogItemDetailResource($item), 'Catalog item created successfully.', 201);
    }

    /** GET /api/v1/catalog-items/{catalog_item} — full detail incl. current vendor rates. */
    public function show(CatalogItem $catalog_item): JsonResponse
    {
        return ApiResponse::success(new CatalogItemDetailResource($this->catalogItems->findDetailed($catalog_item)), 'OK');
    }

    /** PATCH /api/v1/catalog-items/{catalog_item} — partial update (edit_pricing). */
    public function update(UpdateCatalogItemRequest $request, CatalogItem $catalog_item): JsonResponse
    {
        $item = $this->catalogItems->update($catalog_item, $request->validated());
        return ApiResponse::success(new CatalogItemDetailResource($item), 'Catalog item updated successfully.');
    }

    /** DELETE /api/v1/catalog-items/{catalog_item} — soft delete (edit_pricing). */
    public function destroy(CatalogItem $catalog_item): JsonResponse
    {
        try {
            $this->catalogItems->delete($catalog_item);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(null, 'Catalog item deleted successfully.');
    }
}
