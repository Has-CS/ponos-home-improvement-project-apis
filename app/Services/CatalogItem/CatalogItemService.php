<?php

namespace App\Services\CatalogItem;

use App\Models\CatalogItem;
use App\Models\EstimateLineItem;
use App\Models\Project;
use App\Models\TradeCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CatalogItemService
{
    /** Type-ahead result cap when the caller does not specify one. */
    private const DEFAULT_SEARCH_LIMIT = 20;

    private const LIST_WITH = ['tradeCategory:id,name', 'catalogItemType:id,code,label', 'defaultUnit:id,code,label'];
    private const DETAIL_WITH = ['tradeCategory', 'catalogItemType', 'defaultUnit', 'project', 'creator', 'currentVendorRates.vendor', 'currentVendorRates.unit'];

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = CatalogItem::query()->with(self::LIST_WITH);

        if (! empty($filters['search'])) {
            $t = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$t}%")->orWhere('sku', 'ilike', "%{$t}%"));
        }

        foreach (['trade_category_id', 'catalog_item_type_id', 'project_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (array_key_exists('is_custom', $filters) && $filters['is_custom'] !== null) {
            $query->where('is_custom', (bool) $filters['is_custom']);
        }

        return $query
            ->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_dir'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Type-ahead search backing the material-request line picker: one search box
     * replacing the catalog and trade-category dropdowns.
     *
     * Returns a capped list with no total — a picker never needs one, and the
     * COUNT(*) over a wildcard match is the expensive half of the request.
     *
     * Shared by two callers with different audiences: the material-request
     * picker (price-free, reachable by field roles) and the purchase-order
     * picker. Only $vendorId differs — the query, scoping and ordering are
     * identical, so neither is duplicated.
     *
     * @param  array<string,mixed>  $filters
     * @param  ?int  $vendorId  When given, each item carries that vendor's
     *                          current open rate so a buyer can see the price
     *                          before adding the line. Left null for the
     *                          material-request picker, whose callers must not
     *                          see pricing at all.
     * @return array{items: \Illuminate\Support\Collection<int,CatalogItem>, limit: int, has_more: bool}
     */
    public function search(Project $project, array $filters, ?int $vendorId = null): array
    {
        $limit = (int) ($filters['limit'] ?? self::DEFAULT_SEARCH_LIMIT);

        // Escape LIKE metacharacters so searching "50%" looks for a literal
        // "50%" instead of turning the term into a wildcard of its own.
        $escaped = addcslashes(trim((string) $filters['q']), '%_\\');
        $contains = "%{$escaped}%";

        $query = CatalogItem::query()
            ->with(self::LIST_WITH)
            // The global catalog PLUS this project's own custom items. Another
            // project's custom items must never surface here. The existing
            // paginate() cannot express this — it does an exact project_id match.
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))
            ->where(function ($q) use ($contains) {
                $q->where('name', 'ilike', $contains)
                    ->orWhere('description', 'ilike', $contains)
                    // Match the trade category through the INDEXED
                    // catalog_items.trade_category_id rather than joining
                    // trade_categories and OR-ing on its text column: an OR
                    // spanning two tables' text columns defeats index use on
                    // both. trade_categories is ~22 rows, so this is free.
                    ->orWhereIn('trade_category_id', TradeCategory::query()
                        ->where('name', 'ilike', $contains)
                        ->select('id'));
            });

        foreach (['trade_category_id', 'catalog_item_type_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        // ONE extra query for the whole page, not one per row — a constrained
        // eager-load, not a per-item lookup, which is what keeps this usable as
        // the catalog grows. currentVendorRates is already scoped to
        // effective_to IS NULL, and vendor_rates_open_unique guarantees at most
        // one open rate per (vendor, item), so this yields 0 or 1 row each.
        if ($vendorId !== null) {
            $query->with(['currentVendorRates' => fn ($q) => $q->where('vendor_id', $vendorId)]);
        }

        // Fetch one more than asked: its presence is all "has_more" needs, and it
        // costs nothing next to a COUNT(*) over the same match.
        $rows = $query
            // Prefix matches first — typing "break" should surface
            // "Breaker 20A" above "Panel with breaker slots".
            ->orderByRaw('(name ILIKE ?) DESC', ["{$escaped}%"])
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        return [
            'items' => $rows->take($limit),
            'limit' => $limit,
            'has_more' => $rows->count() > $limit,
        ];
    }

    public function create(array $data, int $createdBy): CatalogItem
    {
        $item = CatalogItem::create([
            ...$data,
            'created_by' => $createdBy,
        ])->fresh();

        return $item->load(self::DETAIL_WITH);
    }

    public function findDetailed(CatalogItem $item): CatalogItem
    {
        return $item->load(self::DETAIL_WITH);
    }

    public function update(CatalogItem $item, array $data): CatalogItem
    {
        $item->fill($data)->save();
        return $item->load(self::DETAIL_WITH);
    }

    /**
     * Soft-deletes a catalog item, unless it's still referenced by vendor
     * pricing, an estimate line, or a material request / PO line.
     */
    public function delete(CatalogItem $item): void
    {
        if ($item->vendorRates()->exists()) {
            throw new \RuntimeException('Cannot delete: this item still has vendor rate history.');
        }

        if (EstimateLineItem::where('catalog_item_id', $item->id)->exists()) {
            throw new \RuntimeException('Cannot delete: this item is still referenced by estimate line items.');
        }

        if (DB::table('material_request_items')->where('catalog_item_id', $item->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Cannot delete: this item is still referenced by material request items.');
        }

        if (DB::table('purchase_order_items')->where('catalog_item_id', $item->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Cannot delete: this item is still referenced by purchase order items.');
        }

        $item->delete();
    }
}
