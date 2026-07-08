<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserStatus extends Model
{
    use SoftDeletes;

    /** Stable status codes — the only source of truth for "may log in" (R-1). */
    public const ACTIVE    = 'active';
    public const INVITED   = 'invited';
    public const SUSPENDED = 'suspended';
    public const DISABLED  = 'disabled';

    protected $fillable = ['code', 'label', 'sort_order'];

    protected $casts = ['is_system' => 'boolean'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'user_status_id');
    }
}
