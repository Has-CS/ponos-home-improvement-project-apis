<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MilestoneStatus extends Model
{
    use SoftDeletes;

    public const NOT_STARTED = 'not_started';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const DELAYED = 'delayed';
    public const ON_HOLD = 'on_hold';
    public const CANCELLED = 'cancelled';

    protected $fillable = ['code', 'label', 'sort_order', 'is_terminal'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_terminal' => 'boolean',
        'is_system' => 'boolean',
    ];
}
