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
     * What to call this line when telling someone about it.
     *
     * The catalog item's name first: it is what the buyer picked from the list
     * and what the vendor will recognise. A free-text line has only the
     * requester's own words, and a line with neither falls back to its id, which
     * at least identifies the row.
     *
     * Error messages quote this rather than a row id — "line #45" tells the
     * person reading it nothing about which item is over-ordered.
     */
    public function displayName(): string
    {
        return $this->catalogItem?->name
            ?: ($this->description ?: "line #{$this->id}");
    }

    /**
     * A quantity as a person would write it: 50, not 50.000; 2.5, not 2.500.
     *
     * decimal(14,3) reaches PHP as a padded string, which is right for the
     * database and wrong for a sentence. Shared so every message about a
     * quantity reads the same way.
     */
    public static function formatQuantity(string|float|int|null $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.') ?: '0';
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
     * How much has been RECEIVED against each of these requested lines, summed
     * across every delivery on every purchase order raised from the request.
     *
     * Deliberately keyed to the requested line, not to the purchase-order line:
     * "has this request arrived" is a question about what was asked for. Judging
     * receipts against ordered quantities instead let a request report itself
     * delivered while a line nobody ever bought sat unfulfilled.
     *
     * @param  array<int,int>  $itemIds
     * @return array<int,string>  material_request_item_id => received quantity
     */
    public static function receivedQuantities(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('delivery_items as di')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'di.purchase_order_item_id')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereIn('poi.material_request_item_id', $itemIds)
            ->whereNull('di.deleted_at')
            ->whereNull('poi.deleted_at')
            ->whereNull('po.deleted_at')
            ->groupBy('poi.material_request_item_id')
            ->selectRaw('poi.material_request_item_id as mr_item_id, SUM(di.quantity_received) as received')
            ->pluck('received', 'mr_item_id')
            ->map(fn ($received) => (string) $received)
            ->all();
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
