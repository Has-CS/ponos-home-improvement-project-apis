<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectType extends Model
{
    use SoftDeletes;
    protected $fillable = ['code', 'label', 'sort_order'];
    protected $casts = ['sort_order' => 'integer', 'is_system' => 'boolean'];
}
