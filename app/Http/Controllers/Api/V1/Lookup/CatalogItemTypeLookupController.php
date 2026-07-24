<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreCatalogItemTypeLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateCatalogItemTypeLookupRequest;
use App\Models\CatalogItem;
use App\Models\CatalogItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class CatalogItemTypeLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return CatalogItemType::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return CatalogItem::where('catalog_item_type_id', $lookup->id)->exists();
    }

    public function show(CatalogItemType $catalog_item_type): JsonResponse
    {
        return $this->handleShow($catalog_item_type);
    }

    public function store(StoreCatalogItemTypeLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateCatalogItemTypeLookupRequest $request, CatalogItemType $catalog_item_type): JsonResponse
    {
        return $this->handleUpdate($catalog_item_type, $request->validated());
    }

    public function destroy(CatalogItemType $catalog_item_type): JsonResponse
    {
        return $this->handleDestroy($catalog_item_type);
    }
}
