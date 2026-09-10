<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogItemType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'label',
    ];

    /**
     * is_system is deliberately NOT fillable — it is set by migration/seeder
     * only, never by request input, exactly as on UserStatus. The lookup
     * FormRequests also mark it `prohibited`, so this is defence in depth.
     */
    protected $casts = ['is_system' => 'boolean'];
}
