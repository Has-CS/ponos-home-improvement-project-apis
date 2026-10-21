<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\DashboardRequest;
use App\Http\Resources\Api\V1\DashboardResource;
use App\Services\Dashboard\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * GET /api/v1/dashboard — any authenticated user. Which sections come back
     * is decided by the caller's permissions, not by route middleware.
     */
    public function index(DashboardRequest $request): JsonResponse
    {
        $data = $this->dashboard->build($request->user(), $request->validated());

        return ApiResponse::success(new DashboardResource($data), 'OK');
    }
}
