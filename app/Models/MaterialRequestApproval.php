<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialRequestApproval extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'material_request_id',
        'step_no',
        'approver_id',
        'approver_role',
        'action',
        'comments',
        'from_status_id',
        'to_status_id',
        'acted_at',
    ];

    protected $casts = [
        'step_no' => 'integer',
        'acted_at' => 'datetime',
    ];

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestStatus::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestStatus::class, 'to_status_id');
    }
}
