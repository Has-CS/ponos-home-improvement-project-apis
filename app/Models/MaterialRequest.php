<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'request_no',
        'title',
        'project_id',
        'requested_by',
        'material_request_status_id',
        // Derived from the requested lines by
        // MaterialRequestService::recomputeProgress(), never set by a client.
        'ordering_status',
        'delivery_status',
        'urgency_id',
        'needed_by_date',
        'notes',
        'request_text',
        'structured_by',
        'structured_at',
        'created_by',
    ];

    protected $casts = [
        'needed_by_date' => 'date',
        'structured_at' => 'datetime',
    ];

    /**
     * True when the request carries prose that nobody has yet signed off as
     * fully mapped to line items (or, after approval, to PO lines).
     *
     * Deliberately NOT inferred from the line count: one line proves someone
     * started, not that the prose is covered. A mixed or half-structured request
     * would otherwise drop out of the buyer's queue with part of it never
     * ordered. The sign-off is MaterialRequestService::markStructured(), and any
     * edit that could un-cover the prose clears it again.
     *
     * The single source of truth for both API resources; the buyer-queue SQL in
     * PurchaseOrderService::pendingRequests() mirrors it. Reads the `status`
     * relation, which every caller already eager-loads.
     */
    public function needsStructuring(): bool
    {
        return filled($this->request_text)
            && $this->structured_at === null
            && $this->status?->code !== 'rejected';
    }

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

    /** Whoever first turned this request's prose into line items, if anyone did. */
    public function structuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'structured_by');
    }

    /**
     * Line-item changes made after the request left draft — who changed what,
     * and from what to what.
     *
     * The audit rows point at the REQUEST, with the line's id inside
     * `properties`, so the whole history is one indexed lookup rather than a
     * JSON scan across item rows.
     */
    public function activityLogs(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->orderBy('id');
    }

    /**
     * Photos the requester attached ("a picture of the steel nut I need").
     * Reuses the polymorphic attachments table — no dedicated table needed.
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->where('attachment_type', 'photo')
            ->orderBy('id');
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
