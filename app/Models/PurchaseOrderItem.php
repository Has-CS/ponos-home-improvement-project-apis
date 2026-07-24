<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_order_id',
        'material_request_item_id',
        'cost_code_id',
        'catalog_item_id',
        'vendor_rate_id',
        'unit_id',
        'description',
        'quantity_ordered',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'quantity_ordered' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function materialRequestItem(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestItem::class);
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function vendorRate(): BelongsTo
    {
        return $this->belongsTo(VendorRate::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
