<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreMilestonePhaseLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateMilestonePhaseLookupRequest;
use App\Models\Milestone;
use App\Models\MilestonePhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class MilestonePhaseLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return MilestonePhase::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return Milestone::where('phase_id', $lookup->id)->exists();
    }

    public function show(MilestonePhase $milestone_phase): JsonResponse
    {
        return $this->handleShow($milestone_phase);
    }

    public function store(StoreMilestonePhaseLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateMilestonePhaseLookupRequest $request, MilestonePhase $milestone_phase): JsonResponse
    {
        return $this->handleUpdate($milestone_phase, $request->validated());
    }

    public function destroy(MilestonePhase $milestone_phase): JsonResponse
    {
        return $this->handleDestroy($milestone_phase);
    }
}
