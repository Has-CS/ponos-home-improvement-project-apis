<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Milestone extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'code',
        'name',
        'description',
        'phase_id',
        'status_id',
        'sequence',
        'planned_date',
        'actual_date',
        'predecessor_id',
        'responsible_user_id',
        'responsible_party_label',
        'deliverable',
        'is_payment_milestone',
        'payment_amount',
        'created_by',
    ];

    protected $casts = [
        'planned_date' => 'date',
        'actual_date' => 'date',
        'is_payment_milestone' => 'boolean',
        'payment_amount' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(MilestonePhase::class, 'phase_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(MilestoneStatus::class, 'status_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Milestone::class, 'predecessor_id');
    }

    /** Inverse of predecessor(): milestones that chain off this one. */
    public function successors(): HasMany
    {
        return $this->hasMany(Milestone::class, 'predecessor_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * Completion is driven by status_id (not actual_date, which is purely
     * informational). Reopening is done by setting status_id to any
     * non-terminal status.
     */
    public function getIsCompletedAttribute(): bool
    {
        return $this->status?->code === MilestoneStatus::COMPLETED;
    }
}
