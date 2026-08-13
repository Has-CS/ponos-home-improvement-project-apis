<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Terms & Conditions set for purchase orders.
 *
 * `project_id === null` is the generic default; a set project_id is that
 * project's override. Resolution between the two lives in
 * PurchaseOrderTermsService::resolveFor().
 *
 * Purchase orders SNAPSHOT this text rather than only pointing at the row, so
 * revising the company's standard terms never rewrites an order already issued.
 */
class PurchaseOrderTerm extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'title',
        'body',
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
     * The text as a purchase order snapshots it.
     *
     * Lives here so the create path and the re-resolve-at-issue path write an
     * identical, complete snapshot — the same reason
     * ProjectDeliveryAddress::toShipToSnapshot() exists.
     *
     * @return array<string,mixed>
     */
    public function toTermsSnapshot(): array
    {
        return [
            'terms_title' => $this->title,
            'terms_body' => $this->body,
        ];
    }

    /**
     * The body split into printable clauses.
     *
     * Shared with PurchaseOrder::termsClauses() so the live CRUD view and a
     * PO's frozen copy are broken into clauses by identical rules. Splitting on
     * one-or-more newlines means an author may separate clauses with single or
     * blank lines and get the same output either way.
     *
     * @return array<int,string>
     */
    public static function splitClauses(?string $body): array
    {
        if (blank($body)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R+/', $body) ?: []),
            fn (string $clause) => $clause !== '',
        ));
    }

    /** @return array<int,string> */
    public function clauses(): array
    {
        return self::splitClauses($this->body);
    }
}
