<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoginAttempt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'email_attempted',
        'was_successful',
        'failure_reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'was_successful' => 'boolean',
    ];
}
