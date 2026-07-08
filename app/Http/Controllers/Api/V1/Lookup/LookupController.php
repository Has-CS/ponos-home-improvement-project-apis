<?php

namespace App\Http\Controllers\Api\V1\Lookup;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LookupResource;
use App\Services\Lookup\LookupService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

abstract class LookupController extends Controller
{
    public function __construct(protected readonly LookupService $lookups) {}

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** True if $lookup is currently referenced by a live row elsewhere. */
    abstract protected function isInUse(Model $lookup): bool;

    /** GET — open to any authenticated user. */
    public function index(): JsonResponse
    {
        $items = $this->lookups->list($this->modelClass());
        return ApiResponse::success(LookupResource::collection($items), 'OK');
    }

    protected function handleShow(Model $lookup): JsonResponse
    {
        return ApiResponse::success(new LookupResource($lookup), 'OK');
    }

    protected function handleStore(array $data): JsonResponse
    {
        $lookup = $this->lookups->create($this->modelClass(), $data);
        return ApiResponse::success(new LookupResource($lookup), 'Created.', 201);
    }

    protected function handleUpdate(Model $lookup, array $data): JsonResponse
    {
        $lookup = $this->lookups->update($lookup, $data);
        return ApiResponse::success(new LookupResource($lookup), 'Updated.');
    }

    protected function handleDestroy(Model $lookup): JsonResponse
    {
        try {
            $this->lookups->delete($lookup, fn (Model $l) => $this->isInUse($l));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(null, 'Deleted.');
    }
}
