<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Issue\IndexIssueRequest;
use App\Http\Requests\Api\V1\Issue\StoreIssueRequest;
use App\Http\Requests\Api\V1\Issue\UpdateIssueRequest;
use App\Http\Resources\Api\V1\IssueDetailResource;
use App\Http\Resources\Api\V1\IssueListResource;
use App\Models\Issue;
use App\Models\Project;
use App\Services\Issue\IssueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class IssueController extends Controller
{
    public function __construct(private readonly IssueService $issues) {}

    /** GET /api/v1/projects/{project}/issues */
    public function index(IndexIssueRequest $request, Project $project): JsonResponse
    {
        $page = $this->issues->paginate($project, $request->validated());

        return ApiResponse::success([
            'items' => IssueListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/projects/{project}/issues/{issue} */
    public function show(Project $project, Issue $issue): JsonResponse
    {
        $this->assertInProject($project, $issue);
        return ApiResponse::success(new IssueDetailResource($this->issues->findDetailed($issue)), 'OK');
    }

    /** POST /api/v1/projects/{project}/issues (manage_issues) */
    public function store(StoreIssueRequest $request, Project $project): JsonResponse
    {
        $issue = $this->issues->create($project, $request->validated(), $request->user()->id);
        return ApiResponse::success(new IssueDetailResource($issue), 'Issue raised.', 201);
    }

    /** PATCH /api/v1/projects/{project}/issues/{issue} (manage_issues) */
    public function update(UpdateIssueRequest $request, Project $project, Issue $issue): JsonResponse
    {
        $this->assertInProject($project, $issue);
        $issue = $this->issues->update($issue, $request->validated());
        return ApiResponse::success(new IssueDetailResource($issue), 'Issue updated.');
    }

    /** POST /api/v1/projects/{project}/issues/{issue}/resolve (manage_issues; PM/Admin in service) */
    public function resolve(Project $project, Issue $issue): JsonResponse
    {
        $this->assertInProject($project, $issue);
        $issue = $this->issues->resolve($issue, request()->user());
        return ApiResponse::success(new IssueDetailResource($issue), 'Issue resolved.');
    }

    private function assertInProject(Project $project, Issue $issue): void
    {
        abort_if($issue->project_id !== $project->id, 404, 'Issue not found in this project.');
    }
}
