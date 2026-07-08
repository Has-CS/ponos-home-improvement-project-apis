<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Milestone\StoreMilestoneRequest;
use App\Http\Requests\Api\V1\Milestone\UpdateMilestoneRequest;
use App\Http\Resources\Api\V1\MilestoneResource;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\Project\ProjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MilestoneController extends Controller
{
    private const WITH = ['phase', 'status', 'predecessor', 'responsibleUser', 'creator'];

    public function __construct(private readonly ProjectService $projects) {}

    /** GET /api/v1/projects/{project}/milestones — timeline (ordered). */
    public function index(Project $project): JsonResponse
    {
        $milestones = $project->milestones()->with(self::WITH)->get(); // already ordered by sequence
        return ApiResponse::success(MilestoneResource::collection($milestones), 'OK');
    }

    /** POST /api/v1/projects/{project}/milestones — add a timeline entry. */
    public function store(StoreMilestoneRequest $request, Project $project): JsonResponse
    {
        $milestone = $this->projects->createMilestone($project, $request->validated(), $request->user()->id);
        return ApiResponse::success(new MilestoneResource($milestone), 'Milestone created.', 201);
    }

    /** PATCH /api/v1/projects/{project}/milestones/{milestone} — update. */
    public function update(UpdateMilestoneRequest $request, Project $project, Milestone $milestone): JsonResponse
    {
        abort_if($milestone->project_id !== $project->id, 404, 'Milestone not found in this project.');
        $milestone = $this->projects->updateMilestone($milestone, $request->validated());
        return ApiResponse::success(new MilestoneResource($milestone), 'Milestone updated.');
    }

    /** DELETE /api/v1/projects/{project}/milestones/{milestone} — soft delete. */
    public function destroy(Project $project, Milestone $milestone): JsonResponse
    {
        abort_if($milestone->project_id !== $project->id, 404, 'Milestone not found in this project.');

        try {
            $this->projects->deleteMilestone($milestone);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(null, 'Milestone deleted.');
    }
}
