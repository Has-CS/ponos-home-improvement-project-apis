<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GcRepresentative\StoreGcRepresentativeRequest;
use App\Http\Requests\Api\V1\GcRepresentative\UpdateGcRepresentativeRequest;
use App\Http\Resources\Api\V1\GcRepresentativeResource;
use App\Models\GcRepresentative;
use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use App\Services\GcRepresentative\GcRepresentativeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A General Contractor's on-site representatives, nested under the GC:
 * projects/{project}/general-contractors/{general_contractor}/representatives.
 *
 * Gated like the GC routes: reads by any project member (the signature dropdown),
 * writes by `edit_project`. Both parents are checked here, so a representative
 * can only be reached through its own GC and that GC's own project.
 */
class GcRepresentativeController extends Controller
{
    public function __construct(private readonly GcRepresentativeService $representatives) {}

    /** GET …/representatives — the dropdown on the work authorization step. */
    public function index(Project $project, ProjectGeneralContractor $general_contractor): JsonResponse
    {
        $this->assertGcInProject($project, $general_contractor);

        return ApiResponse::success(
            GcRepresentativeResource::collection($this->representatives->list($general_contractor)),
            'OK'
        );
    }

    public function show(Project $project, ProjectGeneralContractor $general_contractor, GcRepresentative $representative): JsonResponse
    {
        $this->assertRepInGc($project, $general_contractor, $representative);

        return ApiResponse::success(new GcRepresentativeResource($this->representatives->find($representative)), 'OK');
    }

    public function store(StoreGcRepresentativeRequest $request, Project $project, ProjectGeneralContractor $general_contractor): JsonResponse
    {
        $this->assertGcInProject($project, $general_contractor);

        $rep = $this->representatives->create($general_contractor, $request->validated(), $request->user()->id);

        return ApiResponse::success(new GcRepresentativeResource($rep), 'Representative created.', 201);
    }

    public function update(
        UpdateGcRepresentativeRequest $request,
        Project $project,
        ProjectGeneralContractor $general_contractor,
        GcRepresentative $representative,
    ): JsonResponse {
        $this->assertRepInGc($project, $general_contractor, $representative);

        $rep = $this->representatives->update($representative, $request->validated());

        return ApiResponse::success(new GcRepresentativeResource($rep), 'Representative updated.');
    }

    /** Soft delete. Signatures already captured keep their own copy of the details. */
    public function destroy(Project $project, ProjectGeneralContractor $general_contractor, GcRepresentative $representative): JsonResponse
    {
        $this->assertRepInGc($project, $general_contractor, $representative);

        $this->representatives->delete($representative);

        return ApiResponse::success(null, 'Representative deleted.');
    }

    private function assertGcInProject(Project $project, ProjectGeneralContractor $gc): void
    {
        abort_if($gc->project_id !== $project->id, 404, 'General contractor not found in this project.');
    }

    private function assertRepInGc(Project $project, ProjectGeneralContractor $gc, GcRepresentative $rep): void
    {
        $this->assertGcInProject($project, $gc);

        abort_if($rep->general_contractor_id !== $gc->id, 404, 'Representative not found for this general contractor.');
    }
}
