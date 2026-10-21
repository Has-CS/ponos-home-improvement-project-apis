<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangeOrder\ChangeOrderCommentRequest;
use App\Http\Requests\Api\V1\ChangeOrder\ChangeOrderReasonRequest;
use App\Http\Requests\Api\V1\ChangeOrder\GcQueueRequest;
use App\Http\Requests\Api\V1\ChangeOrder\IndexChangeOrderRequest;
use App\Http\Requests\Api\V1\ChangeOrder\SetGcDecisionRequest;
use App\Http\Requests\Api\V1\ChangeOrder\StoreChangeOrderRequest;
use App\Http\Requests\Api\V1\ChangeOrder\StoreEmergencyChangeOrderRequest;
use App\Http\Requests\Api\V1\ChangeOrder\UpdateChangeOrderRequest;
use App\Http\Resources\Api\V1\ChangeOrderDetailResource;
use App\Http\Resources\Api\V1\ChangeOrderListResource;
use App\Models\ChangeOrder;
use App\Models\Project;
use App\Services\ChangeOrder\ChangeOrderAuthorizationPdfService;
use App\Services\ChangeOrder\ChangeOrderPdfService;
use App\Services\ChangeOrder\ChangeOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ChangeOrderController extends Controller
{
    public function __construct(
        private readonly ChangeOrderService $changeOrders,
        private readonly ChangeOrderPdfService $pdf,
        private readonly ChangeOrderAuthorizationPdfService $authorizations,
    ) {}

    /** GET /api/v1/projects/{project}/change-orders/list — paginated CO list. */
    public function index(IndexChangeOrderRequest $request, Project $project): JsonResponse
    {
        $page = $this->changeOrders->paginate($project, $request->validated());

        return ApiResponse::success([
            'items' => ChangeOrderListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    /** GET /api/v1/projects/{project}/change-orders/{change_order} — full detail. */
    public function show(Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        return ApiResponse::success(new ChangeOrderDetailResource($this->changeOrders->findDetailed($change_order)), 'OK');
    }

    /** POST /api/v1/projects/{project}/change-orders — Normal CO (draft). */
    public function store(StoreChangeOrderRequest $request, Project $project): JsonResponse
    {
        $co = $this->changeOrders->createNormal($project, $request->validated(), $request->user()->id);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order created.', 201);
    }

    /** POST /api/v1/projects/{project}/change-orders/emergency — on-site emergency CO. */
    public function storeEmergency(StoreEmergencyChangeOrderRequest $request, Project $project): JsonResponse
    {
        $co = $this->changeOrders->createEmergency($project, $request->validated(), $request->user());
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Emergency change order recorded.', 201);
    }

    /** PATCH /api/v1/projects/{project}/change-orders/{change_order} — edit while editable. */
    public function update(UpdateChangeOrderRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->update($change_order, $request->user(), $request->validated());
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order updated.');
    }

    public function submit(Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->submit($change_order, request()->user());
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order submitted.');
    }

    public function validateCo(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->validateCo($change_order, $request->user(), $request->validated()['comments'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order validated.');
    }

    public function approve(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->approve($change_order, $request->user(), $request->validated()['comments'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order approved.');
    }

    /**
     * GET .../change-orders/{change_order}/pdf
     *
     * Serves the STORED document once it has been sent to the GC, so what a GC was
     * sent can always be retrieved byte-for-byte. Before that there is nothing
     * stored and the change order is still moving through review, so it renders
     * live and prints with a DRAFT watermark.
     *
     * ?preview=1 forces a live render even when a filed copy exists. The filed
     * bytes are never touched or replaced — this only changes what THIS response
     * returns.
     *
     * It exists because the two things the stored copy freezes are not the same
     * thing. The change order's DATA is frozen by design and must never be
     * re-rendered. The TEMPLATE is not: change the Blade file and every
     * already-filed document keeps printing the old layout forever, with no way
     * to see the new one against real data short of raising a fresh change order
     * and walking it through the whole chain. That is a genuine gap for anyone
     * reviewing a layout change, and it is what this flag answers.
     *
     * Deliberately NOT a way to refresh the filed copy: an issued document must
     * stay exactly as issued, so nothing here writes.
     *
     * ?download=1 forces a save-as instead of inline display.
     */
    public function pdf(Project $project, ChangeOrder $change_order): Response
    {
        $this->assertInProject($project, $change_order);

        // currentDocument() re-files first when the stored copy cannot be showing
        // the current signature, so a change order filed during a storage fault
        // heals on its next download instead of serving the same wrong bytes for
        // the rest of its life. ?preview=1 still bypasses the filed copy entirely.
        $stored = request()->boolean('preview')
            ? null
            : $this->pdf->currentDocument($change_order, request()->user()?->id);

        $bytes = $stored && Storage::disk($stored->disk)->exists($stored->file_path)
            ? Storage::disk($stored->disk)->get($stored->file_path)
            : $this->pdf->render($change_order);

        $disposition = request()->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->pdf->fileName($change_order).'"',
        ]);
    }

    /**
     * GET .../change-orders/{change_order}/authorization
     *
     * The Work Authorization sheet — the on-site record of what the General
     * Contractor's representative signed for, with the signature embedded and no
     * price anywhere on it.
     *
     * Serves the FILED copy, which is what makes it evidence: it was written in the
     * same transaction that recorded the signature and is never regenerated from
     * current data. currentAuthorization() re-files first when the stored copy
     * cannot be showing the signature, so a sheet filed during a storage fault
     * heals on its next download instead of serving the same wrong bytes for the
     * rest of its life.
     *
     * 404 rather than an empty PDF when there is none: a Normal change order has no
     * on-site authorization, and neither does an emergency one raised before this
     * sheet existed.
     *
     * ?download=1 forces a save-as instead of inline display.
     */
    public function authorization(Project $project, ChangeOrder $change_order): Response
    {
        $this->assertInProject($project, $change_order);

        $stored = $this->authorizations->currentAuthorization($change_order, request()->user()?->id);

        abort_if($stored === null, 404, 'This change order has no on-site work authorization.');
        abort_unless(
            Storage::disk($stored->disk)->exists($stored->file_path),
            404,
            'The work authorization document is missing.',
        );

        $disposition = request()->boolean('download') ? 'attachment' : 'inline';

        return response(Storage::disk($stored->disk)->get($stored->file_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$this->authorizations->fileName($change_order).'"',
        ]);
    }
    public function setGcDecision(SetGcDecisionRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $data = $request->validated();
        $co = $this->changeOrders->setGcDecision($change_order, $request->user(), $data['decision'], $data['notes'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'GC decision recorded.');
    }

    /* ---------------- the emergency flow ---------------- */

    /**
     * POST .../change-orders/{change_order}/forward
     *
     * The conservative half of the PM's fork: priced, and routed to an Admin for
     * review rather than straight to the General Contractor.
     */
    public function forwardToAdmin(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->forwardToAdmin($change_order, $request->user(), $request->validated()['comments'] ?? null);

        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order forwarded for review.');
    }

    /**
     * POST .../change-orders/{change_order}/send-to-gc
     *
     * Serves BOTH flows.
     *
     * Normal: from `approved`, PM or Admin. Freezes the GC + terms, files the
     * change-order document and moves to pending_gc (no email yet).
     *
     * Emergency: the gate depends on which status it is called from: at
     * work_authorized the caller is skipping Admin review and must hold
     * send_change_request_to_gc; at pending_admin an Admin is sending what they
     * have just reviewed. The service records which route it took in gc_sent_via.
     * Files the change-order document — the first one an emergency change order ever
     * has — and queues it to the GC with the signed authorization merged in behind it.
     */
    public function sendToGc(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->sendToGc($change_order, $request->user(), $request->validated()['comments'] ?? null);

        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order sent to the general contractor.');
    }

    /**
     * POST .../change-orders/{change_order}/send-back-to-pricing
     *
     * An Admin disagrees with the price. Returns it to the PM's pricing desk, not to
     * the foreman — the figure is the PM's work.
     */
    public function sendBackToPricing(ChangeOrderReasonRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->sendBackToPricing($change_order, $request->user(), $request->validated()['comments']);

        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order sent back for re-pricing.');
    }

    /**
     * POST .../change-orders/{change_order}/void-authorization
     *
     * The signed authorization is defective. Nothing can edit what was signed, so the
     * change order is abandoned and the work re-captured on site under a fresh
     * signature. A reason is mandatory, and the signature row is marked voided rather
     * than deleted so it stays readable as evidence.
     */
    public function voidAuthorization(ChangeOrderReasonRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->voidAuthorization($change_order, $request->user(), $request->validated()['comments']);

        return ApiResponse::success(new ChangeOrderDetailResource($co), 'On-site authorization voided.');
    }

    /**
     * GET /api/v1/change-orders/gc-queue
     *
     * Change orders that have gone to a General Contractor, across every project the
     * caller can see. Chiefly the Admin's view of which ones a PM sent WITHOUT their
     * review (?gc_sent_via=pm_direct), and of work that was built and then refused
     * (?disputed=1).
     *
     * A pull-based queue because this application has no notification system — the
     * table exists and nothing has ever written to it — so the honest answer is a
     * list an Admin can open, not a push that does not exist.
     */
    public function gcQueue(GcQueueRequest $request): JsonResponse
    {
        $page = $this->changeOrders->gcQueue($request->user(), $request->validated());

        return ApiResponse::success([
            'items' => ChangeOrderListResource::collection($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'OK');
    }

    public function sendBack(ChangeOrderReasonRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->sendBack($change_order, $request->user(), $request->validated()['comments']);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order sent back.');
    }

    public function reject(ChangeOrderReasonRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->reject($change_order, $request->user(), $request->validated()['comments']);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order rejected.');
    }

    public function cancel(ChangeOrderCommentRequest $request, Project $project, ChangeOrder $change_order): JsonResponse
    {
        $this->assertInProject($project, $change_order);
        $co = $this->changeOrders->cancel($change_order, $request->user(), $request->validated()['comments'] ?? null);
        return ApiResponse::success(new ChangeOrderDetailResource($co), 'Change order cancelled.');
    }

    private function assertInProject(Project $project, ChangeOrder $co): void
    {
        abort_if($co->project_id !== $project->id, 404, 'Change order not found in this project.');
    }
}
