<?php
// app/Models/ChangeOrderApproval.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChangeOrderApproval extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'change_order_id',
        'step_no',
        'actor_id',
        'actor_role',
        'action',
        'comments',
        'from_status_id',
        'to_status_id',
        'acted_at',
    ];

    protected $casts = [
        'acted_at' => 'datetime',
        'step_no' => 'integer',
    ];
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(ChangeOrderStatus::class, 'from_status_id');
    }
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(ChangeOrderStatus::class, 'to_status_id');
    }
}
