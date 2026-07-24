<?php

namespace App\Services\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderStatus;
use App\Models\VendorRate;
use App\Services\Document\DocumentSequenceService;
use App\Services\MaterialRequest\MaterialRequestService;
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
        'vendor', 'status', 'issuedBy',
        'items.catalogItem', 'items.unit', 'items.costCode',
        'deliveries',
    ];

    /** @var array<string,int> */
    private array $statusIdCache = [];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly MaterialRequestService $materialRequests,
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
        $po->update([
            'purchase_order_status_id' => $this->statusId(self::ISSUED),
            'issued_by' => $userId,
            'issued_at' => now(),
        ]);

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
