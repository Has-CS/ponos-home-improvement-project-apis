<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VendorRate\IndexVendorRateRequest;
use App\Http\Requests\Api\V1\VendorRate\StoreVendorRateRequest;
use App\Http\Resources\Api\V1\VendorRateResource;
use App\Models\VendorRate;
use App\Services\VendorRate\VendorRateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class VendorRateController extends Controller
{
    public function __construct(private readonly VendorRateService $vendorRates) {}

    /** GET /api/v1/vendor-rates — current rates by default, or full history via current_only=false. */
    public function index(IndexVendorRateRequest $request): JsonResponse
    {
        $page = $this->vendorRates->paginate($request->validated());

        return ApiResponse::success([
            'items' => VendorRateResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/vendor-rates/{vendor_rate} — a single rate entry (any point in history). */
    public function show(VendorRate $vendor_rate): JsonResponse
    {
        return ApiResponse::success(new VendorRateResource($this->vendorRates->findDetailed($vendor_rate)), 'OK');
    }

    /** POST /api/v1/vendor-rates — add a new rate, auto-closing the prior open one (edit_pricing). */
    public function store(StoreVendorRateRequest $request): JsonResponse
    {
        $vendorRate = $this->vendorRates->addRate($request->validated(), $request->user()->id);
        return ApiResponse::success(new VendorRateResource($vendorRate), 'Vendor rate added successfully.', 201);
    }
}
