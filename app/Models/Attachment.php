<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'project_id',
        'attachment_type',
        'disk',
        'file_path',
        'file_name',
        'mime_type',
        'size_bytes',
        'metadata',
        'captured_at',
        'uploaded_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'captured_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
