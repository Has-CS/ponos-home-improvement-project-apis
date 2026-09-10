<?php

namespace App\Services\CatalogItem;

use App\Models\CatalogItem;
use App\Models\EstimateLineItem;
use App\Models\Project;
use App\Models\TradeCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CatalogItemService
{
    /** Type-ahead result cap when the caller does not specify one. */
    private const DEFAULT_SEARCH_LIMIT = 20;

    private const LIST_WITH = ['tradeCategory:id,name', 'catalogItemType:id,code,label', 'defaultUnit:id,code,label'];
    private const DETAIL_WITH = ['tradeCategory', 'catalogItemType', 'defaultUnit', 'project', 'creator', 'currentVendorRates.vendor', 'currentVendorRates.unit'];

    /**
     * Escape LIKE metacharacters so searching "50%" looks for a literal "50%"
     * rather than turning the term into a wildcard of its own.
     */
    private function escapeLike(string $term): string
    {
        return addcslashes(trim($term), '%_\\');
    }

    /**
     * The catalog match — the ONE definition, applied identically by the admin
     * list and by every type-ahead picker.
     *
     * Shared rather than written per call site because the two had silently
     * drifted: the list matched name+sku, the picker matched
     * name+description+trade category, so a buyer holding a supplier SKU got
     * nothing from the picker while the list found it instantly. One method
     * means they cannot diverge again.
     *
     * GROUPED in a closure, and that is load-bearing rather than stylistic.
     * Both callers chain further constraints AFTER this — the list adds its
     * filters, and the picker adds its PROJECT SCOPE — and AND binds tighter
     * than OR. Ungrouped, a name match would escape those constraints entirely,
     * which in the picker's case would surface another project's custom items.
     *
     * Trade category is matched through the INDEXED
     * catalog_items.trade_category_id rather than by joining trade_categories
     * and OR-ing on its text column: an OR spanning two tables' text columns
     * defeats index use on both. trade_categories is ~22 rows, so this is free.
     *
     * name, sku and description each have a partial GIN trigram index, which is
     * what keeps these leading-wildcard matches off a sequential scan.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<CatalogItem>  $query
     */
    private function applySearch($query, string $term): void
    {
        $contains = '%'.$this->escapeLike($term).'%';

        $query->where(function ($q) use ($contains) {
            $q->where('name', 'ilike', $contains)
                ->orWhere('sku', 'ilike', $contains)
                ->orWhere('description', 'ilike', $contains)
                ->orWhereIn('trade_category_id', TradeCategory::query()
                    ->where('name', 'ilike', $contains)
                    ->select('id'));
        });
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = CatalogItem::query()->with(self::LIST_WITH);

        if (! empty($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        foreach (['trade_category_id', 'catalog_item_type_id', 'project_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (array_key_exists('is_custom', $filters) && $filters['is_custom'] !== null) {
            $query->where('is_custom', (bool) $filters['is_custom']);
        }

        // Absent => BOTH active and retired are listed. The admin list is where
        // a retired item has to remain findable, or nobody could reactivate one.
        // Same opt-in shape as the vendor list's filter.
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
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
     * Shared by three callers with different audiences: the material-request
     * picker (price-free, reachable by field roles), the purchase-order
     * picker, and the RFQ picker. Only $vendorId differs — the query, scoping
     * and ordering are identical, so none of them is duplicated.
     *
     * @param  ?Project  $project  Null when the caller has no project to scope
     *                             to (e.g. a pre-project RFQ) — the search then
     *                             covers the global catalog only, with no
     *                             project's custom items included.
     * @param  array<string,mixed>  $filters
     * @param  ?int  $vendorId  When given, each item carries that vendor's
     *                          current open rate so a buyer can see the price
     *                          before adding the line. Left null for the
     *                          material-request picker, whose callers must not
     *                          see pricing at all.
     * @return array{items: \Illuminate\Support\Collection<int,CatalogItem>, limit: int, has_more: bool}
     */
    public function search(?Project $project, array $filters, ?int $vendorId = null): array
    {
        $limit = (int) ($filters['limit'] ?? self::DEFAULT_SEARCH_LIMIT);

        // Only for the prefix-first ordering below — the match itself escapes
        // its own term inside applySearch().
        $escaped = $this->escapeLike((string) $filters['q']);

        $query = CatalogItem::query()
            ->with(self::LIST_WITH)
            // The global catalog PLUS this project's own custom items. Another
            // project's custom items must never surface here. The existing
            // paginate() cannot express this — it does an exact project_id match.
            // No project at all (RFQ, pre-project): global catalog only.
            ->where(fn ($q) => $project
                ? $q->whereNull('project_id')->orWhere('project_id', $project->id)
                : $q->whereNull('project_id'))
            // Retired items are never offered for selection. Chained at the TOP
            // level, deliberately not inside applySearch()'s closure: that closure
            // groups the OR-ed match, and folding this into it would make
            // "is_active" just another OR branch, re-admitting every retired item
            // whose name happened to match. Historical references are untouched —
            // only NEW selection is affected.
            ->where('is_active', true);

        $this->applySearch($query, (string) $filters['q']);

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
        // Swap the uploaded file for the path it was stored at. Must happen
        // BEFORE the spread below — $data is passed straight into create(), and
        // an UploadedFile object has no business reaching Eloquent.
        $data = $this->resolveImage($data);

        $item = CatalogItem::create([
            ...$data,
            'created_by' => $createdBy,
        ])->fresh();

        return $item->load(self::DETAIL_WITH);
    }

    /**
     * Turn an `image` upload into an `image_path`, storing the file.
     *
     * Files go to the `public` disk, exactly as user avatars do
     * (UserService::create()) — the URL is derived in the API resources, and
     * only the relative path is ever written to the database.
     *
     * $replacing is the path currently on the record, if any: on an update the
     * superseded file is deleted rather than left orphaned on disk, which is
     * the same housekeeping UserService::update() performs for an avatar.
     *
     * A request with no image is returned untouched, so this is safe to call on
     * every path. The `image` key is always removed — it is not a column.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function resolveImage(array $data, ?string $replacing = null): array
    {
        if (! empty($data['image']) && $data['image'] instanceof UploadedFile) {
            if ($replacing) {
                Storage::disk('public')->delete($replacing);
            }

            $data['image_path'] = $data['image']->store('catalog-items/images', 'public');
        }

        unset($data['image']);

        return $data;
    }

    /**
     * Retire or restore an item.
     *
     * The counterpart to delete(): an item still referenced by a rate, an
     * estimate, a material request or a purchase order cannot be removed —
     * correctly — so this is how it leaves the pickers instead. Reversible,
     * and it touches nothing but the flag. Mirrors
     * VendorService::updateStatus().
     */
    public function updateStatus(CatalogItem $item, bool $isActive): CatalogItem
    {
        $item->update(['is_active' => $isActive]);

        return $item->load(self::DETAIL_WITH);
    }

    public function findDetailed(CatalogItem $item): CatalogItem
    {
        return $item->load(self::DETAIL_WITH);
    }

    public function update(CatalogItem $item, array $data): CatalogItem
    {
        // Passing the current path means a replaced image's file is removed from
        // disk instead of being orphaned there for good.
        $data = $this->resolveImage($data, $item->image_path);

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
            throw new \RuntimeException('Cannot delete: this item still has vendor rate history. Deactivate it instead.');
        }

        if (EstimateLineItem::where('catalog_item_id', $item->id)->exists()) {
            throw new \RuntimeException('Cannot delete: this item is still referenced by estimate line items. Deactivate it instead.');
        }

        if (DB::table('material_request_items')->where('catalog_item_id', $item->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Cannot delete: this item is still referenced by material request items. Deactivate it instead.');
        }

        if (DB::table('purchase_order_items')->where('catalog_item_id', $item->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Cannot delete: this item is still referenced by purchase order items. Deactivate it instead.');
        }

        $item->delete();
    }
}
