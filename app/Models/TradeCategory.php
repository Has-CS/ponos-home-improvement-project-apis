<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
