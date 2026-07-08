<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreMilestoneStatusLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateMilestoneStatusLookupRequest;
use App\Models\Milestone;
use App\Models\MilestoneStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class MilestoneStatusLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return MilestoneStatus::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return Milestone::where('status_id', $lookup->id)->exists();
    }

    public function show(MilestoneStatus $milestone_status): JsonResponse
    {
        return $this->handleShow($milestone_status);
    }

    public function store(StoreMilestoneStatusLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateMilestoneStatusLookupRequest $request, MilestoneStatus $milestone_status): JsonResponse
    {
        return $this->handleUpdate($milestone_status, $request->validated());
    }

    public function destroy(MilestoneStatus $milestone_status): JsonResponse
    {
        return $this->handleDestroy($milestone_status);
    }
}
