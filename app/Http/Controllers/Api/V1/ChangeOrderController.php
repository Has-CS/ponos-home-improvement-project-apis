<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangeOrder\ChangeOrderCommentRequest;
use App\Http\Requests\Api\V1\ChangeOrder\ChangeOrderReasonRequest;
use App\Http\Requests\Api\V1\ChangeOrder\IndexChangeOrderRequest;
use App\Http\Requests\Api\V1\ChangeOrder\SetGcDecisionRequest;
use App\Http\Requests\Api\V1\ChangeOrder\StoreChangeOrderRequest;
use App\Http\Requests\Api\V1\ChangeOrder\StoreEmergencyChangeOrderRequest;
use App\Http\Requests\Api\V1\ChangeOrder\UpdateChangeOrderRequest;
use App\Http\Resources\Api\V1\ChangeOrderDetailResource;
use App\Http\Resources\Api\V1\ChangeOrderListResource;
use App\Models\ChangeOrder;
use App\Models\Project;
use App\Services\ChangeOrder\ChangeOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ChangeOrderController extends Controller
{
    public function __construct(private readonly ChangeOrderService $changeOrders) {}

    /** GET /api/v1/projects/{project}/change-orders/list — paginated CO list. */
    public function index(IndexChangeOrderRequest $request, Project $project): JsonResponse
    {
        $page = $this->changeOrders->paginate($project, $request->validated());

        return ApiResponse::success([
            'items' => ChangeOrderListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/projects/{project}/change-orders/{change_order} — full detail. */
    public function show(Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        return ApiResponse::success(new ChangeOrderDetailResource($this->changeOrders->findDetailed($change_order)), 'OK');
    }

    /** POST /api/v1/projects/{project}/change-orders — Normal CO (draft). */
    public function store(StoreChangeOrderRequest $request, Project $project): JsonResponse
    {
        $co = $this->changeOrders->createNormal($project, $request->validated(), $request->user()->id);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order created.', 201);
    }

    /** POST /api/v1/projects/{project}/change-orders/emergency — on-site emergency CO. */
    public function storeEmergency(StoreEmergencyChangeOrderRequest $request, Project $project): JsonResponse
    {
        $co = $this->changeOrders->createEmergency($project, $request->validated(), $request->user());
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Emergency change order recorded.', 201);
    }

    /** PATCH /api/v1/projects/{project}/change-orders/{change_order} — edit while editable. */
    public function update(UpdateChangeOrderRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->update($change_order, $request->validated());
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order updated.');
    }

    public function submit(Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->submit($change_order, request()->user());
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order submitted.');
    }

    public function validateCo(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->validateCo($change_order, $request->user(), $request->validated()['comments'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order validated.');
    }

    public function approve(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->approve($change_order, $request->user(), $request->validated()['comments'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order approved.');
    }

    public function counterSign(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->counterSign($change_order, $request->user(), $request->validated()['comments'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order counter-signed and sent to GC.');
    }

    public function setGcDecision(SetGcDecisionRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $data = $request->validated();
        $co = $this->changeOrders->setGcDecision($change_order, $request->user(), $data['decision'], $data['notes'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'GC decision recorded.');
    }

    public function sendBack(ChangeOrderReasonRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->sendBack($change_order, $request->user(), $request->validated()['comments']);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order sent back.');
    }

    public function reject(ChangeOrderReasonRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->reject($change_order, $request->user(), $request->validated()['comments']);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order rejected.');
    }

    public function cancel(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->cancel($change_order, $request->user(), $request->validated()['comments'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order cancelled.');
    }

    private function assertInProject(Project $project, ChangeOrder $co): void
    {
        abort_if($co->project_id !== $project->id, 404, 'Change order not found in this project.');
    }
}
