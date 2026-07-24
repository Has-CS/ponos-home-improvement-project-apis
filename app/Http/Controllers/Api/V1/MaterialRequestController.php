<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MaterialRequest\ApproveMaterialRequestRequest;
use App\Http\Requests\Api\V1\MaterialRequest\IndexMaterialRequestRequest;
use App\Http\Requests\Api\V1\MaterialRequest\MaterialRequestReasonRequest;
use App\Http\Requests\Api\V1\MaterialRequest\StoreMaterialRequestItemRequest;
use App\Http\Requests\Api\V1\MaterialRequest\StoreMaterialRequestRequest;
use App\Http\Requests\Api\V1\MaterialRequest\UpdateMaterialRequestItemRequest;
use App\Http\Requests\Api\V1\MaterialRequest\UpdateMaterialRequestRequest;
use App\Http\Resources\Api\V1\MaterialRequestDetailResource;
use App\Http\Resources\Api\V1\MaterialRequestItemResource;
use App\Http\Resources\Api\V1\MaterialRequestListResource;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\Project;
use App\Services\MaterialRequest\MaterialRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MaterialRequestController extends Controller
{
    public function __construct(private readonly MaterialRequestService $materialRequests) {}

    /** GET /api/v1/projects/{project}/material-requests */
    public function index(IndexMaterialRequestRequest $request, Project $project): JsonResponse
    {
        $page = $this->materialRequests->paginate($project, $request->validated());

        return ApiResponse::success([
            'items' => MaterialRequestListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/projects/{project}/material-requests/{material_request} */
    public function show(Project $project, MaterialRequest $material_request): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        return ApiResponse::success(new MaterialRequestDetailResource($this->materialRequests->findDetailed($material_request)), 'OK');
    }

    /** POST /api/v1/projects/{project}/material-requests (create_material_request) */
    public function store(StoreMaterialRequestRequest $request, Project $project): JsonResponse
    {
        $mr = $this->materialRequests->create($project, $request->validated(), $request->user()->id);
        return ApiResponse::success(new MaterialRequestDetailResource($mr), 'Material request created.', 201);
    }

    /** PATCH /api/v1/projects/{project}/material-requests/{material_request} (create_material_request) */
    public function update(UpdateMaterialRequestRequest $request, Project $project, MaterialRequest $material_request): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $mr = $this->materialRequests->update($material_request, $request->validated());
        return ApiResponse::success(new MaterialRequestDetailResource($mr), 'Material request updated.');
    }

    /** DELETE /api/v1/projects/{project}/material-requests/{material_request} (create_material_request) */
    public function destroy(Project $project, MaterialRequest $material_request): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $this->materialRequests->delete($material_request, request()->user());
        return ApiResponse::success(null, 'Material request deleted.');
    }

    // ---- Line items ----

    public function storeItem(StoreMaterialRequestItemRequest $request, Project $project, MaterialRequest $material_request): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $item = $this->materialRequests->addItem($material_request, $request->user(), $request->validated());
        return ApiResponse::success(new MaterialRequestItemResource($item), 'Line item added.', 201);
    }

    public function updateItem(UpdateMaterialRequestItemRequest $request, Project $project, MaterialRequest $material_request, MaterialRequestItem $item): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $this->assertItemInRequest($material_request, $item);
        $item = $this->materialRequests->updateItem($material_request, $item, $request->user(), $request->validated());
        return ApiResponse::success(new MaterialRequestItemResource($item), 'Line item updated.');
    }

    public function destroyItem(Project $project, MaterialRequest $material_request, MaterialRequestItem $item): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $this->assertItemInRequest($material_request, $item);
        $this->materialRequests->removeItem($material_request, $item, request()->user());
        return ApiResponse::success(null, 'Line item removed.');
    }

    // ---- Workflow actions ----

    public function submit(Project $project, MaterialRequest $material_request): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $mr = $this->materialRequests->submit($material_request, request()->user());
        return ApiResponse::success(new MaterialRequestDetailResource($mr), 'Material request submitted.');
    }

    public function approve(ApproveMaterialRequestRequest $request, Project $project, MaterialRequest $material_request): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $mr = $this->materialRequests->approve($material_request, $request->user(), $request->validated()['comments'] ?? null);
        return ApiResponse::success(new MaterialRequestDetailResource($mr), 'Material request approved.');
    }

    public function sendBack(MaterialRequestReasonRequest $request, Project $project, MaterialRequest $material_request): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $mr = $this->materialRequests->sendBack($material_request, $request->user(), $request->validated()['comments']);
        return ApiResponse::success(new MaterialRequestDetailResource($mr), 'Material request sent back.');
    }

    public function reject(MaterialRequestReasonRequest $request, Project $project, MaterialRequest $material_request): JsonResponse
    {
        $this->assertInProject($project, $material_request);
        $mr = $this->materialRequests->reject($material_request, $request->user(), $request->validated()['comments']);
        return ApiResponse::success(new MaterialRequestDetailResource($mr), 'Material request rejected.');
    }

    // ---- Guards ----

    private function assertInProject(Project $project, MaterialRequest $mr): void
    {
        abort_if($mr->project_id !== $project->id, 404, 'Material request not found in this project.');
    }

    private function assertItemInRequest(MaterialRequest $mr, MaterialRequestItem $item): void
    {
        abort_if($item->material_request_id !== $mr->id, 404, 'Line item not found in this material request.');
    }
}
