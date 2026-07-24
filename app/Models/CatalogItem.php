<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'trade_category_id',
        'catalog_item_type_id',
        'default_unit_id',
        'project_id',
        'sku',
        'name',
        'description',
        'is_custom',
        'attributes',
        'created_by',
    ];

    protected $casts = [
        'is_custom' => 'boolean',
        'attributes' => 'array',
    ];

    public function tradeCategory(): BelongsTo
    {
        return $this->belongsTo(TradeCategory::class);
    }

    public function catalogItemType(): BelongsTo
    {
        return $this->belongsTo(CatalogItemType::class);
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vendorRates(): HasMany
    {
        return $this->hasMany(VendorRate::class);
    }

    /** Currently-open (non-superseded) rate per vendor, cheapest first. */
    public function currentVendorRates(): HasMany
    {
        return $this->vendorRates()->whereNull('effective_to')->orderBy('rate');
    }

    public function estimateLineItems(): HasMany
    {
        return $this->hasMany(EstimateLineItem::class);
    }
}
