<?php

namespace App\Services\Delivery;

use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderStatus;
use App\Models\User;
use App\Services\MaterialRequest\MaterialRequestService;
use App\Support\Concerns\ScopesProjectAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DeliveryService
{
    use ScopesProjectAccess;

    private const PO_PARTIALLY_RECEIVED = 'partially_received';
    private const PO_RECEIVED = 'received';

    private const LIST_WITH = ['receivedBy'];
    private const DETAIL_WITH = [
        'receivedBy',
        'items.purchaseOrderItem',
        'discrepancies.discrepancyType', 'discrepancies.reportedBy',
    ];

    /** @var array<string,int> */
    private array $statusIdCache = [];

    public function __construct(private readonly MaterialRequestService $materialRequests) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = Delivery::query()->with(self::LIST_WITH)->withCount('items');

        // Access scoping: admins and the procurement desk see deliveries across
        // all projects, matching the purchase-orders index; everyone else
        // (Foreman, Site Engineer, Assistant PM, Coordinator, Project Manager)
        // only sees deliveries for projects they're actively staffed on.
        if (! $this->isProcurementDesk($user)) {
            $ids = $this->accessibleProjectIds($user);
            $query->whereHas('purchaseOrder', fn ($q) => $q->whereIn('project_id', $ids ?: [0]));
        }

        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', $filters['purchase_order_id']);
        }
        if (array_key_exists('has_discrepancy', $filters) && $filters['has_discrepancy'] !== null) {
            $query->where('has_discrepancy', (bool) $filters['has_discrepancy']);
        }

        return $query->orderByDesc('received_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(Delivery $delivery, User $user): Delivery
    {
        $this->assertAccessible($delivery, $user);

        return $delivery->load(self::DETAIL_WITH);
    }

    /**
     * Record a receipt against a PO: create the delivery + its lines, then
     * recompute the PO's received status and cascade fulfillment to the
     * originating material request.
     */
    public function record(PurchaseOrder $po, array $data, User $user): Delivery
    {
        $this->assertProjectAccessible((int) $po->project_id, $user);

        $status = $po->status?->code ?? PurchaseOrderStatus::whereKey($po->purchase_order_status_id)->value('code');
        if (in_array($status, ['draft', 'cancelled'], true)) {
            abort(409, 'Deliveries can only be recorded against an issued purchase order.');
        }

        return DB::transaction(function () use ($po, $data, $user) {
            $delivery = $po->deliveries()->create([
                'received_by' => $user->id,
                'received_at' => $data['received_at'] ?? now(),
                'bol_number' => $data['bol_number'] ?? null,
                'has_discrepancy' => false,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $line) {
                $delivery->items()->create([
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'quantity_received' => $line['quantity_received'],
                    'quantity_accepted' => $line['quantity_accepted'] ?? null,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            $this->recomputePurchaseOrderStatus($po);

            if ($po->material_request_id) {
                $this->materialRequests->recomputeFulfillment($po->materialRequest);
            }

            return $delivery->fresh(self::DETAIL_WITH);
        });
    }

    public function addDiscrepancy(Delivery $delivery, array $data, User $user): Delivery
    {
        $this->assertAccessible($delivery, $user);

        $delivery->discrepancies()->create([
            'delivery_item_id' => $data['delivery_item_id'] ?? null,
            'discrepancy_type_id' => $data['discrepancy_type_id'],
            'description' => $data['description'],
            'reported_by' => $user->id,
        ]);

        $delivery->update(['has_discrepancy' => true]);

        return $delivery->fresh(self::DETAIL_WITH);
    }

    /**
     * Sum received quantity per PO line across all its deliveries and set the
     * PO to received (all lines fully received) or partially_received.
     */
    private function recomputePurchaseOrderStatus(PurchaseOrder $po): void
    {
        $poItems = $po->items()->get(['id', 'quantity_ordered']);
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

        $target = $allFullyReceived ? self::PO_RECEIVED : ($anyReceived ? self::PO_PARTIALLY_RECEIVED : null);
        if ($target !== null) {
            $po->update(['purchase_order_status_id' => $this->statusId($target)]);
        }
    }

    private function statusId(string $code): int
    {
        return $this->statusIdCache[$code] ??= PurchaseOrderStatus::where('code', $code)->value('id')
            ?? abort(500, "Purchase order status '{$code}' is not seeded.");
    }

    /**
     * The single-resource counterpart to paginate()'s inline scoping: Admin
     * and the procurement desk reach any project; everyone else must be
     * staffed on the delivery's PO's project.
     */
    private function assertAccessible(Delivery $delivery, User $user): void
    {
        $delivery->loadMissing('purchaseOrder');
        $this->assertProjectAccessible((int) $delivery->purchaseOrder->project_id, $user);
    }

    private function assertProjectAccessible(int $projectId, User $user): void
    {
        if (! $this->isProcurementDesk($user) && ! in_array($projectId, $this->accessibleProjectIds($user), true)) {
            abort(403, 'You do not have access to this project.');
        }
    }
}
