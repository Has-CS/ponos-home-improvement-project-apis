<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChangeOrderSignature extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'change_order_id',
        'gc_representative_id',
        'signer_name',
        'signer_title',
        'signer_company',
        'signer_contact',
        'signature_attachment_id',
        'signed_at',
        'signed_lat',
        'signed_lng',
        'location_note',
        'captured_by',
        'device_info',
    ];

    /**
     * voided_at / void_reason are absent from $fillable on purpose: a void is a
     * service-level act with its own permission gate and audit row, not a field a
     * client may set while capturing a signature.
     */
    protected $casts = [
        'voided_at' => 'datetime',
        'signed_at' => 'datetime',
        'signed_lat' => 'decimal:6',
        'signed_lng' => 'decimal:6',
        'device_info' => 'array',
    ];

    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class);
    }

    public function signatureAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'signature_attachment_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /**
     * The stored representative this signature was captured for, if one was
     * picked. Traceability only — signer_* is the signed record. withTrashed so a
     * retired representative still resolves.
     */
    public function gcRepresentative(): BelongsTo
    {
        return $this->belongsTo(GcRepresentative::class)->withTrashed();
    }
}
