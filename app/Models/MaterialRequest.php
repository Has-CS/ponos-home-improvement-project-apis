<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'request_no',
        'project_id',
        'requested_by',
        'material_request_status_id',
        'urgency_id',
        'needed_by_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'needed_by_date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestStatus::class, 'material_request_status_id');
    }

    public function urgency(): BelongsTo
    {
        return $this->belongsTo(Urgency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MaterialRequestApproval::class)->orderBy('step_no');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
