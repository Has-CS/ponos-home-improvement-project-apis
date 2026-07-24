<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreUnitLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateUnitLookupRequest;
use App\Models\CatalogItem;
use App\Models\Unit;
use App\Models\VendorRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UnitLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return Unit::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return CatalogItem::where('default_unit_id', $lookup->id)->exists()
            || VendorRate::where('unit_id', $lookup->id)->exists()
            || DB::table('material_request_items')->where('unit_id', $lookup->id)->whereNull('deleted_at')->exists()
            || DB::table('purchase_order_items')->where('unit_id', $lookup->id)->whereNull('deleted_at')->exists()
            || DB::table('estimate_line_items')->where('unit_id', $lookup->id)->whereNull('deleted_at')->exists();
    }

    public function show(Unit $unit): JsonResponse
    {
        return $this->handleShow($unit);
    }

    public function store(StoreUnitLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateUnitLookupRequest $request, Unit $unit): JsonResponse
    {
        return $this->handleUpdate($unit, $request->validated());
    }

    public function destroy(Unit $unit): JsonResponse
    {
        return $this->handleDestroy($unit);
    }
}
