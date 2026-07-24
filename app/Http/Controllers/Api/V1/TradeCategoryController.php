<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TradeCategory\StoreTradeCategoryRequest;
use App\Http\Requests\Api\V1\TradeCategory\UpdateTradeCategoryRequest;
use App\Http\Resources\Api\V1\TradeCategoryResource;
use App\Models\TradeCategory;
use App\Services\TradeCategory\TradeCategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TradeCategoryController extends Controller
{
    public function __construct(private readonly TradeCategoryService $tradeCategories) {}

    /** GET /api/v1/trade-categories — flat list (dropdown source). */
    public function index(): JsonResponse
    {
        return ApiResponse::success(TradeCategoryResource::collection($this->tradeCategories->list()), 'OK');
    }

    /** GET /api/v1/trade-categories/{trade_category} */
    public function show(TradeCategory $trade_category): JsonResponse
    {
        return ApiResponse::success(new TradeCategoryResource($trade_category), 'OK');
    }

    /** POST /api/v1/trade-categories — create (manage_lookups). */
    public function store(StoreTradeCategoryRequest $request): JsonResponse
    {
        $tradeCategory = $this->tradeCategories->create($request->validated());
        return ApiResponse::success(new TradeCategoryResource($tradeCategory), 'Trade category created successfully.', 201);
    }

    /** PATCH /api/v1/trade-categories/{trade_category} — partial update (manage_lookups). */
    public function update(UpdateTradeCategoryRequest $request, TradeCategory $trade_category): JsonResponse
    {
        $tradeCategory = $this->tradeCategories->update($trade_category, $request->validated());
        return ApiResponse::success(new TradeCategoryResource($tradeCategory), 'Trade category updated successfully.');
    }

    /** DELETE /api/v1/trade-categories/{trade_category} — soft delete (manage_lookups). */
    public function destroy(TradeCategory $trade_category): JsonResponse
    {
        try {
            $this->tradeCategories->delete($trade_category);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(null, 'Trade category deleted successfully.');
    }
}
