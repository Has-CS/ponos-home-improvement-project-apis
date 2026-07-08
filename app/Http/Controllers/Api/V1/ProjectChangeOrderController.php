<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChangeOrderLogResource;
use App\Models\ChangeOrder;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectChangeOrderController extends Controller
{
    /**
     * GET /api/v1/projects/{project}/change-orders
     * Read-only CO log: value, status, authorization chain. Reconciles with
     * the change-order module (reads change_orders + change_order_approvals);
     * does NOT create/modify COs (A5).
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        $log = ChangeOrder::query()
            ->where('project_id', $project->id)
            ->with([
                'type',
                'status',
                'gcDecision',
                'costCode',
                'originator',
                'approvals.actor',
                'approvals.fromStatus',
                'approvals.toStatus',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::success([
            'items' => ChangeOrderLogResource::collection($log),
            'pagination' => [
                'current_page' => $log->currentPage(),
                'per_page'     => $log->perPage(),
                'total'        => $log->total(),
                'last_page'    => $log->lastPage(),
            ],
        ], 'OK');
    }
}
