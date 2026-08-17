<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProjectGeneralContractor\StoreProjectGeneralContractorRequest;
use App\Http\Requests\Api\V1\ProjectGeneralContractor\UpdateProjectGeneralContractorRequest;
use App\Http\Resources\Api\V1\ProjectGeneralContractorResource;
use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use App\Services\ProjectGeneralContractor\ProjectGeneralContractorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProjectGeneralContractorController extends Controller
{
    public function __construct(private readonly ProjectGeneralContractorService $contractors) {}

    /**
     * GET /api/v1/projects/{project}/general-contractors — the GC dropdown for
     * raising a change order.
     *
     * Membership is enforced by the `project.access` middleware rather than
     * checked here, unlike ProjectDeliveryAddressController::index(). That
     * controller does its own widened check because purchase-order work is
     * deliberately not membership-scoped and Procurement is often not staffed
     * onto the project. Change orders ARE membership-scoped, so this endpoint —
     * which exists to feed the change-order GC dropdown — follows that module.
     */
    public function index(Project $project): JsonResponse
    {
        return ApiResponse::success(
            ProjectGeneralContractorResource::collection($this->contractors->list($project)),
            'OK'
        );
    }

    /** POST /api/v1/projects/{project}/general-contractors */
    public function store(StoreProjectGeneralContractorRequest $request, Project $project): JsonResponse
    {
        $gc = $this->contractors->create($project, $request->validated(), $request->user()->id);

        return ApiResponse::success(new ProjectGeneralContractorResource($gc), 'General contractor created.', 201);
    }

    /** PATCH /api/v1/projects/{project}/general-contractors/{general_contractor} */
    public function update(
        UpdateProjectGeneralContractorRequest $request,
        Project $project,
        ProjectGeneralContractor $general_contractor,
    ): JsonResponse {
        $this->assertInProject($project, $general_contractor);
        $gc = $this->contractors->update($general_contractor, $request->validated());

        return ApiResponse::success(new ProjectGeneralContractorResource($gc), 'General contractor updated.');
    }

    /**
     * DELETE /api/v1/projects/{project}/general-contractors/{general_contractor}
     *
     * Always permitted: change orders snapshot the GC when their document is
     * generated, so retiring it cannot alter a document already issued.
     */
    public function destroy(Project $project, ProjectGeneralContractor $general_contractor): JsonResponse
    {
        $this->assertInProject($project, $general_contractor);
        $this->contractors->delete($general_contractor);

        return ApiResponse::success(null, 'General contractor deleted.');
    }

    private function assertInProject(Project $project, ProjectGeneralContractor $gc): void
    {
        abort_if($gc->project_id !== $project->id, 404, 'General contractor not found in this project.');
    }
}
