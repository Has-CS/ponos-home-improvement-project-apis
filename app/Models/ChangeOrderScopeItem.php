<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row of a change order's Scope of Work table.
 *
 * Carries WHAT the work is and HOW MUCH of it — never what it costs. A change
 * order's money is the single change_orders.value decimal, with no breakdown by
 * design, so these rows have no price, no cost code and no catalog link.
 */
class ChangeOrderScopeItem extends Model
{
    use HasFactory, SoftDeletes;

    /** Row kinds, matching the change_order_scope_items_row_type_check constraint. */
    public const SECTION = 'section';

    public const GROUP = 'group';

    public const EMPHASIS = 'emphasis';

    public const LINE = 'line';

    public const SUBLINE = 'subline';

    public const SPACER = 'spacer';

    public const SUBTOTAL = 'subtotal';

    /** @var array<int,string> */
    public const ROW_TYPES = [
        self::SECTION, self::GROUP, self::EMPHASIS,
        self::LINE, self::SUBLINE, self::SPACER, self::SUBTOTAL,
    ];

    protected $fillable = [
        'change_order_id',
        'row_type',
        'label',
        'quantity',
        'unit_id',
        'indent',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'indent' => 'integer',
        'sort_order' => 'integer',
    ];

    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The unit as printed — "EA", "SF", "GAL".
     *
     * Upper-cased from units.code rather than reading units.label, which holds
     * the long form ("Each", "Square Foot") and would not fit the column.
     */
    public function printedUnit(): ?string
    {
        return $this->unit ? strtoupper($this->unit->code) : null;
    }

    /**
     * The quantity as printed: trailing zeros trimmed, so 0.700 shows as 0.7 and
     * 876.000 as 876. Same treatment the purchase-order and material-request
     * documents give their quantities.
     */
    public function printedQuantity(): ?string
    {
        if ($this->quantity === null) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $this->quantity, 3, '.', ','), '0'), '.');
    }

    /** Rows that print a quantity/unit pair at all. */
    public function hasQuantity(): bool
    {
        return $this->quantity !== null;
    }
}
