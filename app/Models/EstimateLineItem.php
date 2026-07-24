<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EstimateLineItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'estimate_version_id',
        'catalog_item_id',
        'cost_code_id',
        'unit_id',
        'vendor_rate_id',
        'quantity',
        'unit_price',
        'line_total',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function estimateVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateVersion::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function vendorRate(): BelongsTo
    {
        return $this->belongsTo(VendorRate::class);
    }
}
