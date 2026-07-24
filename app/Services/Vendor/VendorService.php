<?php

namespace App\Services\Vendor;

use App\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class VendorService
{
    private const DETAIL_WITH = ['creator'];

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Vendor::query();

        if (! empty($filters['search'])) {
            $t = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$t}%")
                ->orWhere('email', 'ilike', "%{$t}%")
                ->orWhere('contact_name', 'ilike', "%{$t}%"));
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query
            ->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_dir'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(array $data, int $createdBy): Vendor
    {
        // fresh() so the DB-side is_active default (true) reflects correctly
        // when the caller omits it, rather than reading as null in-memory.
        $vendor = Vendor::create([
            ...$data,
            'created_by' => $createdBy,
        ])->fresh();

        return $vendor->load(self::DETAIL_WITH);
    }

    public function findDetailed(Vendor $vendor): Vendor
    {
        return $vendor->load(self::DETAIL_WITH)->loadCount('vendorRates');
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        $vendor->fill($data)->save();
        return $vendor->load(self::DETAIL_WITH)->loadCount('vendorRates');
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
}
