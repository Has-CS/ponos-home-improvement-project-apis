<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialRequestItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'material_request_id',
        'cost_code_id',
        'catalog_item_id',
        'trade_category_id',
        'unit_id',
        'description',
        'quantity',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sort_order' => 'integer',
    ];

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function tradeCategory(): BelongsTo
    {
        return $this->belongsTo(TradeCategory::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
