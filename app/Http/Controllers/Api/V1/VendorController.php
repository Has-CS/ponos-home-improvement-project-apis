<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Vendor\IndexVendorRequest;
use App\Http\Requests\Api\V1\Vendor\StoreVendorRequest;
use App\Http\Requests\Api\V1\Vendor\UpdateVendorRequest;
use App\Http\Requests\Api\V1\Vendor\UpdateVendorStatusRequest;
use App\Http\Resources\Api\V1\VendorDetailResource;
use App\Http\Resources\Api\V1\VendorListResource;
use App\Models\Vendor;
use App\Services\Vendor\VendorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class VendorController extends Controller
{
    public function __construct(private readonly VendorService $vendors) {}

    /** GET /api/v1/vendors — paginated list (search, is_active filter, sort). */
    public function index(IndexVendorRequest $request): JsonResponse
    {
        $page = $this->vendors->paginate($request->validated());

        return ApiResponse::success([
            'items' => VendorListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** POST /api/v1/vendors — create (edit_pricing). */
    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = $this->vendors->create($request->validated(), $request->user()->id);
        return ApiResponse::success(new VendorDetailResource($vendor), 'Vendor created successfully.', 201);
    }

    /** GET /api/v1/vendors/{vendor} — full detail. */
    public function show(Vendor $vendor): JsonResponse
    {
        return ApiResponse::success(new VendorDetailResource($this->vendors->findDetailed($vendor)), 'OK');
    }

    /** PATCH /api/v1/vendors/{vendor} — partial update (edit_pricing). */
    public function update(UpdateVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor = $this->vendors->update($vendor, $request->validated());
        return ApiResponse::success(new VendorDetailResource($vendor), 'Vendor updated successfully.');
    }

    /** PATCH /api/v1/vendors/{vendor}/status — activate/deactivate (edit_pricing). */
    public function updateStatus(UpdateVendorStatusRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor = $this->vendors->updateStatus($vendor, (bool) $request->validated('is_active'));
        return ApiResponse::success(new VendorDetailResource($vendor), 'Vendor status updated.');
    }

    /** DELETE /api/v1/vendors/{vendor} — soft delete (edit_pricing). */
    public function destroy(Vendor $vendor): JsonResponse
    {
        try {
            $this->vendors->delete($vendor);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(null, 'Vendor deleted successfully.');
    }
}
