<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreIssueStatusLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateIssueStatusLookupRequest;
use App\Models\IssueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class IssueStatusLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return IssueStatus::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return DB::table('issues')->where('issue_status_id', $lookup->id)->whereNull('deleted_at')->exists();
    }

    public function show(IssueStatus $issue_status): JsonResponse
    {
        return $this->handleShow($issue_status);
    }

    public function store(StoreIssueStatusLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateIssueStatusLookupRequest $request, IssueStatus $issue_status): JsonResponse
    {
        return $this->handleUpdate($issue_status, $request->validated());
    }

    public function destroy(IssueStatus $issue_status): JsonResponse
    {
        return $this->handleDestroy($issue_status);
    }
}
