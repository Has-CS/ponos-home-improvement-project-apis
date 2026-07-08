<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserCredential extends Model
{

    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'email',
        'password',
        'must_change_password',
        'password_changed_at',
        'email_verified_at',
    ];

    /**
     * Never expose these. Even though we shape output via Resources,
     * $hidden is a defense-in-depth backstop.
     */
    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'must_change_password' => 'boolean',
        'password_changed_at'  => 'datetime',
        'email_verified_at'    => 'datetime',
        'password'             => 'hashed', // auto-hash on set (Laravel 10+)
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
