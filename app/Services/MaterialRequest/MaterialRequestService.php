<?php

namespace App\Services\MaterialRequest;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Document\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MaterialRequestService
{
    // Status codes (material_request_statuses.code) the workflow transitions between.
    private const DRAFT = 'draft';
    private const PENDING_PM = 'pending_pm';
    private const SENT_BACK_TO_FOREMAN = 'sent_back_to_foreman';
    private const PENDING_ADMIN = 'pending_admin';
    private const SENT_BACK_TO_PM = 'sent_back_to_pm';
    private const REJECTED = 'rejected';
    private const APPROVED = 'approved';
    private const ORDERED = 'ordered';
    private const PARTIALLY_DELIVERED = 'partially_delivered';
    private const DELIVERED = 'delivered';

    /** Statuses in which the request's lines may still be edited. */
    private const EDITABLE_STATUSES = [self::DRAFT, self::SENT_BACK_TO_FOREMAN, self::SENT_BACK_TO_PM];

    private const LIST_WITH = ['status', 'urgency', 'requester'];
    private const DETAIL_WITH = [
        'status', 'urgency', 'requester',
        'items.costCode', 'items.catalogItem', 'items.tradeCategory', 'items.unit',
        'approvals.approver', 'approvals.fromStatus', 'approvals.toStatus',
        'purchaseOrders',
    ];

    /** @var array<string,int> */
    private array $statusIdCache = [];

