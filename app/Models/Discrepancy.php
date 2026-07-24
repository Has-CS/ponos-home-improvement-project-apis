<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discrepancy extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_id',
        'delivery_item_id',
        'discrepancy_type_id',
        'description',
        'reported_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function deliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class);
    }

    public function discrepancyType(): BelongsTo
    {
        return $this->belongsTo(DiscrepancyType::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
