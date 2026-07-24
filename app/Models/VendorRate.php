<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorRate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'catalog_item_id',
        'unit_id',
        'rate',
        'currency',
        'effective_from',
        'effective_to',
        'source',
        'notes',
        'superseded_by_id',
        'entered_by',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(VendorRate::class, 'superseded_by_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
