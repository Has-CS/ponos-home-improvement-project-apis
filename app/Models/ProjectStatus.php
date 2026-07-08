<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectStatus extends Model
{
    use SoftDeletes;
    public const ACTIVE = 'active';
    public const ON_HOLD = 'on_hold';
    public const COMPLETED = 'completed';
    public const ARCHIVED = 'archived';
    protected $fillable = ['code', 'label', 'sort_order', 'is_terminal'];
    protected $casts = ['is_terminal' => 'boolean', 'is_system' => 'boolean'];
}
