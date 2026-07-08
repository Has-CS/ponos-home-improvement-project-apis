<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IssueStatus extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'label',
        'sort_order',
        'is_terminal',
    ];

    protected $casts = [
        'is_terminal' => 'boolean',
        'sort_order'  => 'integer',
    ];
}
