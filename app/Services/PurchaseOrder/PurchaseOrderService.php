<?php

namespace App\Services\PurchaseOrder;

use App\Jobs\SendPurchaseOrderEmailJob;
use App\Models\Attachment;
use App\Models\CatalogItem;
use App\Models\EmailLog;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderStatus;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRate;
use App\Services\Attachment\AttachmentService;
use App\Services\Document\DocumentSequenceService;
use App\Services\MaterialRequest\MaterialRequestService;
use App\Services\PurchaseOrderTerms\PurchaseOrderTermsService;
use App\Support\Concerns\ScopesProjectAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    use ScopesProjectAccess;

    private const DRAFT = 'draft';
    private const ISSUED = 'issued';
    private const SENT = 'sent';
    private const CANCELLED = 'cancelled';

    private const LIST_WITH = ['vendor', 'status'];
    private const DETAIL_WITH = [
        'vendor', 'status', 'issuedBy', 'shipToAddress', 'terms',
        'items.catalogItem', 'items.unit', 'items.costCode',
        'deliveries',
        // The originating request travels with the PO so the buyer can see the
        // foreman's own words and photos next to the lines they derived from
        // them — and so that reference survives for audit afterwards.
        'materialRequest.photos', 'materialRequest.items.catalogItem', 'materialRequest.items.unit',
    ];

    /** @var array<string,int> */
    private array $statusIdCache = [];

    /** Supporting files per order — a backstop against an unbounded merge. */
    private const MAX_ATTACHMENTS = 10;

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly MaterialRequestService $materialRequests,
        private readonly PurchaseOrderTermsService $terms,
        private readonly PurchaseOrderPdfService $pdf,
        private readonly AttachmentService $attachments,
        private readonly PurchaseOrderPdfMergeService $merge,
    ) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrder::query()->with(self::LIST_WITH);

        if (! $this->isProcurementDesk($user)) {
            $ids = $this->accessibleProjectIds($user);
            $query->whereIn('project_id', $ids ?: [0]);
        }

        if (! empty($filters['search'])) {
            $query->where('po_number', 'ilike', '%'.$filters['search'].'%');
        }
        foreach (['project_id', 'vendor_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['status_id'])) {
            $query->where('purchase_order_status_id', $filters['status_id']);
        }
        if (! empty($filters['source'])) {
            // The accessor's inverse: standalone orders are the ones with no
            // request behind them. Uses the existing material_request_id index.
            $filters['source'] === 'standalone'
                ? $query->whereNull('material_request_id')
                : $query->whereNotNull('material_request_id');
        }

        return $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(PurchaseOrder $po, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);

        $po->load(self::DETAIL_WITH);

        // Same figures on the embedded request, so an order can be reviewed
        // against what is still outstanding on the lines it came from.
        MaterialRequestItem::attachOrderedQuantities(
            $po->materialRequest?->items ?? collect(),
        );

        return $po;
    }

    /**
     * The single access check every PO read/write goes through: Admin and
     * the procurement desk reach any project; everyone else (PM) must be
     * staffed on the PO's project. Public — also called directly from
     * PurchaseOrderController::pdf(), which doesn't otherwise touch the
     * service before streaming bytes.
     */
    public function assertAccessible(PurchaseOrder $po, User $user): void
    {
        $this->assertProjectAccessible((int) $po->project_id, $user);
    }

    private function assertProjectAccessible(int $projectId, User $user): void
    {
        if ($this->isProcurementDesk($user)) {
            return;
        }
        if (! in_array($projectId, $this->accessibleProjectIds($user), true)) {
            abort(403, 'You do not have access to this project.');
        }
    }

    /**
     * The buyer's work queue: approved requests across every project that still
     * need a purchase order cut against them.
     *
     * This exists because material-request reads are project-membership-gated
     * (`project.access`) while purchase-order routes are not — so a Procurement
     * user who isn't staffed onto a project previously had no way to see, or
     * even find, the request they were meant to buy. That was survivable while
     * every request arrived pre-structured; it is not, now that a request may
     * arrive as prose for the office to map.
     *
     * @param  array<string,mixed>  $filters
     */
    public function pendingRequests(User $user, array $filters): LengthAwarePaginator
    {
        // What the queue always meant: approved, and not yet fully bought. It
        // used to say `approved` OR `ordered`, which both kept fully-ordered
        // requests in the list and relied on a status that was only ever set by
        // the first order.
        $query = MaterialRequest::query()
            ->whereHas('status', fn ($q) => $q->where('code', 'approved'))
            ->where('ordering_status', '!=', 'fully_ordered')
            ->with(['status', 'urgency', 'requester', 'project', 'photos', 'items.catalogItem', 'items.unit'])
            ->withCount(['items', 'photos']);

        if (! $this->isProcurementDesk($user)) {
            $ids = $this->accessibleProjectIds($user);
            $query->whereIn('project_id', $ids ?: [0]);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        // Prose nobody has signed off as mapped yet — the requests that actually
        // need a human to do the structuring work. Mirrors
        // MaterialRequest::needsStructuring(); its rejected-status clause is
        // implied by the approved/ordered constraint above. Grouped, so the
        // `false` branch's OR cannot escape the status and project constraints.
        if (array_key_exists('needs_structuring', $filters) && $filters['needs_structuring'] !== null) {
            $needs = (bool) $filters['needs_structuring'];

            $query->where(function ($q) use ($needs) {
                $needs
                    ? $q->whereNotNull('request_text')->whereNull('structured_at')
                    : $q->whereNull('request_text')->orWhereNotNull('structured_at');
            });
        }

        $page = $query
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));

        // How much of each requested line is still orderable, so the buyer picks
        // from a list that already knows the limit instead of meeting it as a 422.
        MaterialRequestItem::attachOrderedQuantities(
            collect($page->items())->flatMap(fn (MaterialRequest $mr) => $mr->items),
        );

        return $page;
    }

    public function create(array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user) {
            // lockForUpdate: the over-order check below reads how much of this
            // request is already on order, so two POs submitted for the same
            // request at the same instant would otherwise both read the same
            // figure and both pass. Locking the request row serialises them for
            // the life of this transaction. Nothing else contends for it.
            // Two routes into the same order. From an approved request, where the
            // request dictates the project, what may be bought and how much; or
            // directly, where the buyer states the project and the catalog lines
            // themselves. Everything past this point is identical.
            $mr = ! empty($data['material_request_id'])
                // lockForUpdate: the over-order check below reads how much of this
                // request is already on order, so two POs submitted for the same
                // request at the same instant would otherwise both read the same
                // figure and both pass. Locking the request row serialises them.
                ? MaterialRequest::lockForUpdate()->findOrFail($data['material_request_id'])
                : null;

            if ($mr) {
                $projectId = (int) $mr->project_id;

                $this->assertLinesBelongToRequest($mr, $data['items']);

                // Before the quantity check: deriving the unit is what makes the
                // requested and ordered quantities comparable in the first place.
                $data['items'] = $this->deriveLinesFromRequest($mr, $data['items']);

                $this->assertOrderedQuantitiesWithinRequest($mr, $data['items']);
            } else {
                $this->assertMayRaiseStandalone($user);
                $projectId = (int) $data['project_id'];
            }

            $this->assertProjectAccessible($projectId, $user);

            $vendorId = (int) $data['vendor_id'];
            $this->assertVendorActive(Vendor::findOrFail($vendorId));

            $po = PurchaseOrder::create([
                'po_number' => $this->generatePoNumber(Project::findOrFail($projectId)),
                // Null on the direct route — the column has always been nullable.
                'material_request_id' => $mr?->id,
                'project_id' => $projectId,
                'vendor_id' => $vendorId,
                'purchase_order_status_id' => $this->statusId(self::DRAFT),
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'created_by' => $user->id,
                ...$this->resolveShipTo($projectId, $data['ship_to_address_id'] ?? null),
                // Resolved now so a draft already shows the terms it would
                // carry; re-resolved at issue(), which is the copy that counts.
                ...$this->resolveTerms($projectId),
            ]);

            $total = '0';
            foreach ($data['items'] as $line) {
                $total = bcadd($total, $this->persistLine($po, $vendorId, $line), 2);
            }

            $po->update(['total_amount' => $total]);

            // Only where there IS a request: ordering progress is a rollup of
            // its requested lines, and a direct order has none to move.
            if ($mr) {
                $this->materialRequests->recomputeProgress($mr);
            }

            return $po->fresh(self::DETAIL_WITH);
        });
    }

    public function update(PurchaseOrder $po, array $data, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);
        $this->assertDraft($po);

        // Re-resolve rather than filling ship_to_address_id straight from the
        // request: the snapshot columns must always agree with the FK, and a
        // plain fill() would move the pointer while leaving the printed block
        // behind. Only ever reached on a draft (assertDraft above), so an issued
        // PO's snapshot stays frozen without any extra guard.
        if (array_key_exists('ship_to_address_id', $data)) {
            $addressId = $data['ship_to_address_id'] !== null ? (int) $data['ship_to_address_id'] : null;
            unset($data['ship_to_address_id']);

            // Explicit null clears the destination outright; omitting the key
            // leaves it untouched. No primary fallback here — on an existing PO
            // that would silently re-populate an address the buyer just cleared.
            $data = [
                ...$data,
                ...$this->resolveShipTo((int) $po->project_id, $addressId, fallbackToPrimary: false),
            ];
        }

        $po->fill($data)->save();

        return $po->fresh(self::DETAIL_WITH);
    }

    public function delete(PurchaseOrder $po, User $user): void
    {
        $this->assertAccessible($po, $user);
        $this->assertDraft($po);
        if ($po->deliveries()->exists()) {
            abort(409, 'Cannot delete a purchase order that has deliveries.');
        }
        $po->items()->delete();
        $po->delete();
        $this->recomputeRequestProgress($po);
    }

    // ---- Supporting attachments ----

    /**
     * Attach supporting paperwork — a rate screenshot, an emailed quote, a signed
     * contract scan. These are appended to the order's PDF so the vendor receives
     * one file instead of three.
     *
     * Allowed at every status except cancelled, unlike line items: supporting
     * files are context AROUND the order, not part of it, and they routinely
     * arrive after the order has gone out. Nothing here touches the document
     * filed at issue — the merge is composed on top of it.
     *
     * @param  array<int,\Illuminate\Http\UploadedFile>  $files
     */
    public function addAttachments(PurchaseOrder $po, array $files, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);
        $this->assertNotCancelled($po);

        $existing = $this->merge->supportingAttachments($po)->count();

        // Counted against what is already stored, so the cap cannot be walked
        // past one request at a time.
        if ($existing + count($files) > self::MAX_ATTACHMENTS) {
            abort(422, 'A purchase order may carry at most '.self::MAX_ATTACHMENTS.' supporting files.');
        }

        return DB::transaction(function () use ($po, $files, $user) {
            foreach ($files as $file) {
                $this->attachments->storeUploadedFile($file, [
                    'attachable_type' => PurchaseOrder::class,
                    'attachable_id' => $po->id,
                    'project_id' => $po->project_id,
                    'attachment_type' => 'supporting',
                    'directory' => 'purchase-order-attachments',
                    'uploaded_by' => $user->id,
                ]);
            }

            return $po->fresh(self::DETAIL_WITH);
        });
    }

    public function removeAttachment(PurchaseOrder $po, Attachment $attachment, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);
        $this->assertNotCancelled($po);

        $attachment->delete();

        return $po->fresh(self::DETAIL_WITH);
    }

    private function assertNotCancelled(PurchaseOrder $po): void
    {
        if ($this->statusCode($po) === self::CANCELLED) {
            abort(409, 'A cancelled purchase order cannot have its attachments changed.');
        }
    }

    // ---- Line items (draft only) ----

    /**
     * Add a line to a draft order.
     *
     * Every rule that governs a line at create applies here too, through the
     * same guards: it must name a line of this PO's material request (once that
     * request has lines), and it may not push the cumulative ordered quantity
     * past what was requested.
     *
     * @param  array<string,mixed>  $data
     */
    public function addItem(PurchaseOrder $po, array $data, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);
        $this->assertDraft($po);

        return DB::transaction(function () use ($po, $data) {
            $mr = $this->lockedRequestFor($po);

            if ($mr) {
                $this->assertLinesBelongToRequest($mr, [$data]);
                $data = $this->deriveLinesFromRequest($mr, [$data])[0];
                $this->assertOrderedQuantitiesWithinRequest($mr, [$data]);
            }

            $this->persistLine($po, (int) $po->vendor_id, $data);
            $this->recomputeTotal($po);
            $this->recomputeRequestProgress($po);

            return $po->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * Change one line of a draft order.
     *
     * Reprices ONLY when the catalog item changes or a `unit_price` is stated.
     * A quantity fix must never silently reprice against a vendor rate that has
     * moved since the order was drafted — the snapshot is the point.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateItem(PurchaseOrder $po, PurchaseOrderItem $item, array $data, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);
        $this->assertDraft($po);

        return DB::transaction(function () use ($po, $item, $data) {
            $mr = $this->lockedRequestFor($po);

            // Judge the line as it will BE, not as it was: absent keys keep their
            // current values, so the merged state is what the rules must see.
            $merged = [
                'material_request_item_id' => array_key_exists('material_request_item_id', $data)
                    ? $data['material_request_item_id']
                    : $item->material_request_item_id,
                'quantity_ordered' => $data['quantity_ordered'] ?? $item->quantity_ordered,
                // Carried through so an edit cannot quietly point the line at a
                // different item than the request line it fulfils — the same rule
                // create() applies, judged on the post-edit state.
                'catalog_item_id' => $data['catalog_item_id'] ?? $item->catalog_item_id,
                'unit_id' => $data['unit_id'] ?? $item->unit_id,
            ];

            if ($mr) {
                $this->assertLinesBelongToRequest($mr, [$merged]);
                $merged = $this->deriveLinesFromRequest($mr, [$merged])[0];

                // Excluding this line's own contribution — otherwise its existing
                // 15 would count as "already ordered" and 15 -> 16 could never pass.
                $this->assertOrderedQuantitiesWithinRequest($mr, [$merged], $item->id);

                // Whatever the request line dictates wins over what was sent.
                $data['catalog_item_id'] = $merged['catalog_item_id'];
                $data['unit_id'] = $merged['unit_id'];
            }

            $catalogItemId = (int) ($data['catalog_item_id'] ?? $item->catalog_item_id);
            $repricing = $catalogItemId !== (int) $item->catalog_item_id
                || array_key_exists('unit_price', $data);

            $pricing = $repricing
                ? $this->resolveLinePricing(
                    (int) $po->vendor_id,
                    CatalogItem::findOrFail($catalogItemId),
                    $data,
                    $item,
                )
                : [];

            $item->fill([...$data, ...$pricing]);

            // Recomputed from the post-fill state, so a quantity change, a price
            // change, or both land on a consistent line_total.
            $item->line_total = bcmul((string) $item->quantity_ordered, (string) $item->unit_price, 2);
            $item->save();

            $this->recomputeTotal($po);
            $this->recomputeRequestProgress($po);

            return $po->fresh(self::DETAIL_WITH);
        });
    }

    /** Remove a line from a draft order. */
    public function removeItem(PurchaseOrder $po, PurchaseOrderItem $item, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);
        $this->assertDraft($po);

        // create() requires at least one line, so a PO must never be editable
        // into a state create itself would reject. It also keeps a zero-line,
        // zero-total order from ever reaching issue().
        if ($po->items()->count() <= 1) {
            abort(409, 'A purchase order must keep at least one line item. Delete the draft instead.');
        }

        // Unreachable on a draft — deliveries need an issued order — but this
        // mirrors delete()'s delivery guard rather than trusting the status alone.
        if ($item->deliveryItems()->exists()) {
            abort(409, 'Cannot remove a line item that already has deliveries recorded against it.');
        }

        return DB::transaction(function () use ($po, $item) {
            $item->delete();
            $this->recomputeTotal($po);
            $this->recomputeRequestProgress($po);

            return $po->fresh(self::DETAIL_WITH);
        });
    }

    /**
     * The order's material request, row-locked for the rest of the transaction.
     *
     * Same reasoning as create(): the over-order check reads how much of the
     * request is already on order, so concurrent edits must serialise. Null when
     * the PO has no request behind it (the column is nullable at schema level),
     * in which case there is nothing to measure against.
     */
    /**
     * Tell the originating request that its coverage may have moved.
     *
     * Called from every event that changes what is on order — a line added,
     * changed or removed, and an order cancelled or deleted. Cancelling is the
     * one that used to be missing: the request stayed marked as ordered with
     * nothing actually on order behind it.
     */
    private function recomputeRequestProgress(PurchaseOrder $po): void
    {
        $mr = $po->material_request_id
            ? MaterialRequest::find($po->material_request_id)
            : null;

        if ($mr) {
            $this->materialRequests->recomputeProgress($mr);
        }
    }

    private function lockedRequestFor(PurchaseOrder $po): ?MaterialRequest
    {
        return $po->material_request_id
            ? MaterialRequest::lockForUpdate()->find($po->material_request_id)
            : null;
    }

    public function issue(PurchaseOrder $po, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);

        if ($this->statusCode($po) !== self::DRAFT) {
            abort(409, 'Only a draft purchase order can be issued.');
        }

        // Optional while drafting — a buyer may start a PO before the site
        // address exists — but mandatory the moment it becomes a real order,
        // since an issued PO is what reaches the vendor. Same shape as
        // ChangeOrderService::prepareDocument() requiring `value` before the CO
        // document is generated and leaves for the GC.
        if (! $po->hasShipTo()) {
            abort(422, 'A delivery address must be set before the purchase order can be issued.');
        }

        // Checked again here, not only at create: a vendor may be retired while
        // the order sits in draft, and issue() is the moment it becomes a real
        // order to them. Same precondition shape as the ship-to check above.
        // An already-issued order is never revisited — only NEW commitment is
        // blocked. To release a stranded draft, reactivate the vendor or delete
        // the draft and re-cut it against another vendor.
        $this->assertVendorActive($po->vendor);

        // Re-resolve the terms at the moment of issue, then freeze.
        //
        // Deliberately unlike the ship-to snapshot, which is settled at create:
        // the requirement is that a PO carries the terms IN FORCE WHEN IT WAS
        // ISSUED, and an administrator may publish revised terms while the
        // order sits in draft. From here on assertDraft() blocks every edit, so
        // this is the last moment the copy can move — and the right one.
        $po->update([
            'purchase_order_status_id' => $this->statusId(self::ISSUED),
            'issued_by' => $user->id,
            'issued_at' => now(),
            ...$this->resolveTerms((int) $po->project_id),
        ]);

        // File the document now, from the just-frozen state. Deliberately after
        // the update, so the stored PDF carries the issued status, the issuer
        // and the terms resolved a moment ago — the order exactly as issued.
        $this->pdf->storeFor($po->refresh(), $user->id);

        return $po->fresh(self::DETAIL_WITH);
    }

    /**
     * Email the issued order to the vendor with its filed PDF attached, and flip
     * issued -> sent. Mirrors RfqService::submit(), down to the EmailLog row and
     * the queued job that carries the attachment.
     *
     * Both preconditions are checked BEFORE the status moves, so a PO is never
     * left marked as sent when nothing could go out: no vendor address to send
     * to, and no filed document to attach. The document is the copy stored at
     * issue() — deliberately not re-rendered, so the vendor receives the order
     * exactly as issued.
     */
    public function send(PurchaseOrder $po, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);

        if ($this->statusCode($po) !== self::ISSUED) {
            abort(409, 'Only an issued purchase order can be marked as sent.');
        }

        $vendor = $po->vendor ?? Vendor::findOrFail($po->vendor_id);

        if (blank($vendor->email)) {
            abort(422, 'This vendor has no email on file; add one before sending the purchase order.');
        }

        // issue() always files one, so a miss here means the record or the file
        // has been lost. Better a clear 422 than a PO marked sent whose delivery
        // fails later in a worker where nobody is watching.
        $document = $this->pdf->storedDocument($po);

        if (! $document) {
            abort(422, 'The issued purchase order document is missing, so there is nothing to send.');
        }

        return DB::transaction(function () use ($po, $vendor) {
            $po->update([
                'purchase_order_status_id' => $this->statusId(self::SENT),
                'sent_at' => now(),
            ]);

            $emailLog = EmailLog::create([
                'to_email' => $vendor->email,
                'subject' => "Purchase Order {$po->po_number} from ".config('company.name'),
                'template' => 'emails.purchase-order.order',
                'mailable_type' => PurchaseOrder::class,
                'mailable_id' => $po->id,
                'status' => 'queued',
            ]);

            // afterCommit: nothing is queued unless the transition itself commits.
            SendPurchaseOrderEmailJob::dispatch($emailLog->id, $po->id)->afterCommit();

            return $po->fresh(self::DETAIL_WITH);
        });
    }

    public function cancel(PurchaseOrder $po, User $user): PurchaseOrder
    {
        $this->assertAccessible($po, $user);

        if (in_array($this->statusCode($po), ['received', self::CANCELLED], true)) {
            abort(409, 'This purchase order can no longer be cancelled.');
        }
        if ($po->deliveries()->exists()) {
            abort(409, 'Cannot cancel a purchase order that already has deliveries.');
        }
        $po->update(['purchase_order_status_id' => $this->statusId(self::CANCELLED)]);
        $this->recomputeRequestProgress($po);

        return $po->fresh(self::DETAIL_WITH);
    }

    // ---- internals ----

    /**
     * Resolve the ship-to destination into the columns a PO stores for it: the
     * FK for traceability, plus the snapshot that actually gets printed.
     *
     * Snapshotting rather than joining at render time is the whole point — an
     * address may be corrected or retired months after the order shipped, and
     * an issued PO must keep printing what it printed on the day. This mirrors
     * how purchase_order_items already keeps vendor_rate_id beside a frozen
     * unit_price.
     *
     * The project name and code are snapshotted for the same reason: both are
     * editable through PATCH /projects/{project}, so deriving them live would
     * let a rename rewrite the header of an order already with a vendor.
     *
     * @param  bool  $fallbackToPrimary  Use the project's primary address when
     *                                   none is named. Wanted at create (the
     *                                   dropdown's default), not on update,
     *                                   where it would undo a deliberate clear.
     * @return array<string,mixed>
     */
    private function resolveShipTo(int $projectId, ?int $addressId, bool $fallbackToPrimary = true): array
    {
        $project = Project::findOrFail($projectId);

        if ($addressId !== null) {
            $address = ProjectDeliveryAddress::find($addressId);

            // Belt-and-braces against one project's site being attached to
            // another's order. The FormRequest only checks the row exists; it
            // can't check ownership, because the project is derived from the
            // material request inside this service.
            if (! $address || (int) $address->project_id !== $projectId) {
                abort(422, 'The delivery address does not belong to this purchase order\'s project.');
            }
        } else {
            $address = $fallbackToPrimary ? $project->primaryDeliveryAddress()->first() : null;
        }

        if (! $address) {
            // Clear the block wholesale. Leaving a stale snapshot behind when
            // the FK goes null would print an address the PO no longer claims.
            return [
                'ship_to_address_id' => null,
                'ship_to_project_name' => null,
                'ship_to_project_code' => null,
                ...array_fill_keys(array_keys((new ProjectDeliveryAddress)->toShipToSnapshot()), null),
            ];
        }

        return [
            'ship_to_address_id' => $address->id,
            'ship_to_project_name' => $project->name,
            'ship_to_project_code' => $project->code,
            ...$address->toShipToSnapshot(),
        ];
    }

    /**
     * Resolve the Terms & Conditions this PO is issued under into the columns
     * that store them: the FK for traceability, plus the snapshot that prints.
     *
     * Snapshotting matters more here than anywhere else in the document — a PO
     * is semi-contractual, so revising the company's standard terms must never
     * rewrite what an order already placed said it was governed by.
     *
     * All-nulls when nothing is configured, which is a legitimate outcome:
     * unlike the ship-to address, missing terms never block issuing. The block
     * simply doesn't print.
     *
     * @return array<string,mixed>
     */
    private function resolveTerms(int $projectId): array
    {
        $terms = $this->terms->resolveFor($projectId);

        if (! $terms) {
            return ['terms_id' => null, 'terms_title' => null, 'terms_body' => null];
        }

        return ['terms_id' => $terms->id, ...$terms->toTermsSnapshot()];
    }

    /**
     * A PO line may declare which requested line it fulfils — but only a line of
     * the request this PO is being cut from. StorePurchaseOrderRequest reports
     * this per line for API clients; this is the invariant itself, so no future
     * caller can write a cross-request link. One query however many lines.
     *
     * Null ids are skipped, not rejected: a line mapped from free-text prose has
     * no requested line behind it, which is the normal case for a prose request.
     *
     * @param  array<int,array<string,mixed>>  $lines
     */
    private function assertLinesBelongToRequest(MaterialRequest $mr, array $lines): void
    {
        $submitted = array_values(array_unique(array_map(
            static fn ($line) => (int) $line['material_request_item_id'],
            array_filter($lines, static fn ($line) => ($line['material_request_item_id'] ?? null) !== null),
        )));

        // Unlinked lines are only acceptable while the request carries no lines
        // of its own (a prose request mapped straight onto PO lines). Once it
        // has lines, an unlinked PO line would escape the over-order check.
        $unlinked = count($lines) - count(array_filter(
            $lines,
            static fn ($line) => ($line['material_request_item_id'] ?? null) !== null,
        ));

        if ($unlinked > 0 && $mr->items()->exists()) {
            abort(422, 'This material request has line items, so every purchase order line must name the requested line it fulfils.');
        }

        if ($submitted === []) {
            return;
        }

        $owned = $mr->items()->whereIn('id', $submitted)->pluck('id')->all();

        if (count($owned) !== count($submitted)) {
            abort(422, 'A purchase order line references a material request line that does not belong to this request.');
        }
    }

    /**
     * Fill in what the requested line already says, and refuse to contradict it.
     *
     * A PO line that names a request line has ONE source of truth for WHAT is
     * being bought: that request line. Taking the catalog item from the client as
     * well gives two values that can disagree with nothing comparing them — which
     * is how an order for "Electric Breakers" was accepted against a request for
     * "PPR Cold & Hot Water Pipe 32mm".
     *
     * Derived, not merely validated, so a client can send just the link and the
     * quantity. Sending a value that AGREES is fine; sending one that disagrees
     * is refused rather than silently overridden, because a silent swap means the
     * person who raised the request never learns their item was changed — and the
     * order's own printed terms say substitutions need written approval.
     *
     * The unit comes along for a second reason: the over-order rule compares
     * requested against ordered quantities, and with no conversion table in the
     * system those two numbers only mean the same thing in the same unit.
     *
     * Only fields with a value on the request line are derived: a free-text line
     * carries no catalog item, so the buyer still supplies one — that is the
     * mapping step, not a contradiction.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,array<string,mixed>>
     */
    private function deriveLinesFromRequest(MaterialRequest $mr, array $lines): array
    {
        $requestLines = $mr->items()->get()->keyBy('id');

        foreach ($lines as $index => $line) {
            $itemId = $line['material_request_item_id'] ?? null;

            if ($itemId === null || ! $requestLines->has((int) $itemId)) {
                continue;
            }

            $requested = $requestLines->get((int) $itemId);

            foreach (['catalog_item_id' => 'catalog item', 'unit_id' => 'unit'] as $field => $label) {
                if ($requested->{$field} === null) {
                    continue;   // nothing to derive from: a free-text request line
                }

                $supplied = $line[$field] ?? null;

                if ($supplied !== null && (int) $supplied !== (int) $requested->{$field}) {
                    abort(422, $this->mismatchMessage($field, $label, $requested, (int) $supplied));
                }

                $lines[$index][$field] = $requested->{$field};
            }

            // A convenience, not a rule: the request's cost code stands in when
            // the buyer doesn't state one, and an explicit value still wins —
            // recoding an order is a legitimate accounting decision.
            if (($line['cost_code_id'] ?? null) === null && $requested->cost_code_id !== null) {
                $lines[$index]['cost_code_id'] = $requested->cost_code_id;
            }
        }

        return $lines;
    }

    /** Names both sides, so the caller can see exactly what disagreed. */
    private function mismatchMessage(string $field, string $label, MaterialRequestItem $requested, int $supplied): string
    {
        if ($field === 'catalog_item_id') {
            $names = CatalogItem::whereIn('id', [$requested->catalog_item_id, $supplied])->pluck('name', 'id');

            return sprintf(
                'This line fulfils a request for %s, but the order names %s. Order the requested item, or change the material request first.',
                $names[$requested->catalog_item_id] ?? "catalog item #{$requested->catalog_item_id}",
                $names[$supplied] ?? "catalog item #{$supplied}",
            );
        }

        $codes = Unit::whereIn('id', [$requested->unit_id, $supplied])->pluck('code', 'id');

        return sprintf(
            'This line is requested in %s but ordered in %s. Quantities are compared directly, so both must use the same unit.',
            $codes[$requested->unit_id] ?? "unit #{$requested->unit_id}",
            $codes[$supplied] ?? "unit #{$supplied}",
        );
    }

    /**
     * No more may be ordered against a requested line than was requested —
     * counting every PO already raised from that request, not just this one.
     *
     * StorePurchaseOrderRequest reports this per line for API clients; this is
     * the invariant, so no future caller can over-order. Aggregation lives in
     * MaterialRequestItem::orderedQuantities() and is shared by both.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @param  int|null  $excludePurchaseOrderItemId  A line being edited, whose
     *                   own current quantity must not count against itself.
     */
    private function assertOrderedQuantitiesWithinRequest(MaterialRequest $mr, array $lines, ?int $excludePurchaseOrderItemId = null): void
    {
        $claimed = [];

        foreach ($lines as $line) {
            $itemId = $line['material_request_item_id'] ?? null;

            if ($itemId === null) {
                continue;
            }

            // Summed per requested line: several PO lines may fulfil one request
            // line, and only their total can be judged against it.
            $claimed[(int) $itemId] = bcadd(
                $claimed[(int) $itemId] ?? '0',
                (string) ($line['quantity_ordered'] ?? 0),
                3,
            );
        }

        if ($claimed === []) {
            return;
        }

        // The catalog item comes along so the message can name what is actually
        // over-ordered; a row id tells the person reading it nothing.
        $requested = $mr->items()->with('catalogItem')->whereIn('id', array_keys($claimed))->get()->keyBy('id');
        $alreadyOrdered = MaterialRequestItem::orderedQuantities(array_keys($claimed), $excludePurchaseOrderItemId);

        foreach ($claimed as $itemId => $claiming) {
            $line = $requested->get($itemId);
            $already = $alreadyOrdered[$itemId] ?? '0';
            $limit = (string) ($line?->quantity ?? '0');

            // bccomp at the column's own 3 decimals — exact, not a float epsilon.
            if (bccomp(bcadd($already, $claiming, 3), $limit, 3) > 0) {
                $remaining = bccomp($limit, $already, 3) > 0 ? bcsub($limit, $already, 3) : '0';

                abort(422, sprintf(
                    'Ordering %s of %s exceeds what was requested: requested %s, already ordered %s, remaining %s.',
                    MaterialRequestItem::formatQuantity($claiming),
                    $line?->displayName() ?? "line #{$itemId}",
                    MaterialRequestItem::formatQuantity($limit),
                    MaterialRequestItem::formatQuantity($already),
                    MaterialRequestItem::formatQuantity($remaining),
                ));
            }
        }
    }

    /**
     * The client's purchase-order number: `SJ-2026-09-00001` — project short
     * code, year, 2-digit month, then a 5-digit series.
     *
     * The series counts PER PROJECT and never resets: a project's orders read
     * 00001, 00002, 00003 whatever month they fall in, so October's third order
     * is SJ-2026-10-00003. The date is taken at the moment the order is created,
     * not frozen from the project's first one.
     *
     * The counter comes from DocumentSequenceService, whose atomic
     * `UPDATE … RETURNING` is what makes two simultaneous creates impossible to
     * collide — a MAX()+1 here would race under load, and the partial unique
     * index on po_number would then reject the loser outright.
     */
    private function generatePoNumber(Project $project): string
    {
        if (blank($project->short_code)) {
            abort(422, 'This project has no short code, so a purchase order number cannot be generated.');
        }

        $series = $this->sequences->nextValue('purchase_order', 'project', (int) $project->id, $project->short_code);

        return sprintf(
            '%s-%s-%s-%05d',
            $project->short_code,
            now()->format('Y'),
            now()->format('m'),
            $series,
        );
    }

    /**
     * Raising an order with no material request behind it is its own right.
     *
     * Everything else about a purchase order is gated by
     * `manage_purchase_orders`; this is the one thing that skips the
     * request-and-approve chain altogether, which procurement literature calls
     * maverick spend. Keeping it separate means the bypass is granted to
     * somebody rather than implied by being able to cut orders at all, and it
     * can be taken away without removing their ability to buy from approved
     * requests. Procore draws the same line with its granular "Create Purchase
     * Order Contract" right.
     *
     * Checked here rather than at the route: one endpoint serves both routes,
     * so the gate depends on the payload.
     */
    private function assertMayRaiseStandalone(User $user): void
    {
        $user->unsetRelation('permissions');

        if (! $user->can('create_standalone_purchase_order')) {
            abort(403, 'You cannot raise a purchase order without a material request. Create the order from an approved material request instead.');
        }
    }

    /**
     * A retired vendor takes no new orders. "Retired" here means the same as it
     * does for catalog items (CatalogItemService::search()): never offered for
     * new selection, while everything already recorded against them stands —
     * their issued orders still read, print and receive deliveries as before.
     *
     * StorePurchaseOrderRequest reports this on `vendor_id` for API clients;
     * this is the invariant, covering issue() and any future non-HTTP caller.
     */
    private function assertVendorActive(Vendor $vendor): void
    {
        if (! $vendor->is_active) {
            abort(422, 'This vendor is inactive and cannot receive purchase orders.');
        }
    }

    private function persistLine(PurchaseOrder $po, int $vendorId, array $line): string
    {
        // Normally derived from the request line; required from the caller only
        // where there was nothing to derive from — a free-text request line or a
        // prose-only request. Pricing resolves through the catalog item, so a
        // line without one cannot be priced at all.
        if (($line['catalog_item_id'] ?? null) === null) {
            abort(422, 'This line needs a catalog item: the material request line it fulfils is free text, so the item has to be chosen here.');
        }

        $catalogItem = CatalogItem::findOrFail($line['catalog_item_id']);
        $pricing = $this->resolveLinePricing($vendorId, $catalogItem, $line);

        $lineTotal = bcmul((string) $line['quantity_ordered'], (string) $pricing['unit_price'], 2);

        $po->items()->create([
            'material_request_item_id' => $line['material_request_item_id'] ?? null,
            'cost_code_id' => $line['cost_code_id'] ?? null,
            'catalog_item_id' => $catalogItem->id,
            'description' => $line['description'] ?? null,
            'quantity_ordered' => $line['quantity_ordered'],
            'line_total' => $lineTotal,
            ...$pricing,
        ]);

        return $lineTotal;
    }

    /**
     * Settle a line's price, the rate row behind it, and its unit.
     *
     * Shared by create and by an edit that reprices, so both resolve identically.
     *
     * Snapshotting rule: when the buyer states a `unit_price`, the rate on file
     * was NOT used, so `vendor_rate_id` stays null (the schema means "the exact
     * rate row used"). Only a price derived from the rate records it.
     *
     * @param  array<string,mixed>  $line
     * @return array{unit_price: mixed, vendor_rate_id: int|null, unit_id: int|null}
     */
    private function resolveLinePricing(int $vendorId, CatalogItem $catalogItem, array $line, ?PurchaseOrderItem $existing = null): array
    {
        $unitId = $line['unit_id'] ?? $existing?->unit_id ?? $catalogItem->default_unit_id;

        if (array_key_exists('unit_price', $line) && $line['unit_price'] !== null) {
            return ['unit_price' => $line['unit_price'], 'vendor_rate_id' => null, 'unit_id' => $unitId];
        }

        $currentRate = VendorRate::where('vendor_id', $vendorId)
            ->where('catalog_item_id', $catalogItem->id)
            ->whereNull('effective_to')
            ->first();

        if ($currentRate === null) {
            abort(422, "No current vendor rate for catalog item #{$catalogItem->id}; provide a unit_price.");
        }

        return ['unit_price' => $currentRate->rate, 'vendor_rate_id' => $currentRate->id, 'unit_id' => $unitId];
    }

    /**
     * Re-sum `total_amount` from the lines that are actually there.
     *
     * Same 2dp bcadd arithmetic as create()'s running sum, but read back from
     * the rows, so it cannot drift after a line is added, changed or removed.
     */
    private function recomputeTotal(PurchaseOrder $po): void
    {
        $total = '0';

        foreach ($po->items()->pluck('line_total') as $lineTotal) {
            $total = bcadd($total, (string) $lineTotal, 2);
        }

        $po->update(['total_amount' => $total]);
    }

    private function assertDraft(PurchaseOrder $po): void
    {
        if ($this->statusCode($po) !== self::DRAFT) {
            abort(409, 'Only a draft purchase order can be modified.');
        }
    }

    private function statusCode(PurchaseOrder $po): string
    {
        return $po->status?->code ?? PurchaseOrderStatus::whereKey($po->purchase_order_status_id)->value('code');
    }

    private function statusId(string $code): int
    {
        return $this->statusIdCache[$code] ??= PurchaseOrderStatus::where('code', $code)->value('id')
            ?? abort(500, "Purchase order status '{$code}' is not seeded.");
    }
}
