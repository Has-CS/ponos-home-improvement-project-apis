<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderTerms\StorePurchaseOrderTermsRequest;
use App\Http\Requests\Api\V1\PurchaseOrderTerms\UpdatePurchaseOrderTermsRequest;
use App\Http\Resources\Api\V1\PurchaseOrderTermsResource;
use App\Models\Project;
use App\Models\PurchaseOrderTerm;
use App\Services\PurchaseOrderTerms\PurchaseOrderTermsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PurchaseOrderTermsController extends Controller
{
    public function __construct(private readonly PurchaseOrderTermsService $terms) {}

    /** GET /api/v1/purchase-order-terms — the default plus every override. */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            PurchaseOrderTermsResource::collection($this->terms->list()),
            'OK'
        );
    }

    /** GET /api/v1/purchase-order-terms/{purchase_order_term} */
    public function show(PurchaseOrderTerm $purchase_order_term): JsonResponse
    {
        return ApiResponse::success(
            new PurchaseOrderTermsResource($this->terms->findDetailed($purchase_order_term)),
            'OK'
        );
    }

    /** POST /api/v1/purchase-order-terms — create the default or an override. */
    public function store(StorePurchaseOrderTermsRequest $request): JsonResponse
    {
        $terms = $this->terms->create($request->validated(), $request->user()->id);

        return ApiResponse::success(new PurchaseOrderTermsResource($terms), 'Terms & conditions created.', 201);
    }

    /** PATCH /api/v1/purchase-order-terms/{purchase_order_term} */
    public function update(UpdatePurchaseOrderTermsRequest $request, PurchaseOrderTerm $purchase_order_term): JsonResponse
    {
        $terms = $this->terms->update($purchase_order_term, $request->validated());

        return ApiResponse::success(new PurchaseOrderTermsResource($terms), 'Terms & conditions updated.');
    }

    /**
     * DELETE /api/v1/purchase-order-terms/{purchase_order_term}
     *
     * Always permitted: purchase orders snapshot the text, so removing a set
     * cannot alter an order already issued. Removing a project's override just
     * means it falls back to the company default from here on.
     */
    public function destroy(PurchaseOrderTerm $purchase_order_term): JsonResponse
    {
        $this->terms->delete($purchase_order_term);

        return ApiResponse::success(null, 'Terms & conditions deleted.');
    }

    /**
     * GET /api/v1/projects/{project}/purchase-order-terms
     *
     * Which terms a PO cut against this project would carry — the override if
     * it has one, else the company default. A read-only window onto the same
     * resolveFor() the purchase-order service uses, so a buyer can check the
     * terms before cutting the order rather than discovering them on the
     * finished document. Null when nothing is configured.
     */
    public function effective(Project $project): JsonResponse
    {
        $terms = $this->terms->resolveFor($project->id);

        return ApiResponse::success(
            $terms ? new PurchaseOrderTermsResource($terms->load(['project', 'creator'])) : null,
            'OK'
        );
    }
}
