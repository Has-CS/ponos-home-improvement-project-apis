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
        'general_contractor_id',
        'cost_code_id',
        'change_order_type_id',
        'change_order_status_id',
        'gc_decision_id',
        'originator_id',
        'title',
        'description',
        'scope',
        'inclusions',
        'exclusions',
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
    public function generalContractor(): BelongsTo
    {
        return $this->belongsTo(ProjectGeneralContractor::class, 'general_contractor_id');
    }
    public function terms(): BelongsTo
    {
        return $this->belongsTo(ChangeOrderTerm::class, 'terms_id');
    }

    /**
     * The standing contractual blocks as printed, each split into paragraphs.
     *
     * Reads the SNAPSHOT exclusively, never the related terms row — that is what
     * keeps an issued change order's wording fixed after the company revises its
     * standard text. Same rule as PurchaseOrder::termsClauses().
     *
     * Splitting is delegated to PurchaseOrderTerm::splitClauses() so the frozen
     * copy and the live CRUD view break into paragraphs by identical rules.
     *
     * @return array<string,array<int,string>>
     */
    public function termsParagraphs(): array
    {
        return [
            'payment_terms' => PurchaseOrderTerm::splitClauses($this->payment_terms_body),
            'changes' => PurchaseOrderTerm::splitClauses($this->changes_body),
            'acceptance' => PurchaseOrderTerm::splitClauses($this->acceptance_body),
        ];
    }

    /** True once the GC details have been frozen onto this change order. */
    public function hasGcSnapshot(): bool
    {
        return $this->gc_name !== null;
    }

    /**
     * The GC block as printed:
     *
     *     Kellerman Construction Group
     *     Attn: Dana Whitfield          <- contact_name, rendered separately
     *     4400 Commerce Drive, Suite 210
     *     Naperville, IL 60563
     *     United States
     *
     * Prefers the SNAPSHOT, which is what makes an issued document immutable.
     * Falls back to the live relation so a draft change order — which has no
     * snapshot until its document is prepared — still renders its selected GC.
     *
     * Both paths format through ProjectGeneralContractor::formatLines(), so the
     * frozen copy and the live row can never disagree about layout.
     *
     * @return array{name:?string, contact_name:?string, lines:array<int,string>, phone:?string, email:?string}|null
     */
    public function gcBlock(): ?array
    {
        if ($this->hasGcSnapshot()) {
            return [
                'name' => $this->gc_name,
                'contact_name' => $this->gc_contact_name,
                'lines' => ProjectGeneralContractor::formatLines([
                    'street_1' => $this->gc_street_1,
                    'street_2' => $this->gc_street_2,
                    'city' => $this->gc_city,
                    'state' => $this->gc_state,
                    'postal_code' => $this->gc_postal_code,
                    'country' => $this->gc_country,
                ]),
                'phone' => $this->gc_phone,
                'email' => $this->gc_email,
            ];
        }

        $gc = $this->generalContractor;

        if (! $gc) {
            return null;
        }

        return [
            'name' => $gc->name,
            'contact_name' => $gc->contact_name,
            'lines' => $gc->addressLines(),
            'phone' => $gc->phone,
            'email' => $gc->email,
        ];
    }

    /**
     * Inclusions / exclusions as printable entries, one per line.
     *
     * Split by the same rule the purchase order's frozen terms use, so an author
     * may separate entries with single or blank lines and get the same output
     * either way.
     *
     * @return array<int,string>
     */
    public function inclusionList(): array
    {
        return PurchaseOrderTerm::splitClauses($this->inclusions);
    }

    /** @return array<int,string> */
    public function exclusionList(): array
    {
        return PurchaseOrderTerm::splitClauses($this->exclusions);
    }
    public function approvals(): HasMany
    {
        return $this->hasMany(ChangeOrderApproval::class)->orderBy('step_no');
    }

    /**
     * The Scope of Work table rows, in print order.
     *
     * Ordered here rather than at each call site so the API response and the PDF
     * can never disagree about sequence — sort_order IS the document's order.
     */
    public function scopeItems(): HasMany
    {
        return $this->hasMany(ChangeOrderScopeItem::class)->orderBy('sort_order');
    }
    public function signatures(): HasMany
    {
        return $this->hasMany(ChangeOrderSignature::class);
    }
}
