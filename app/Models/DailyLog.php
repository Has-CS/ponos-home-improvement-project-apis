<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyLog extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'logged_by',
        'log_date',
        'work_description',
        'weather',
        'crew_count',
        'has_issue',
        'created_by',
    ];

    protected $casts = [
        'log_date' => 'date',
        'has_issue' => 'boolean',
        'crew_count' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'daily_log_id');
    }

    /**
     * Site photos the foreman attached when filing this log.
     *
     * Reuses the polymorphic attachments table — no dedicated table needed, and
     * the same shape MaterialRequest::photos() uses, so the two modules store
     * and serialize evidence identically. Only the file's PATH lives in the
     * database; the bytes are on the private disk (AttachmentService::put()).
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->where('attachment_type', 'photo')
            ->orderBy('id');
    }
}
