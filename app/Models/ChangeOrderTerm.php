<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The standing contractual text a change-order document is issued under:
 * Payment Terms, Changes, and Acceptance.
 *
 * `project_id === null` is the generic default; a set project_id is that
 * project's override. Resolution between the two lives in
 * ChangeOrderTermsService::resolveFor().
 *
 * Change orders SNAPSHOT this text rather than only pointing at the row, so
 * revising the company's standard wording never rewrites a document already in a
 * General Contractor's hands.
 */
class ChangeOrderTerm extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'payment_terms_body',
        'changes_body',
        'acceptance_body',
        'created_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** True for the company-wide default rather than a project override. */
    public function isDefault(): bool
    {
        return $this->project_id === null;
    }

    /**
     * The text as a change order snapshots it.
     *
     * Lives here so there is one definition of a complete snapshot, the same
     * reason PurchaseOrderTerm::toTermsSnapshot() and
     * ProjectGeneralContractor::toGcSnapshot() exist.
     *
     * @return array<string,mixed>
     */
    public function toTermsSnapshot(): array
    {
        return [
            'payment_terms_body' => $this->payment_terms_body,
            'changes_body' => $this->changes_body,
            'acceptance_body' => $this->acceptance_body,
        ];
    }

    /**
     * Every body split into printable paragraphs, keyed as the document uses
     * them. Delegates to PurchaseOrderTerm::splitClauses() so the live CRUD view
     * and a change order's frozen copy break text into paragraphs by identical
     * rules — an author may separate them with single or blank lines and get the
     * same output either way.
     *
     * @return array<string,array<int,string>>
     */
    public function paragraphs(): array
    {
        return [
            'payment_terms' => PurchaseOrderTerm::splitClauses($this->payment_terms_body),
            'changes' => PurchaseOrderTerm::splitClauses($this->changes_body),
            'acceptance' => PurchaseOrderTerm::splitClauses($this->acceptance_body),
        ];
    }
}
