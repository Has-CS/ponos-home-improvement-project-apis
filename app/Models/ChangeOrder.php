<?php
// app/Models/ChangeOrder.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChangeOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'change_order_no',
        'project_id',
        'cost_code_id',
        'change_order_type_id',
        'change_order_status_id',
        'gc_decision_id',
        'originator_id',
        'title',
        'description',
        'scope',
        'location',
        'urgency_id',
        'value',
        'document_attachment_id',
        'counter_signed_by',
        'counter_signed_at',
        'gc_decision_by',
        'gc_decision_at',
        'gc_decision_notes',
        'became_active_at',
        'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'counter_signed_at' => 'datetime',
        'gc_decision_at' => 'datetime',
        'became_active_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
    public function status(): BelongsTo
    {
        return $this->belongsTo(ChangeOrderStatus::class, 'change_order_status_id');
    }
    public function type(): BelongsTo
    {
        return $this->belongsTo(ChangeOrderType::class, 'change_order_type_id');
    }
    public function gcDecision(): BelongsTo
    {
        return $this->belongsTo(GcDecision::class, 'gc_decision_id');
    }
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }
    public function urgency(): BelongsTo
    {
        return $this->belongsTo(Urgency::class);
    }
    public function originator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'originator_id');
    }
    public function counterSignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counter_signed_by');
    }
    public function gcDecisionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gc_decision_by');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function documentAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'document_attachment_id');
    }
    public function approvals(): HasMany
    {
        return $this->hasMany(ChangeOrderApproval::class)->orderBy('step_no');
    }
    public function signatures(): HasMany
    {
        return $this->hasMany(ChangeOrderSignature::class);
    }
}
