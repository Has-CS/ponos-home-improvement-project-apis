<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\IndexProjectRequest;
use App\Http\Requests\Api\V1\Project\StoreProjectRequest;
use App\Http\Requests\Api\V1\Project\UpdateProjectRequest;
use App\Http\Requests\Api\V1\Project\UpdateProjectStatusRequest;
use App\Http\Resources\Api\V1\ProjectDetailResource;
use App\Http\Resources\Api\V1\ProjectListResource;
use App\Models\Project;
use App\Services\Project\ProjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    /** GET /api/v1/projects — list (scoped, filtered, paginated). */
    public function index(IndexProjectRequest $request): JsonResponse
    {
        $page = $this->projects->paginate($request->user(), $request->validated());

        return ApiResponse::success([
            'items' => ProjectListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/projects/mine — projects the current user can switch between. */
    public function mine(IndexProjectRequest $request): JsonResponse
    {
        // Same as index but never all-access: even admins get only their assignments here.
        $filters = $request->validated();
        $user = $request->user();
        $ids = $this->projects->accessibleProjectIds($user) ?: [0];

        $page = Project::with(['client', 'type', 'status'])
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success([
            'items' => ProjectListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** POST /api/v1/projects — create (Admin). */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->validated(), $request->user()->id);
        return ApiResponse::success(new ProjectDetailResource($project), 'Project created successfully.', 201);
    }

    /** GET /api/v1/projects/{project} — full details. */
    public function show(Project $project): JsonResponse
    {
        return ApiResponse::success(
            new ProjectDetailResource($this->projects->findDetailed($project)),
            'OK'
        );
    }

    /** PATCH /api/v1/projects/{project} — partial update (Admin). */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project = $this->projects->update($project, $request->validated());
        return ApiResponse::success(new ProjectDetailResource($project), 'Project updated successfully.');
    }

    /** PATCH /api/v1/projects/{project}/status — change status (Admin). */
    public function updateStatus(UpdateProjectStatusRequest $request, Project $project): JsonResponse
    {
        $project = $this->projects->updateStatus($project, (int) $request->validated('project_status_id'));
        return ApiResponse::success(new ProjectDetailResource($project), 'Project status updated.');
    }

    /** DELETE /api/v1/projects/{project} — soft delete (Admin). */
    public function destroy(Project $project): JsonResponse
    {
        $this->projects->delete($project);
        return ApiResponse::success(null, 'Project deleted successfully.');
    }
}
