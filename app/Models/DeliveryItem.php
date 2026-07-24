<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_id',
        'purchase_order_item_id',
        'quantity_received',
        'quantity_accepted',
        'notes',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'quantity_accepted' => 'decimal:3',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
