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

    protected $casts = [
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
}
