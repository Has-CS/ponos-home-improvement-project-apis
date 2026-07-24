<?php

namespace App\Services\VendorRate;

use App\Models\CatalogItem;
use App\Models\VendorRate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VendorRateService
{
    private const WITH = ['vendor', 'catalogItem', 'unit', 'enteredBy'];

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

        $currentOnly = ! array_key_exists('current_only', $filters) || $filters['current_only'] === null
            ? true
            : (bool) $filters['current_only'];

        if ($currentOnly) {
            $query->whereNull('effective_to');
        }

        return $query
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15));
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
