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
    protected $casts = ['value' => 'decimal:2'];
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
    public function originator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'originator_id');
    }
    public function approvals(): HasMany
    {
        return $this->hasMany(ChangeOrderApproval::class)->orderBy('step_no');
    }
}
