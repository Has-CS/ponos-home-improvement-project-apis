<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class MaterialRequestItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'material_request_id',
        'cost_code_id',
        'catalog_item_id',
        'trade_category_id',
        'unit_id',
        'description',
        'quantity',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sort_order' => 'integer',
    ];

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function tradeCategory(): BelongsTo
    {
        return $this->belongsTo(TradeCategory::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Stamp `ordered_quantity` and `remaining_quantity` onto a set of requested
     * lines, in ONE query for the whole set.
     *
     * What a buyer needs in front of them while cutting a purchase order: how
     * much of this line is already on order elsewhere, and how much is left. The
     * over-order rule refuses anything past `remaining_quantity`, so without it
     * a picker can only discover the limit by hitting a 422.
     *
     * Set as attributes rather than computed in the API resource, because a
     * resource would issue one query per line. Only the buyer-facing reads call
     * this; the material-request module's own payloads are unchanged.
     *
     * @param  \Illuminate\Support\Collection<int,self>  $items
     */
    public static function attachOrderedQuantities($items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $ordered = self::orderedQuantities($items->pluck('id')->all());

        foreach ($items as $item) {
            $already = $ordered[$item->id] ?? '0';
            $remaining = bccomp((string) $item->quantity, $already, 3) > 0
                ? bcsub((string) $item->quantity, $already, 3)
                : '0';

            $item->setAttribute('ordered_quantity', $already);
            $item->setAttribute('remaining_quantity', $remaining);
        }
    }

    /**
     * How much has already been ORDERED against each of these requested lines,
     * summed across every purchase order raised from the request.
     *
     * One material request legitimately produces many POs (one per vendor), so
     * "how much is left to order" is only answerable by aggregating all of them
     * — a single PO's own quantity says nothing. The single source of truth for
     * the over-order rule, used by both StorePurchaseOrderRequest (per-line 422s)
     * and PurchaseOrderService (the invariant).
     *
     * Cancelled orders and soft-deleted rows do not count: cancelling a PO or
     * deleting a draft releases its quantity back to the request. Drafts DO
     * count — otherwise two drafts could each pass on their own and then both be
     * issued, and issue() performs no line-level check.
     *
     * Returns decimal strings, not floats: quantity is decimal(14,3) and the
     * comparison is exact (bccomp), never a float epsilon.
     *
     * @param  array<int,int>  $itemIds
     * @param  int|null  $excludePurchaseOrderItemId  A PO line being edited. Its
     *                   own current quantity must not count against itself, or
     *                   changing a line from 15 to 16 could never pass.
     * @return array<int,string>  material_request_item_id => ordered quantity
     */
    public static function orderedQuantities(array $itemIds, ?int $excludePurchaseOrderItemId = null): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('purchase_order_items as poi')
            ->when($excludePurchaseOrderItemId !== null, fn ($q) => $q->where('poi.id', '!=', $excludePurchaseOrderItemId))
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->join('purchase_order_statuses as pos', 'pos.id', '=', 'po.purchase_order_status_id')
            ->whereIn('poi.material_request_item_id', $itemIds)
            ->whereNull('poi.deleted_at')
            ->whereNull('po.deleted_at')
            ->where('pos.code', '!=', 'cancelled')
            ->groupBy('poi.material_request_item_id')
            ->selectRaw('poi.material_request_item_id as mr_item_id, SUM(poi.quantity_ordered) as ordered')
            ->pluck('ordered', 'mr_item_id')
            ->map(fn ($ordered) => (string) $ordered)
            ->all();
    }
}
