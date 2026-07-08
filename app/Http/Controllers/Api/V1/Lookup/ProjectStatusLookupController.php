<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreProjectStatusLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateProjectStatusLookupRequest;
use App\Models\Project;
use App\Models\ProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class ProjectStatusLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return ProjectStatus::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return Project::where('project_status_id', $lookup->id)->exists();
    }

    public function show(ProjectStatus $project_status): JsonResponse
    {
        return $this->handleShow($project_status);
    }

    public function store(StoreProjectStatusLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateProjectStatusLookupRequest $request, ProjectStatus $project_status): JsonResponse
    {
        return $this->handleUpdate($project_status, $request->validated());
    }

    public function destroy(ProjectStatus $project_status): JsonResponse
    {
        return $this->handleDestroy($project_status);
    }
}
