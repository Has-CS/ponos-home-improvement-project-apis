<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A row on the central audit spine (`activity_logs`).
 *
 * Note this is NOT spatie/laravel-activitylog's model, despite that package
 * being installed. The table predates any use of it and is shaped differently —
 * it has no `causer_type` (the causer is always a user, by FK), and it adds
 * `project_id`, `ip_address` and `user_agent`. Adopting Spatie would mean
 * altering the schema to suit the package rather than the other way round.
 *
 * Append-only in practice: `deleted_at` exists for schema uniformity and is
 * never set. SoftDeletes is on purely so this model behaves like every other
 * one in the codebase.
 */
class Activity extends Model
{
    use SoftDeletes;

    protected $table = 'activity_logs';

    protected $fillable = [
        'log_name',
        'event',
        'description',
        'causer_id',
        'subject_type',
        'subject_id',
        'project_id',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
