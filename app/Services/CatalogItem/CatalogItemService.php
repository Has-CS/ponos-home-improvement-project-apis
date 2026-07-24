<?php

namespace App\Services\CatalogItem;

use App\Models\CatalogItem;
use App\Models\EstimateLineItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CatalogItemService
{
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
