<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreProjectTypeLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateProjectTypeLookupRequest;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class ProjectTypeLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return ProjectType::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return Project::where('project_type_id', $lookup->id)->exists();
    }

    public function show(ProjectType $project_type): JsonResponse
    {
        return $this->handleShow($project_type);
    }

    public function store(StoreProjectTypeLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateProjectTypeLookupRequest $request, ProjectType $project_type): JsonResponse
    {
        return $this->handleUpdate($project_type, $request->validated());
    }

    public function destroy(ProjectType $project_type): JsonResponse
    {
        return $this->handleDestroy($project_type);
    }
}
