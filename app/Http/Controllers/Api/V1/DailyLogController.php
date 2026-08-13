<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DailyLog\IndexDailyLogRequest;
use App\Http\Requests\Api\V1\DailyLog\StoreDailyLogRequest;
use App\Http\Requests\Api\V1\DailyLog\UpdateDailyLogRequest;
use App\Http\Resources\Api\V1\DailyLogDetailResource;
use App\Http\Resources\Api\V1\DailyLogListResource;
use App\Models\DailyLog;
use App\Models\Project;
use App\Services\DailyLog\DailyLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DailyLogController extends Controller
{
    public function __construct(private readonly DailyLogService $dailyLogs) {}

    /** GET /api/v1/projects/{project}/daily-logs (project.access + view_daily_log) */
    public function index(IndexDailyLogRequest $request, Project $project): JsonResponse
    {
        $page = $this->dailyLogs->paginate($project, $request->validated());

        return ApiResponse::success([
            'items' => DailyLogListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/projects/{project}/daily-logs/{daily_log} */
    public function show(Project $project, DailyLog $daily_log): JsonResponse
    {
        $this->assertInProject($project, $daily_log);
        return ApiResponse::success(new DailyLogDetailResource($this->dailyLogs->findDetailed($daily_log)), 'OK');
    }

    /** POST /api/v1/projects/{project}/daily-logs (project.access + submit_daily_log) */
    public function store(StoreDailyLogRequest $request, Project $project): JsonResponse
    {
        $log = $this->dailyLogs->create($project, $request->validated(), $request->user());
        return ApiResponse::success(new DailyLogDetailResource($log), 'Daily log submitted.', 201);
    }

    /** PATCH /api/v1/projects/{project}/daily-logs/{daily_log} (author or admin) */
    public function update(UpdateDailyLogRequest $request, Project $project, DailyLog $daily_log): JsonResponse
    {
        $this->assertInProject($project, $daily_log);
        $log = $this->dailyLogs->update($daily_log, $request->user(), $request->validated());
        return ApiResponse::success(new DailyLogDetailResource($log), 'Daily log updated.');
    }

    /** DELETE /api/v1/projects/{project}/daily-logs/{daily_log} (author or admin) */
    public function destroy(Project $project, DailyLog $daily_log): JsonResponse
    {
        $this->assertInProject($project, $daily_log);
        $this->dailyLogs->delete($daily_log, request()->user());
        return ApiResponse::success(null, 'Daily log deleted.');
    }

    private function assertInProject(Project $project, DailyLog $log): void
    {
        abort_if($log->project_id !== $project->id, 404, 'Daily log not found in this project.');
    }
}
