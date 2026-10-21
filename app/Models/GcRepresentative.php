<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who signs for a General Contractor on site.
 *
 * Their company is the GC's name — never stored here. Capture copies these values
 * into change_order_signatures.signer_* (see toSignerSnapshot()), so the signed
 * record is independent of later edits or a soft delete.
 */
class GcRepresentative extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'general_contractor_id',
        'name',
        'title',
        'phone',
        'email',
        'created_by',
    ];

    public function generalContractor(): BelongsTo
    {
        // withTrashed: a representative of a retired GC still names its company.
        return $this->belongsTo(ProjectGeneralContractor::class, 'general_contractor_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** One contact line, as the signature's single signer_contact column holds it. */
    public function contactLine(): ?string
    {
        return $this->phone ?: ($this->email ?: null);
    }

    /**
     * The signer_* values a signature captured for this representative carries.
     *
     * @return array<string,string|null>
     */
    public function toSignerSnapshot(): array
    {
        return [
            'signer_name' => $this->name,
            'signer_title' => $this->title,
            'signer_company' => $this->generalContractor?->name,
            'signer_contact' => $this->contactLine(),
        ];
    }
}