    public function __construct(private readonly DocumentSequenceService $sequences) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(Project $project, array $filters): LengthAwarePaginator
    {
        $query = MaterialRequest::query()
            ->where('project_id', $project->id)
            ->with(self::LIST_WITH)
            ->withCount('items');

        if (! empty($filters['search'])) {
            $query->where('request_no', 'ilike', '%'.$filters['search'].'%');
        }
        if (! empty($filters['status_id'])) {
            $query->where('material_request_status_id', $filters['status_id']);
        }
        if (! empty($filters['urgency_id'])) {
            $query->where('urgency_id', $filters['urgency_id']);
        }

        return $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(MaterialRequest $mr): MaterialRequest
    {
        return $mr->load(self::DETAIL_WITH);
    }

    public function create(Project $project, array $data, int $userId): MaterialRequest
    {
        return DB::transaction(function () use ($project, $data, $userId) {
            $mr = MaterialRequest::create([
                'request_no' => $this->sequences->next('material_request', 'MR'),
                'project_id' => $project->id,
                'requested_by' => $userId,
                'material_request_status_id' => $this->statusId(self::DRAFT),
                'urgency_id' => $data['urgency_id'],
                'needed_by_date' => $data['needed_by_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['items'] ?? [] as $line) {
                $this->persistItem($mr, $line);
            }

            return $mr->fresh(self::DETAIL_WITH);
        });
    }

    public function update(MaterialRequest $mr, array $data): MaterialRequest
    {
        // Header edits allowed only while the request is still editable.
        $this->assertEditable($mr);
        $mr->fill($data)->save();

        return $mr->fresh(self::DETAIL_WITH);
    }

    public function delete(MaterialRequest $mr, User $user): void
    {
        if ($this->statusCode($mr) !== self::DRAFT) {
            abort(409, 'Only a draft material request can be deleted.');
        }
        if ($mr->requested_by !== $user->id && ! $this->isAdmin($user)) {
            abort(403, 'Only the requester can delete this draft.');
        }

        $mr->items()->delete();
        $mr->delete();
    }

    // ---- Line items ----

    public function addItem(MaterialRequest $mr, User $user, array $data): MaterialRequestItem
    {
        $this->assertItemsEditable($mr, $user);

        return $this->persistItem($mr, $data)->load(['costCode', 'catalogItem', 'tradeCategory', 'unit']);
    }

    public function updateItem(MaterialRequest $mr, MaterialRequestItem $item, User $user, array $data): MaterialRequestItem
    {
        $this->assertItemsEditable($mr, $user);
        $item->fill($data)->save();

        return $item->fresh(['costCode', 'catalogItem', 'tradeCategory', 'unit']);
    }

    public function removeItem(MaterialRequest $mr, MaterialRequestItem $item, User $user): void
    {
        $this->assertItemsEditable($mr, $user);
        $item->delete();
    }

    // ---- Workflow transitions ----

    /**
     * Advance a draft/returned request into the approval chain.
     * draft|sent_back_to_foreman → pending_pm (by the requester);
     * sent_back_to_pm → pending_admin (by a PM-level user).
     */
    public function submit(MaterialRequest $mr, User $user): MaterialRequest
    {
        $from = $this->statusCode($mr);

        if (in_array($from, [self::DRAFT, self::SENT_BACK_TO_FOREMAN], true)) {
            if ($mr->requested_by !== $user->id && ! $this->isAdmin($user)) {
                abort(403, 'Only the requester can submit this request.');
            }
            $to = self::PENDING_PM;
        } elseif ($from === self::SENT_BACK_TO_PM) {
            if (! $this->isPmLevel($user)) {
                abort(403, 'Only a project manager can resubmit this request to admin.');
            }
            $to = self::PENDING_ADMIN;
        } else {
            abort(409, "A request in '{$from}' status cannot be submitted.");
        }

        if ($mr->items()->count() === 0) {
            abort(422, 'Cannot submit a material request with no line items.');
        }

        return $this->transition($mr, $user, 'submit', $from, $to, null);
    }

    public function approve(MaterialRequest $mr, User $user, ?string $comments): MaterialRequest
    {
        $from = $this->statusCode($mr);
        $this->assertApproverForStep($mr, $user, $from);

        $to = match ($from) {
            self::PENDING_PM => self::PENDING_ADMIN,
            self::PENDING_ADMIN => self::APPROVED,
            default => abort(409, "A request in '{$from}' status cannot be approved."),
        };

        return $this->transition($mr, $user, 'approve', $from, $to, $comments);
    }

    public function sendBack(MaterialRequest $mr, User $user, string $comments): MaterialRequest
    {
        $from = $this->statusCode($mr);
        $this->assertApproverForStep($mr, $user, $from);

        $to = match ($from) {
            self::PENDING_PM => self::SENT_BACK_TO_FOREMAN,
            self::PENDING_ADMIN => self::SENT_BACK_TO_PM,
            default => abort(409, "A request in '{$from}' status cannot be sent back."),
        };

        return $this->transition($mr, $user, 'send_back', $from, $to, $comments);
    }

    public function reject(MaterialRequest $mr, User $user, string $comments): MaterialRequest
    {
        $from = $this->statusCode($mr);
        $this->assertApproverForStep($mr, $user, $from);

        if (! in_array($from, [self::PENDING_PM, self::PENDING_ADMIN], true)) {
            abort(409, "A request in '{$from}' status cannot be rejected.");
        }

        return $this->transition($mr, $user, 'reject', $from, self::REJECTED, $comments);
    }

    /**
     * Recompute fulfillment status from the request's POs and their deliveries.
     * Called by DeliveryService after a receipt is recorded. Only advances a
     * request that is already ordered/partially_delivered (never regresses an
     * un-ordered request).
     */
    public function recomputeFulfillment(MaterialRequest $mr): void
    {
        if (! in_array($this->statusCode($mr), [self::ORDERED, self::PARTIALLY_DELIVERED, self::DELIVERED], true)) {
            return;
        }

        $poItems = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.material_request_id', $mr->id)
            ->whereNull('po.deleted_at')
            ->whereNull('poi.deleted_at')
            ->select('poi.id', 'poi.quantity_ordered')
            ->get();

        if ($poItems->isEmpty()) {
            return;
        }

        $anyReceived = false;
        $allFullyReceived = true;

        foreach ($poItems as $poItem) {
            $received = (float) DB::table('delivery_items')
                ->where('purchase_order_item_id', $poItem->id)
                ->whereNull('deleted_at')
                ->sum('quantity_received');

            if ($received > 0) {
                $anyReceived = true;
            }
            if ($received + 1e-9 < (float) $poItem->quantity_ordered) {
                $allFullyReceived = false;
            }
        }

        $target = $allFullyReceived ? self::DELIVERED : ($anyReceived ? self::PARTIALLY_DELIVERED : self::ORDERED);

        if ($this->statusCode($mr) !== $target) {
            $mr->update(['material_request_status_id' => $this->statusId($target)]);
        }
    }

    /** Flip an approved request to ordered when its first PO is created. */
    public function markOrdered(MaterialRequest $mr): void
    {
        if ($this->statusCode($mr) === self::APPROVED) {
            $mr->update(['material_request_status_id' => $this->statusId(self::ORDERED)]);
        }
    }

    // ---- Internals ----

    private function persistItem(MaterialRequest $mr, array $data): MaterialRequestItem
    {
        return $mr->items()->create([
            'cost_code_id' => $data['cost_code_id'],
            'catalog_item_id' => $data['catalog_item_id'] ?? null,
            'trade_category_id' => $data['trade_category_id'] ?? null,
            'unit_id' => $data['unit_id'],
            'description' => $data['description'] ?? null,
            'quantity' => $data['quantity'],
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    private function transition(MaterialRequest $mr, User $user, string $action, string $from, string $to, ?string $comments): MaterialRequest
    {
        return DB::transaction(function () use ($mr, $user, $action, $from, $to, $comments) {
            $fromId = $this->statusId($from);
            $toId = $this->statusId($to);

            $mr->update(['material_request_status_id' => $toId]);

            $nextStep = (int) $mr->approvals()->max('step_no') + 1;
            $mr->approvals()->create([
                'step_no' => $nextStep,
                'approver_id' => $user->id,
                'approver_role' => $this->actorRole($user),
                'action' => $action,
                'comments' => $comments,
                'from_status_id' => $fromId,
                'to_status_id' => $toId,
                'acted_at' => now(),
            ]);

            return $mr->fresh(self::DETAIL_WITH);
        });
    }

    private function assertEditable(MaterialRequest $mr): void
    {
        if (! in_array($this->statusCode($mr), self::EDITABLE_STATUSES, true)) {
            abort(409, 'This material request can no longer be edited in its current status.');
        }
    }

    private function assertItemsEditable(MaterialRequest $mr, User $user): void
    {
        $this->assertEditable($mr);

        if ($mr->requested_by !== $user->id && ! $this->isPmLevel($user)) {
            abort(403, 'You are not allowed to edit the lines of this material request.');
        }
    }

    /** Enforce the two-level role chain for the step being actioned. */
    private function assertApproverForStep(MaterialRequest $mr, User $user, string $from): void
    {
        if ($from === self::PENDING_PM && ! $this->isPmLevel($user)) {
            abort(403, 'This step must be actioned by a project manager.');
        }
        if ($from === self::PENDING_ADMIN && ! $this->isAdmin($user)) {
            abort(403, 'This step must be actioned by an administrator.');
        }
    }

    private function isAdmin(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole('Admin');
    }

    private function isPmLevel(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole(['Admin', 'Project Manager', 'Assistant Project Manager']);
    }

    private function actorRole(User $user): string
    {
        $user->unsetRelation('roles');
        foreach (['Admin', 'Project Manager', 'Assistant Project Manager'] as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }
        return $user->getRoleNames()->first() ?? 'User';
    }

    private function statusCode(MaterialRequest $mr): string
    {
        return $mr->status?->code ?? MaterialRequestStatus::whereKey($mr->material_request_status_id)->value('code');
    }

    private function statusId(string $code): int
    {
        return $this->statusIdCache[$code] ??= MaterialRequestStatus::where('code', $code)->value('id')
            ?? abort(500, "Material request status '{$code}' is not seeded.");
    }
}
