<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CostCode\StoreCostCodeRequest;
use App\Http\Requests\Api\V1\CostCode\UpdateCostCodeRequest;
use App\Http\Resources\Api\V1\CostCodeResource;
use App\Models\CostCode;
use App\Services\CostCode\CostCodeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CostCodeController extends Controller
{
    public function __construct(private readonly CostCodeService $costCodes) {}

    /** GET /api/v1/cost-codes — flat list (dropdown source). */
    public function index(): JsonResponse
    {
        return ApiResponse::success(CostCodeResource::collection($this->costCodes->list()), 'OK');
    }

    /** GET /api/v1/cost-codes/{cost_code} */
    public function show(CostCode $cost_code): JsonResponse
    {
        return ApiResponse::success(new CostCodeResource($cost_code), 'OK');
    }

    /** POST /api/v1/cost-codes — create (manage_lookups). */
    public function store(StoreCostCodeRequest $request): JsonResponse
    {
        $costCode = $this->costCodes->create($request->validated());
        return ApiResponse::success(new CostCodeResource($costCode), 'Cost code created successfully.', 201);
    }

    /** PATCH /api/v1/cost-codes/{cost_code} — partial update (manage_lookups). */
    public function update(UpdateCostCodeRequest $request, CostCode $cost_code): JsonResponse
    {
        $costCode = $this->costCodes->update($cost_code, $request->validated());
        return ApiResponse::success(new CostCodeResource($costCode), 'Cost code updated successfully.');
    }

    /** DELETE /api/v1/cost-codes/{cost_code} — soft delete (manage_lookups). */
    public function destroy(CostCode $cost_code): JsonResponse
    {
        try {
            $this->costCodes->delete($cost_code);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(null, 'Cost code deleted successfully.');
    }
}
