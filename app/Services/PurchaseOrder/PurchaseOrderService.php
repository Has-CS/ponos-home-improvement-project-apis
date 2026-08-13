<?php

namespace App\Services\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderStatus;
use App\Models\VendorRate;
use App\Services\Document\DocumentSequenceService;
use App\Services\MaterialRequest\MaterialRequestService;
use App\Services\PurchaseOrderTerms\PurchaseOrderTermsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
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

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly MaterialRequestService $materialRequests,
        private readonly PurchaseOrderTermsService $terms,
        private readonly PurchaseOrderPdfService $pdf,
    ) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrder::query()->with(self::LIST_WITH);

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

        return $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(PurchaseOrder $po): PurchaseOrder
    {
        return $po->load(self::DETAIL_WITH);
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
    public function pendingRequests(array $filters): LengthAwarePaginator
    {
        $query = MaterialRequest::query()
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['approved', 'ordered']))
            ->with(['status', 'urgency', 'requester', 'project', 'photos', 'items.catalogItem', 'items.unit'])
            ->withCount(['items', 'photos']);

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        // Prose that nobody has mapped to catalog items yet — the requests that
        // actually need a human to do the structuring work.
        if (array_key_exists('needs_structuring', $filters) && $filters['needs_structuring'] !== null) {
            $needs = (bool) $filters['needs_structuring'];

            $query->where(function ($q) use ($needs) {
                $needs
                    ? $q->whereNotNull('request_text')->whereDoesntHave('items')
                    : $q->whereNull('request_text')->orWhereHas('items');
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(array $data, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $userId) {
            $mr = MaterialRequest::findOrFail($data['material_request_id']);
            $vendorId = (int) $data['vendor_id'];

            $po = PurchaseOrder::create([
                'po_number' => $this->sequences->next('purchase_order', 'PO'),
                'material_request_id' => $mr->id,
                'project_id' => $mr->project_id,
                'vendor_id' => $vendorId,
                'purchase_order_status_id' => $this->statusId(self::DRAFT),
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'created_by' => $userId,
                ...$this->resolveShipTo((int) $mr->project_id, $data['ship_to_address_id'] ?? null),
                // Resolved now so a draft already shows the terms it would
                // carry; re-resolved at issue(), which is the copy that counts.
                ...$this->resolveTerms((int) $mr->project_id),
            ]);

            $total = '0';
            foreach ($data['items'] as $line) {
                $total = bcadd($total, $this->persistLine($po, $vendorId, $line), 2);
            }

            $po->update(['total_amount' => $total]);

            // First PO cut against an approved request advances it to ordered.
            $this->materialRequests->markOrdered($mr);

            return $po->fresh(self::DETAIL_WITH);
        });
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
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

    public function delete(PurchaseOrder $po): void
    {
        $this->assertDraft($po);
        if ($po->deliveries()->exists()) {
            abort(409, 'Cannot delete a purchase order that has deliveries.');
        }
        $po->items()->delete();
        $po->delete();
    }

    public function issue(PurchaseOrder $po, int $userId): PurchaseOrder
    {
        if ($this->statusCode($po) !== self::DRAFT) {
            abort(409, 'Only a draft purchase order can be issued.');
        }

        // Optional while drafting — a buyer may start a PO before the site
        // address exists — but mandatory the moment it becomes a real order,
        // since an issued PO is what reaches the vendor. Same shape as
        // ChangeOrderService::counterSign() requiring `value` before the CO
        // leaves for the GC.
        if (! $po->hasShipTo()) {
            abort(422, 'A delivery address must be set before the purchase order can be issued.');
        }

        // Re-resolve the terms at the moment of issue, then freeze.
        //
        // Deliberately unlike the ship-to snapshot, which is settled at create:
        // the requirement is that a PO carries the terms IN FORCE WHEN IT WAS
        // ISSUED, and an administrator may publish revised terms while the
        // order sits in draft. From here on assertDraft() blocks every edit, so
        // this is the last moment the copy can move — and the right one.
        $po->update([
            'purchase_order_status_id' => $this->statusId(self::ISSUED),
            'issued_by' => $userId,
            'issued_at' => now(),
            ...$this->resolveTerms((int) $po->project_id),
        ]);

        // File the document now, from the just-frozen state. Deliberately after
        // the update, so the stored PDF carries the issued status, the issuer
        // and the terms resolved a moment ago — the order exactly as issued.
        $this->pdf->storeFor($po->refresh(), $userId);

        return $po->fresh(self::DETAIL_WITH);
    }

    public function send(PurchaseOrder $po): PurchaseOrder
    {
        if ($this->statusCode($po) !== self::ISSUED) {
            abort(409, 'Only an issued purchase order can be marked as sent.');
        }
        $po->update([
            'purchase_order_status_id' => $this->statusId(self::SENT),
            'sent_at' => now(),
        ]);

        return $po->fresh(self::DETAIL_WITH);
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if (in_array($this->statusCode($po), ['received', self::CANCELLED], true)) {
            abort(409, 'This purchase order can no longer be cancelled.');
        }
        if ($po->deliveries()->exists()) {
            abort(409, 'Cannot cancel a purchase order that already has deliveries.');
        }
        $po->update(['purchase_order_status_id' => $this->statusId(self::CANCELLED)]);

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

    private function persistLine(PurchaseOrder $po, int $vendorId, array $line): string
    {
        $catalogItem = CatalogItem::findOrFail($line['catalog_item_id']);
        $unitId = $line['unit_id'] ?? $catalogItem->default_unit_id;

        $currentRate = VendorRate::where('vendor_id', $vendorId)
            ->where('catalog_item_id', $catalogItem->id)
            ->whereNull('effective_to')
            ->first();

        // Snapshot the price. When the buyer overrides unit_price, the rate on
        // file was NOT used, so vendor_rate_id stays null (schema: "exact rate
        // row used"). Only when we derive the price from the rate do we record it.
        if (array_key_exists('unit_price', $line) && $line['unit_price'] !== null) {
            $unitPrice = $line['unit_price'];
            $vendorRateId = null;
        } elseif ($currentRate !== null) {
            $unitPrice = $currentRate->rate;
            $vendorRateId = $currentRate->id;
        } else {
            abort(422, "No current vendor rate for catalog item #{$catalogItem->id}; provide a unit_price.");
        }

        $lineTotal = bcmul((string) $line['quantity_ordered'], (string) $unitPrice, 2);

        $po->items()->create([
            'material_request_item_id' => $line['material_request_item_id'] ?? null,
            'cost_code_id' => $line['cost_code_id'] ?? null,
            'catalog_item_id' => $catalogItem->id,
            'vendor_rate_id' => $vendorRateId,
            'unit_id' => $unitId,
            'description' => $line['description'] ?? null,
            'quantity_ordered' => $line['quantity_ordered'],
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ]);

        return $lineTotal;
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
