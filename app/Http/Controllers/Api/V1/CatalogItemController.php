<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogItem\IndexCatalogItemRequest;
use App\Http\Requests\Api\V1\CatalogItem\StoreCatalogItemRequest;
use App\Http\Requests\Api\V1\CatalogItem\UpdateCatalogItemRequest;
use App\Http\Resources\Api\V1\CatalogItemDetailResource;
use App\Http\Resources\Api\V1\CatalogItemListResource;
use App\Models\CatalogItem;
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
