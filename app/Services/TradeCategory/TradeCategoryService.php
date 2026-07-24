<?php

namespace App\Services\TradeCategory;

use App\Models\CatalogItem;
use App\Models\TradeCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TradeCategoryService
{
    public function list(): Collection
    {
        return TradeCategory::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function create(array $data): TradeCategory
    {
        // fresh() so the DB-side sort_order default (0) reflects correctly
        // when the caller omits it, rather than reading as null in-memory.
        return TradeCategory::create($data)->fresh();
    }

    public function update(TradeCategory $tradeCategory, array $data): TradeCategory
    {
        $tradeCategory->fill($data)->save();
        return $tradeCategory->fresh();
    }

    /**
     * Soft-deletes a trade category, unless it has children or is still
     * referenced by a live catalog item / material request line.
     */
    public function delete(TradeCategory $tradeCategory): void
    {
        if ($tradeCategory->children()->exists()) {
            throw new \RuntimeException('Cannot delete: this trade category has sub-categories.');
        }

        if (CatalogItem::where('trade_category_id', $tradeCategory->id)->exists()) {
            throw new \RuntimeException('Cannot delete: this trade category is still referenced by catalog items.');
        }

        if (DB::table('material_request_items')->where('trade_category_id', $tradeCategory->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Cannot delete: this trade category is still referenced by material request items.');
        }

        $tradeCategory->delete();
    }
}
