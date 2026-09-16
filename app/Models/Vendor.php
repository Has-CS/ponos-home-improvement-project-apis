<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'contact_name',
        'email',
        'phone',
        'address',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vendorRates(): HasMany
    {
        return $this->hasMany(VendorRate::class);
    }

    /**
     * The trades this vendor supplies — "who does Doors", "who does Electrical".
     *
     * Reuses trade_categories, the taxonomy catalog items are already classified
     * by, rather than a vendor-specific list that could drift from it. Many-to-
     * many because a supplier rarely serves a single trade. The pivot is
     * trade_category_vendor — Laravel's alphabetical default, so no table
     * argument is needed.
     *
     * Ordered by the taxonomy's own sort_order so every response lists a
     * vendor's trades in the same sequence as the trade-category lookup. Safe to
     * declare here: sync() and whereHas() build their pivot and existence
     * queries fresh, so the ordering never leaks into them.
     */
    public function tradeCategories(): BelongsToMany
    {
        return $this->belongsToMany(TradeCategory::class)
            ->withTimestamps()
            ->orderBy('trade_categories.sort_order')
            ->orderBy('trade_categories.id');
    }
}
