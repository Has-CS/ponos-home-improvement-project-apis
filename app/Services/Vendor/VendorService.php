<?php

namespace App\Services\Vendor;

use App\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class VendorService
{
    private const DETAIL_WITH = ['creator', 'tradeCategories'];

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        // Trades eager-loaded for the whole page in ONE extra query, so the list
        // can show each vendor's trades without a query per row.
        $query = Vendor::query()->with('tradeCategories');

        if (! empty($filters['search'])) {
            $t = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$t}%")
                ->orWhere('email', 'ilike', "%{$t}%")
                ->orWhere('contact_name', 'ilike', "%{$t}%"));
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        // Vendors supplying ANY of the given trades.
        //
        // whereHas, deliberately NOT a join: it compiles to an EXISTS subquery,
        // so a vendor serving two of the requested trades is returned ONCE. A
        // join would return it once per matching trade — duplicated rows and an
        // inflated pagination total — and would also make the unqualified
        // created_at/name ordering below ambiguous against the pivot.
        if (! empty($filters['trade_category_ids'])) {
            $ids = array_map('intval', $filters['trade_category_ids']);

            $query->whereHas('tradeCategories', fn ($q) => $q->whereIn('trade_categories.id', $ids));
        }

        return $query
            ->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_dir'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(array $data, int $createdBy): Vendor
    {
        $tradeIds = $this->pullTradeIds($data);

        // One transaction, so a failed sync can never leave a vendor saved
        // without the trades it was created with.
        return DB::transaction(function () use ($data, $createdBy, $tradeIds) {
            // fresh() so the DB-side is_active default (true) reflects correctly
            // when the caller omits it, rather than reading as null in-memory.
            $vendor = Vendor::create([
                ...$data,
                'created_by' => $createdBy,
            ])->fresh();

            if ($tradeIds !== null) {
                $vendor->tradeCategories()->sync($tradeIds);
            }

            return $vendor->load(self::DETAIL_WITH);
        });
    }

    public function findDetailed(Vendor $vendor): Vendor
    {
        return $vendor->load(self::DETAIL_WITH)->loadCount('vendorRates');
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        $tradeIds = $this->pullTradeIds($data);

        return DB::transaction(function () use ($vendor, $data, $tradeIds) {
            $vendor->fill($data)->save();

            // Absent -> untouched; present -> replaces the whole set; [] -> clears.
            // The same contract UserService applies to role_ids.
            if ($tradeIds !== null) {
                $vendor->tradeCategories()->sync($tradeIds);
            }

            return $vendor->load(self::DETAIL_WITH)->loadCount('vendorRates');
        });
    }

    public function updateStatus(Vendor $vendor, bool $isActive): Vendor
    {
        $vendor->update(['is_active' => $isActive]);
        return $vendor->load(self::DETAIL_WITH)->loadCount('vendorRates');
    }

    /**
     * Soft-deletes a vendor, unless it still has rate history —
     * restrictOnDelete() only fires on a hard DELETE, so a soft-delete here
     * would otherwise leave vendor_rates pointing at a hidden vendor.
     */
    public function delete(Vendor $vendor): void
    {
        if ($vendor->vendorRates()->exists()) {
            throw new \RuntimeException('Cannot delete a vendor that has rate history.');
        }

        $vendor->delete();
    }

    /**
     * Take the trade ids out of the payload, returning them separately.
     *
     * They are a relation, not a column, so they are synced on their own. Left
     * in the payload they would not error — Eloquent's strict mode is off here,
     * so a non-fillable key is silently discarded — which is exactly the
     * problem: the vendor would save with no trades and nothing would say so.
     *
     * Returns null when the key was not sent at all ("leave the trades alone"),
     * as distinct from an empty array ("clear them").
     *
     * @param  array<string,mixed>  $data  modified in place: the key is removed
     * @return array<int,int>|null
     */
    private function pullTradeIds(array &$data): ?array
    {
        if (! array_key_exists('trade_category_ids', $data)) {
            return null;
        }

        $ids = array_map('intval', (array) ($data['trade_category_ids'] ?? []));
        unset($data['trade_category_ids']);

        return $ids;
    }
}
