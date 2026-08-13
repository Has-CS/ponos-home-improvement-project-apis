<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
