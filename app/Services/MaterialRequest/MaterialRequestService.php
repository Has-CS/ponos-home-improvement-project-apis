<?php

namespace App\Services\MaterialRequest;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Attachment\AttachmentService;
use App\Services\Document\DocumentSequenceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
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
        'status', 'urgency', 'requester', 'structuredBy', 'photos',
        'items.costCode', 'items.catalogItem', 'items.tradeCategory', 'items.unit',
        'approvals.approver', 'approvals.fromStatus', 'approvals.toStatus',
        'activityLogs.causer',
        'purchaseOrders',
    ];

    /** @var array<string,int> */
    private array $statusIdCache = [];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AttachmentService $attachments,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(Project $project, array $filters): LengthAwarePaginator
    {
        $query = MaterialRequest::query()
            ->where('project_id', $project->id)
            ->with(self::LIST_WITH)
            ->withCount(['items', 'photos']);

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
                'request_text' => $data['request_text'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['items'] ?? [] as $line) {
                $this->persistItem($mr, $line);
            }

            $this->storePhotos($mr, $project, $data['photos'] ?? [], $userId);

            return $mr->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * Attach requester photos ("a picture of the steel nut I need") to the
     * request.
     *
     * Each photo may arrive as an uploaded file (multipart — a browser file
     * picker, Postman) or as a base64 / data-URI string (a JSON body from an
     * on-device camera capture). Both land in the same private disk and the same
     * attachments row; only the ingest differs.
     *
     * @param  array<int,string|UploadedFile>  $photos
     */
    private function storePhotos(MaterialRequest $mr, Project $project, array $photos, int $userId): void
    {
        $meta = [
            'attachable_type' => MaterialRequest::class,
            'attachable_id' => $mr->id,
            'project_id' => $project->id,
            'attachment_type' => 'photo',
            'directory' => 'material-request-photos',
            'uploaded_by' => $userId,
            'captured_at' => now(),
        ];

        foreach ($photos as $photo) {
            $photo instanceof UploadedFile
                ? $this->attachments->storeUploadedImage($photo, $meta)
                : $this->attachments->storeBase64Image($photo, $meta);
        }
    }

    public function update(MaterialRequest $mr, array $data): MaterialRequest
    {
        // Header edits allowed only while the request is still editable.
        $this->assertEditable($mr);

        // The raw message freezes once the request is submitted, so it stays a
        // faithful record of what the foreman actually asked for — it is the
        // reference Procurement builds the PO from. Note assertEditable() alone
        // is not enough: it still permits `sent_back_to_pm`, where the PM holds
        // the request and could otherwise rewrite the foreman's words.
        if (array_key_exists('request_text', $data)
            && ! in_array($this->statusCode($mr), [self::DRAFT, self::SENT_BACK_TO_FOREMAN], true)) {
            abort(422, 'The original request text can no longer be changed once the request has been submitted.');
        }

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

        return DB::transaction(function () use ($mr, $user, $data) {
            $item = $this->persistItem($mr, $data);
            $this->markStructured($mr, $user);

            $this->logLineChange($mr, $user, 'created', $item, [
                'new' => $this->auditable($this->activity->snapshot($item, $item->getAttributes())),
            ]);

            return $item->load(['costCode', 'catalogItem', 'tradeCategory', 'unit']);
        });
    }

    public function updateItem(MaterialRequest $mr, MaterialRequestItem $item, User $user, array $data): MaterialRequestItem
    {
        $this->assertItemsEditable($mr, $user);

        // PATCH: catalog_item_id may be absent (unchanged), swapped for another
        // item, or explicitly nulled to turn the line into free text. Derive
        // against the MERGED post-update state, the same way
        // UpdateMaterialRequestItemRequest validates the item-or-description CHECK.
        if (array_key_exists('catalog_item_id', $data)) {
            $catalogItemId = $data['catalog_item_id'] !== null ? (int) $data['catalog_item_id'] : null;
        } else {
            $catalogItemId = $item->catalog_item_id !== null ? (int) $item->catalog_item_id : null;
        }

        $resolved = $this->resolveLineAttributes($catalogItemId, $data, $item);

        // The unit is the requester's own input — only ever DEFAULTED at create
        // time, never re-derived under them when the catalog item changes.
        // (trade_category_id is server-owned metadata, so it does re-derive.)
        unset($resolved['unit_id']);

        return DB::transaction(function () use ($mr, $item, $user, $data, $resolved) {
            // Capture BEFORE fill(): save() calls syncOriginal(), so getOriginal()
            // afterwards would already hold the new values.
            $before = $item->getAttributes();

            $item->fill([...$data, ...$resolved])->save();

            $diff = $this->activity->diff($item, $before);

            // A no-op PATCH is not worth an audit row.
            if ($diff['new'] !== []) {
                $this->logLineChange($mr, $user, 'updated', $item, [
                    'old' => $this->auditable($diff['old']),
                    'new' => $this->auditable($diff['new']),
                ]);
            }

            return $item->fresh(['costCode', 'catalogItem', 'tradeCategory', 'unit']);
        });
    }

    public function removeItem(MaterialRequest $mr, MaterialRequestItem $item, User $user): void
    {
        $this->assertItemsEditable($mr, $user);

        DB::transaction(function () use ($mr, $item, $user) {
            $before = $item->getAttributes();
            $item->delete();

            $this->logLineChange($mr, $user, 'deleted', $item, [
                'old' => $this->auditable($this->activity->snapshot($item, $before)),
            ]);
        });
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

            // A PM-level requester has no PM step left to perform on their own
            // request — routing it to pending_pm would only invite them to
            // approve themselves — so it goes straight to Admin.
            //
            // Keyed off the REQUESTER, never the actor: an Admin is allowed to
            // submit someone else's draft on their behalf (see the guard above),
            // and a foreman's request must still route to pending_pm when they do.
            $to = $mr->requester && $this->isPmLevel($mr->requester)
                ? self::PENDING_ADMIN
                : self::PENDING_PM;
        } elseif ($from === self::SENT_BACK_TO_PM) {
            if (! $this->isPmLevel($user)) {
                abort(403, 'Only a project manager can resubmit this request to admin.');
            }
            $to = self::PENDING_ADMIN;
        } else {
            abort(409, "A request in '{$from}' status cannot be submitted.");
        }

        // A request must say SOMETHING — but prose is enough. A foreman who
        // can't navigate the catalog pickers submits a free-text description
        // instead, and the mapping to catalog items happens later in the office
        // (optionally by the PM here, otherwise by Procurement at the PO).
        if ($mr->items()->count() === 0 && blank($mr->request_text)) {
            abort(422, 'Add at least one line item, or describe what you need, before submitting.');
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

    /**
     * End a request at the PM step, bypassing Admin approval.
     *
     * Deliberately a separate method and a separate endpoint rather than a
     * branch inside approve(): a PM who HOLDS the finalise right must still be
     * able to send a request up to Admin when they judge it warrants it. Folding
     * this into approve() would make the bypass automatic and take that choice
     * away. It also means the existing approval path is not edited at all, so it
     * cannot regress.
     *
     * The coarse gate (`finalize_material_request`) is enforced by route
     * middleware; what remains here is the step rule — this shortcut exists only
     * at `pending_pm`, and only for someone who could have approved there anyway.
     * At `pending_admin` an Admin's ordinary approve() already finalises.
     */
    public function finalize(MaterialRequest $mr, User $user, ?string $comments): MaterialRequest
    {
        $from = $this->statusCode($mr);

        if ($from !== self::PENDING_PM) {
            abort(409, "A request in '{$from}' status cannot be finalised.");
        }

        if (! $this->isPmLevel($user)) {
            abort(403, 'This step must be actioned by a project manager.');
        }

        return $this->transition($mr, $user, 'finalize', $from, self::APPROVED, $comments);
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
        $catalogItemId = isset($data['catalog_item_id']) ? (int) $data['catalog_item_id'] : null;

        return $mr->items()->create([
            'cost_code_id' => $data['cost_code_id'] ?? null,
            'catalog_item_id' => $catalogItemId,
            'description' => $data['description'] ?? null,
            'quantity' => $data['quantity'],
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            ...$this->resolveLineAttributes($catalogItemId, $data),
        ]);
    }

    /**
     * Resolve the two line attributes the server owns rather than the caller.
     *
     * A catalog item already carries its trade category (catalog_items
     * .trade_category_id is NOT NULL), so on a catalog line the category is
     * DERIVED and any client-sent value is ignored. That is what stops a line
     * ever storing a Doors item against a Plumbing category, and what lets the
     * field UI skip asking the question at all. On a free-text line there is
     * nothing to derive from, so the caller's value stands — the FormRequest
     * makes it mandatory there, since it is the only trade categorization such
     * a line will ever have.
     *
     * unit_id is defaulted from the item the same way
     * PurchaseOrderService::persistLine() does it, but only as a FALLBACK: an
     * explicit unit is the requester's stated intent and always wins.
     *
     * $existing is passed on the update path so an unrelated PATCH (e.g. just
     * `quantity`) can never blank a free-text line's stored category.
     *
     * @param  array<string,mixed>  $data
     * @return array{trade_category_id: ?int, unit_id: ?int}
     */
    private function resolveLineAttributes(?int $catalogItemId, array $data, ?MaterialRequestItem $existing = null): array
    {
        $catalogItem = $catalogItemId !== null ? CatalogItem::find($catalogItemId) : null;

        return [
            'trade_category_id' => $catalogItem?->trade_category_id
                ?? $data['trade_category_id']
                ?? $existing?->trade_category_id,
            'unit_id' => $data['unit_id']
                ?? $catalogItem?->default_unit_id
                ?? $existing?->unit_id,
        ];
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

    /**
     * Lines are editable by whoever the request currently sits with.
     *
     *   draft | sent_back_to_foreman | sent_back_to_pm — the requester (or a
     *       PM-level user) is holding it, and may edit freely.
     *   pending_pm    — the PM is reviewing it, and MAY optionally turn a
     *       free-text request into catalog lines before approving.
     *   pending_admin — likewise for an administrator.
     *
     * The two review windows exist because a foreman who couldn't structure the
     * request in the first place is not helped by send_back — that was the only
     * route available before, and it hands the job straight back to the one
     * person who can't do it. Structuring here is entirely optional: a request
     * may be approved as prose and mapped by Procurement at the PO instead.
     */
    private function assertItemsEditable(MaterialRequest $mr, User $user): void
    {
        $status = $this->statusCode($mr);

        if ($status === self::PENDING_PM) {
            // Narrower than isPmLevel() on purpose: an Assistant PM may approve,
            // send back or reject at this step, but may NOT rewrite the lines of
            // someone else's request. Their route to a correction is send_back.
            if (! $this->isPmOrAdmin($user)) {
                abort(403, 'Only a project manager or administrator can edit the lines of a request awaiting review.');
            }

            return;
        }

        if ($status === self::PENDING_ADMIN) {
            if (! $this->isAdmin($user)) {
                abort(403, 'Only an administrator can edit the lines of a request awaiting their review.');
            }

            return;
        }

        $this->assertEditable($mr);

        if ($mr->requested_by !== $user->id && ! $this->isPmLevel($user)) {
            abort(403, 'You are not allowed to edit the lines of this material request.');
        }
    }

    /**
     * Columns worth keeping in an audit diff — the ones a person actually
     * changed. Primary keys, the parent FK and Eloquent's bookkeeping columns
     * are noise.
     *
     * Values are stored raw (FK ids as written) rather than resolved to labels:
     * honest, queryable, and no extra lookups on the write path. A UI can
     * resolve names when it renders the log.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function auditable(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'id', 'material_request_id', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    /**
     * Write one line-item change to the audit spine.
     *
     * Skipped entirely while the request is a draft: that is the author's own
     * scratchpad, and logging every correction to an unsent request is noise.
     * From submit onwards — including any send-back window — every change is
     * recorded, which is what makes a reviewer altering someone else's request
     * accountable.
     *
     * The SUBJECT is the request, not the line. Carrying the line's id in the
     * properties instead keeps "the whole history of this request" a single
     * indexed lookup on (subject_type, subject_id).
     *
     * @param  array<string,mixed>  $properties
     */
    private function logLineChange(
        MaterialRequest $mr,
        User $user,
        string $verb,
        MaterialRequestItem $item,
        array $properties,
    ): void {
        if ($this->statusCode($mr) === self::DRAFT) {
            return;
        }

        $this->activity->log(
            event: "material_request_item.{$verb}",
            subject: $mr,
            causer: $user,
            properties: ['item_id' => $item->id, 'request_no' => $mr->request_no, ...$properties],
            projectId: $mr->project_id,
            description: "Line item {$verb} on {$mr->request_no}",
        );
    }

    /**
     * Record who first turned this request's prose into line items. Only
     * meaningful on a request that carried free text; stays null forever on one
     * that was structured from the start, or that goes to the PO as prose.
     */
    private function markStructured(MaterialRequest $mr, User $user): void
    {
        if (blank($mr->request_text) || $mr->structured_at !== null) {
            return;
        }

        $mr->forceFill(['structured_by' => $user->id, 'structured_at' => now()])->save();

        $nextStep = (int) $mr->approvals()->max('step_no') + 1;
        $mr->approvals()->create([
            'step_no' => $nextStep,
            'approver_id' => $user->id,
            'approver_role' => $this->actorRole($user),
            'action' => 'edit', // already permitted by the approvals CHECK
            'comments' => 'Structured the free-text request into line items.',
            'from_status_id' => $mr->material_request_status_id,
            'to_status_id' => $mr->material_request_status_id,
            'acted_at' => now(),
        ]);
    }

    /** Enforce the two-level role chain for the step being actioned. */
    private function assertApproverForStep(MaterialRequest $mr, User $user, string $from): void
    {
        // Nobody clears their own request through the PM step. submit() already
        // routes a PM-level requester past it, so this is defence in depth — and
        // it covers requests raised before that routing existed, which are still
        // sitting at pending_pm. Not a deadlock: any other PM-level user, or an
        // Admin, can still action them.
        //
        // Deliberately not applied at pending_admin: the Admin is the terminal
        // authority, and blocking there would strand requests wherever there is
        // only one Admin.
        if ($from === self::PENDING_PM && $mr->requested_by === $user->id) {
            abort(403, 'You cannot approve your own material request.');
        }

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

    /**
     * Narrower than isPmLevel(): excludes Assistant Project Manager.
     *
     * Used only for EDITING a request that is under review. Assistant PMs keep
     * their approval rights at that step — this deliberately splits "may approve"
     * from "may rewrite someone else's lines".
     */
    private function isPmOrAdmin(User $user): bool
    {
        $user->unsetRelation('roles');
        return $user->hasRole(['Admin', 'Project Manager']);
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
