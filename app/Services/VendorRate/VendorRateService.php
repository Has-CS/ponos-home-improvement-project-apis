<?php

namespace App\Services\VendorRate;

use App\Models\CatalogItem;
use App\Models\Vendor;
use App\Models\VendorRate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VendorRateService
{
    private const WITH = ['vendor', 'catalogItem', 'unit', 'enteredBy'];

    /**
     * Columns `sort_by` may name. Own-table only — sorting by vendor or item
     * name would need a join, which complicates the eager-load and the
     * pagination count for little gain.
     */
    private const SORTABLE = ['effective_from', 'rate', 'created_at'];

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = VendorRate::query()->with(self::WITH);

        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if (! empty($filters['catalog_item_id'])) {
            $query->where('catalog_item_id', $filters['catalog_item_id']);
        }

        if (! empty($filters['trade_category_id'])) {
            // Indexed subquery on catalog_items rather than a join, so the
            // rate query keeps using its own indexes. "Every plumbing rate."
            $query->whereIn('catalog_item_id', CatalogItem::query()
                ->where('trade_category_id', $filters['trade_category_id'])
                ->select('id'));
        }

        if (! empty($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        if (isset($filters['rate_min'])) {
            $query->where('rate', '>=', $filters['rate_min']);
        }

        if (isset($filters['rate_max'])) {
            $query->where('rate', '<=', $filters['rate_max']);
        }

        // "Which prices moved in this window" — filters on when the rate was
        // SET. Same naming and idiom as DailyLogService::paginate().
        if (! empty($filters['date_from'])) {
            $query->whereDate('effective_from', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('effective_from', '<=', $filters['date_to']);
        }

        // "What was I paying on this date" — the estimating question. Returns
        // the rate each vendor actually had in effect then, which is one row
        // per vendor: the same guarantee vendor_rates_open_unique gives the
        // current view, projected back in time.
        //
        // The inner closure is LOAD-BEARING. Ungrouped, AND binds tighter than
        // OR, so the orWhereDate would escape vendor_id / catalog_item_id /
        // search entirely and return other vendors' pricing. Same trap
        // documented in CatalogItemService::applySearch().
        if (! empty($filters['as_of'])) {
            $asOf = $filters['as_of'];

            $query->whereDate('effective_from', '<=', $asOf)
                ->where(fn ($q) => $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOf));
        }

        if ($this->wantsCurrentOnly($filters)) {
            $query->whereNull('effective_to');
        }

        // Whitelisted in IndexVendorRateRequest, re-checked here so the service
        // is safe to call directly (tests, future callers) without smuggling a
        // raw column name into the ORDER BY.
        $sortBy = in_array($filters['sort_by'] ?? null, self::SORTABLE, true)
            ? $filters['sort_by']
            : 'effective_from';

        $sortDir = ($filters['sort_dir'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($sortBy, $sortDir)
            // Kept in EVERY case, not just the default: rows sharing an
            // effective_from or a rate have no inherent order, and without a
            // unique tiebreaker paginating them can repeat or skip records
            // between pages.
            ->orderBy('id', 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Whether to restrict to open rates.
     *
     * Still defaults to TRUE, so a bare request behaves exactly as it always
     * has. But the default is SUPPRESSED when the caller asks a historical
     * question, because leaving it on would quietly give the wrong answer:
     *
     *   ?date_from=…&date_to=…  would return only rates set in the window that
     *   happen to still be open today — omitting every one since superseded,
     *   which is precisely the set the question asks for.
     *
     *   ?as_of=…                would return only rates still open now, rather
     *   than the ones in effect on that date — the opposite of the intent.
     *
     * An EXPLICIT current_only still wins either way, so no combination becomes
     * unreachable. (as_of + current_only is rejected outright by the
     * FormRequest: "current" is simply as_of = today.)
     *
     * @param  array<string,mixed>  $filters
     */
    private function wantsCurrentOnly(array $filters): bool
    {
        if (array_key_exists('current_only', $filters) && $filters['current_only'] !== null) {
            return (bool) $filters['current_only'];
        }

        return ! (filled($filters['as_of'] ?? null)
            || filled($filters['date_from'] ?? null)
            || filled($filters['date_to'] ?? null));
    }

    /**
     * Free-text match across the item and the vendor — the two things anyone
     * actually remembers about a price.
     *
     * Resolved through indexed FK subqueries rather than joins, the technique
     * CatalogItemService::search() uses: catalog_items has GIN trigram indexes
     * on name and sku, and vendors.name gets one in the migration alongside
     * this change, so each half stays off a sequential scan.
     *
     * GROUPED, for the same load-bearing reason as the as_of clause above.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<VendorRate>  $query
     */
    private function applySearch($query, string $term): void
    {
        $contains = '%'.$this->escapeLike($term).'%';

        $query->where(fn ($q) => $q
            ->whereIn('catalog_item_id', CatalogItem::query()
                ->where(fn ($c) => $c->where('name', 'ilike', $contains)
                    ->orWhere('sku', 'ilike', $contains))
                ->select('id'))
            ->orWhereIn('vendor_id', Vendor::query()
                ->where('name', 'ilike', $contains)
                ->select('id')));
    }

    /**
     * Escape LIKE metacharacters so a SKU containing `_` or `%` is matched
     * literally. Deliberately mirrors the identically-named helper in
     * CatalogItemService — a stable one-liner, not worth coupling two service
     * namespaces together to share.
     */
    private function escapeLike(string $term): string
    {
        return addcslashes(trim($term), '%_\\');
    }

    public function findDetailed(VendorRate $vendorRate): VendorRate
    {
        return $vendorRate->load(self::WITH);
    }

    /**
     * Adds a new effective-dated rate for a (vendor, catalog_item) pair.
     * History is never overwritten: if an open rate already exists, it is
     * closed the day before the new rate's effective_from and linked via
     * superseded_by_id, all in one transaction.
     */
    public function addRate(array $data, int $enteredBy): VendorRate
    {
        return DB::transaction(function () use ($data, $enteredBy) {
            $unitId = $data['unit_id'] ?? CatalogItem::findOrFail($data['catalog_item_id'])->default_unit_id;
            $effectiveFrom = $data['effective_from'] ?? now()->toDateString();

            $current = VendorRate::where('vendor_id', $data['vendor_id'])
                ->where('catalog_item_id', $data['catalog_item_id'])
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            // Close the prior open rate BEFORE inserting the new one — the
            // partial unique index only allows one row with effective_to IS
            // NULL per (vendor_id, catalog_item_id), so both rows can never
            // be open at the same time, even momentarily inside this transaction.
            if ($current) {
                $closingDate = Carbon::parse($effectiveFrom)->subDay()->toDateString();
                $current->update(['effective_to' => $closingDate]);
            }

            $newRate = VendorRate::create([
                'vendor_id' => $data['vendor_id'],
                'catalog_item_id' => $data['catalog_item_id'],
                'unit_id' => $unitId,
                'rate' => $data['rate'],
                'currency' => $data['currency'] ?? 'USD',
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'source' => $data['source'] ?? null,
                'notes' => $data['notes'] ?? null,
                'entered_by' => $enteredBy,
            ])->fresh();

            if ($current) {
                $current->update(['superseded_by_id' => $newRate->id]);
            }

            return $newRate->load(self::WITH);
        });
    }
}
