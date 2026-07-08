<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Gender extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'label', 'sort_order'];

    protected $casts = ['is_system' => 'boolean'];
}
