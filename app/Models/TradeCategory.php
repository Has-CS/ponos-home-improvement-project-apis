<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TradeCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'sort_order',
    ];

    protected $casts = [
        'parent_id'  => 'integer',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TradeCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TradeCategory::class, 'parent_id');
    }

    /**
     * Vendors that supply this trade — the inverse of Vendor::tradeCategories().
     *
     * Used chiefly by TradeCategoryService::delete() to refuse removing a trade
     * that live vendors are still classified under. Soft-deleted vendors are
     * excluded automatically (Vendor uses SoftDeletes), so a defunct supplier
     * never blocks the delete.
     */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class)->withTimestamps();
    }
}
