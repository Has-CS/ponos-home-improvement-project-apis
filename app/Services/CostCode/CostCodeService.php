<?php

namespace App\Services\CostCode;

use App\Models\CostCode;
use App\Models\EstimateLineItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CostCodeService
{
    public function list(): Collection
    {
        return CostCode::query()->orderBy('code')->orderBy('id')->get();
    }

    public function create(array $data): CostCode
    {
        // fresh() so DB-side defaults (is_active) reflect correctly when the
        // caller omits them, rather than reading as null on the in-memory model.
        return CostCode::create($data)->fresh();
    }

    public function update(CostCode $costCode, array $data): CostCode
    {
        $costCode->fill($data)->save();
        return $costCode->fresh();
    }

    /**
     * Soft-deletes a cost code, unless it has children or is still referenced
     * by a live estimate/material-request/purchase-order line or change order.
     */
    public function delete(CostCode $costCode): void
    {
        if ($costCode->children()->exists()) {
            throw new \RuntimeException('Cannot delete: this cost code has sub-codes.');
        }

        if (EstimateLineItem::where('cost_code_id', $costCode->id)->exists()) {
            throw new \RuntimeException('Cannot delete: this cost code is still referenced by estimate line items.');
        }

        if (DB::table('material_request_items')->where('cost_code_id', $costCode->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Cannot delete: this cost code is still referenced by material request items.');
        }

        if (DB::table('purchase_order_items')->where('cost_code_id', $costCode->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Cannot delete: this cost code is still referenced by purchase order items.');
        }

        if (DB::table('change_orders')->where('cost_code_id', $costCode->id)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Cannot delete: this cost code is still referenced by change orders.');
        }

        $costCode->delete();
    }
}
