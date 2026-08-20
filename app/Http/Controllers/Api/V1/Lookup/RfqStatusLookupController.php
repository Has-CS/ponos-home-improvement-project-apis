<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Requests\Api\V1\Lookup\StoreRfqStatusLookupRequest;
use App\Http\Requests\Api\V1\Lookup\UpdateRfqStatusLookupRequest;
use App\Models\RfqStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RfqStatusLookupController extends LookupController
{
    protected function modelClass(): string
    {
        return RfqStatus::class;
    }

    protected function isInUse(Model $lookup): bool
    {
        return DB::table('rfqs')->where('rfq_status_id', $lookup->id)->whereNull('deleted_at')->exists();
    }

    public function show(RfqStatus $rfq_status): JsonResponse
    {
        return $this->handleShow($rfq_status);
    }

    public function store(StoreRfqStatusLookupRequest $request): JsonResponse
    {
        return $this->handleStore($request->validated());
    }

    public function update(UpdateRfqStatusLookupRequest $request, RfqStatus $rfq_status): JsonResponse
    {
        return $this->handleUpdate($rfq_status, $request->validated());
    }

    public function destroy(RfqStatus $rfq_status): JsonResponse
    {
        return $this->handleDestroy($rfq_status);
    }
}
