<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'client_id',
        'project_type_id',
        'project_status_id',
        'site_address',
        'budget',
        'start_date',
        'end_date',
        'created_by',
    ];



    protected $casts = [
        'budget'     => 'decimal:2',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    /* ---- lookups ---- */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function type(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'project_type_id');
    }
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'project_status_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---- staffing ---- */
    public function projectUsers(): HasMany
    {
        return $this->hasMany(ProjectUser::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user')
            ->withPivot(['role_id', 'is_active', 'assigned_by', 'assigned_at'])
            ->wherePivotNull('deleted_at')
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    /* ---- timeline ---- */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sequence');
    }

    /* ---- cross-module (read-level exposure) ---- */
    // TODO: re-add materialRequests, dailyLogs, issues once those modules exist
    public function changeOrders(): HasMany
    {
        return $this->hasMany(ChangeOrder::class);
    }
}
