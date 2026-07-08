<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCode extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'parent_id' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CostCode::class, 'parent_id');
    }
}
