<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProjectDeliveryAddress\StoreProjectDeliveryAddressRequest;
use App\Http\Requests\Api\V1\ProjectDeliveryAddress\UpdateProjectDeliveryAddressRequest;
use App\Http\Resources\Api\V1\ProjectDeliveryAddressResource;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Services\ProjectDeliveryAddress\ProjectDeliveryAddressService;
use App\Support\ApiResponse;
use App\Support\Concerns\ScopesProjectAccess;
use Illuminate\Http\JsonResponse;

class ProjectDeliveryAddressController extends Controller
{
    use ScopesProjectAccess;

    public function __construct(private readonly ProjectDeliveryAddressService $addresses) {}

    /**
     * GET /api/v1/projects/{project}/delivery-addresses — the ship-to dropdown.
     *
     * Access is checked HERE rather than with the `project.access` middleware,
     * because purchase-order work is deliberately not project-membership-scoped
     * and Procurement is frequently not staffed onto the project they are
     * buying for. Gating this route on membership alone would 403 the buyer on
     * the only endpoint that feeds the ship-to dropdown — the same trap
     * `purchase-orders/pending-requests` and the material-request-photo
     * widening in AttachmentController were both built to avoid.
     */
    public function index(Project $project): JsonResponse
    {
        $user = request()->user();

        abort_unless(
            $this->canAccessProject($user, $project->id) || $this->isProcurementDesk($user),
            403,
            'You do not have access to this project.'
        );

        return ApiResponse::success(
            ProjectDeliveryAddressResource::collection($this->addresses->list($project)),
            'OK'
        );
    }

    /** POST /api/v1/projects/{project}/delivery-addresses — add a ship-to site. */
    public function store(StoreProjectDeliveryAddressRequest $request, Project $project): JsonResponse
    {
        $address = $this->addresses->create($project, $request->validated(), $request->user()->id);

        return ApiResponse::success(new ProjectDeliveryAddressResource($address), 'Delivery address created.', 201);
    }

    /** PATCH /api/v1/projects/{project}/delivery-addresses/{delivery_address} */
    public function update(
        UpdateProjectDeliveryAddressRequest $request,
        Project $project,
        ProjectDeliveryAddress $delivery_address,
    ): JsonResponse {
        $this->assertInProject($project, $delivery_address);
        $address = $this->addresses->update($delivery_address, $request->validated());

        return ApiResponse::success(new ProjectDeliveryAddressResource($address), 'Delivery address updated.');
    }

    /**
     * DELETE /api/v1/projects/{project}/delivery-addresses/{delivery_address}
     *
     * Always permitted: purchase orders snapshot the address, so removing it
     * cannot alter an order that has already been issued.
     */
    public function destroy(Project $project, ProjectDeliveryAddress $delivery_address): JsonResponse
    {
        $this->assertInProject($project, $delivery_address);
        $this->addresses->delete($delivery_address);

        return ApiResponse::success(null, 'Delivery address deleted.');
    }

    private function assertInProject(Project $project, ProjectDeliveryAddress $address): void
    {
        abort_if($address->project_id !== $project->id, 404, 'Delivery address not found in this project.');
    }
}
