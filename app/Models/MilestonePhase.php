<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MilestonePhase extends Model
{
    use SoftDeletes;

    public const PRE_CONSTRUCTION = 'pre_construction';
    public const DESIGN = 'design';
    public const PROCUREMENT = 'procurement';
    public const CONSTRUCTION = 'construction';
    public const CLOSEOUT = 'closeout';

    protected $fillable = ['code', 'label', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_system' => 'boolean',
    ];
}
