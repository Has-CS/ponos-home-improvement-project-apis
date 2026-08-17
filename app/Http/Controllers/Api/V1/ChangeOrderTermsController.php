<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangeOrderTerms\StoreChangeOrderTermsRequest;
use App\Http\Requests\Api\V1\ChangeOrderTerms\UpdateChangeOrderTermsRequest;
use App\Http\Resources\Api\V1\ChangeOrderTermsResource;
use App\Models\ChangeOrderTerm;
use App\Models\Project;
use App\Services\ChangeOrderTerms\ChangeOrderTermsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ChangeOrderTermsController extends Controller
{
    public function __construct(private readonly ChangeOrderTermsService $terms) {}

    /** GET /api/v1/change-order-terms — the default plus every override. */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ChangeOrderTermsResource::collection($this->terms->list()),
            'OK'
        );
    }

    /** GET /api/v1/change-order-terms/{change_order_term} */
    public function show(ChangeOrderTerm $change_order_term): JsonResponse
    {
        return ApiResponse::success(
            new ChangeOrderTermsResource($this->terms->findDetailed($change_order_term)),
            'OK'
        );
    }

    /** POST /api/v1/change-order-terms — create the default or an override. */
    public function store(StoreChangeOrderTermsRequest $request): JsonResponse
    {
        $terms = $this->terms->create($request->validated(), $request->user()->id);

        return ApiResponse::success(new ChangeOrderTermsResource($terms), 'Change-order terms created.', 201);
    }

    /** PATCH /api/v1/change-order-terms/{change_order_term} */
    public function update(UpdateChangeOrderTermsRequest $request, ChangeOrderTerm $change_order_term): JsonResponse
    {
        $terms = $this->terms->update($change_order_term, $request->validated());

        return ApiResponse::success(new ChangeOrderTermsResource($terms), 'Change-order terms updated.');
    }

    /**
     * DELETE /api/v1/change-order-terms/{change_order_term}
     *
     * Always permitted: prepared change orders snapshot the text, so removing a
     * set cannot alter a document already issued. Removing a project's override
     * just means it falls back to the company default from here on.
     */
    public function destroy(ChangeOrderTerm $change_order_term): JsonResponse
    {
        $this->terms->delete($change_order_term);

        return ApiResponse::success(null, 'Change-order terms deleted.');
    }

    /**
     * GET /api/v1/projects/{project}/change-order-terms
     *
     * Which terms a change order raised against this project would carry — the
     * override if it has one, else the company default. A read-only window onto
     * the same resolveFor() the change-order service uses, so the PM preparing
     * the document can check the wording before generating it rather than
     * discovering it on the finished sheet. Null when nothing is configured.
     */
    public function effective(Project $project): JsonResponse
    {
        $terms = $this->terms->resolveFor($project->id);

        return ApiResponse::success(
            $terms ? new ChangeOrderTermsResource($terms->load(['project', 'creator'])) : null,
            'OK'
        );
    }
}
