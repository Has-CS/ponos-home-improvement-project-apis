<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A General Contractor belonging to one project. A project may have several and
 * at most one primary.
 *
 * Distinct from Client: a client is the owner commissioning the project, a GC is
 * who Ponos contracts under as a subcontractor. A project can have both.
 *
 * Change orders SNAPSHOT these values rather than only pointing at the row — see
 * the gc_* columns on change_orders — so editing or retiring a GC here never
 * alters a change-order document that has already been generated.
 */
class ProjectGeneralContractor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'name',
        'contact_name',
        'street_1',
        'street_2',
        'city',
        'state',
        'postal_code',
        'country',
        'phone',
        'email',
        'notes',
        'is_primary',
        'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The values a change order freezes onto itself when its document is
     * generated. Keyed to the gc_* columns on change_orders.
     *
     * `notes` is deliberately NOT snapshotted: it is internal working context
     * about the GC (who to chase, billing quirks), not part of the address block
     * printed on a document.
     *
     * @return array<string,mixed>
     */
    public function toGcSnapshot(): array
    {
        return [
            'gc_name' => $this->name,
            'gc_contact_name' => $this->contact_name,
            'gc_street_1' => $this->street_1,
            'gc_street_2' => $this->street_2,
            'gc_city' => $this->city,
            'gc_state' => $this->state,
            'gc_postal_code' => $this->postal_code,
            'gc_country' => $this->country,
            'gc_phone' => $this->phone,
            'gc_email' => $this->email,
        ];
    }

    /**
     * This GC's address as printed, one array entry per line. Used for the live
     * fallback when a change order has no snapshot yet; the frozen equivalent is
     * ChangeOrder::gcLines(), and the two format identically on purpose.
     *
     * @return array<int,string>
     */
    public function addressLines(): array
    {
        return self::formatLines([
            'street_1' => $this->street_1,
            'street_2' => $this->street_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
        ]);
    }

    /**
     * The shared line-formatting rule, so the live row and a change order's
     * frozen copy can never drift apart. Empty parts are dropped rather than
     * left as blank lines.
     *
     * @param  array<string,mixed>  $parts
     * @return array<int,string>
     */
    public static function formatLines(array $parts): array
    {
        // "Wheaton, IL 60187" — comma only when a city is followed by something.
        $locality = trim(implode(' ', array_filter([$parts['state'] ?? null, $parts['postal_code'] ?? null])));
        $cityLine = trim(implode(', ', array_filter([$parts['city'] ?? null, $locality])));

        return array_values(array_filter([
            $parts['street_1'] ?? null,
            $parts['street_2'] ?? null,
            $cityLine !== '' ? $cityLine : null,
            $parts['country'] ?? null,
        ], fn ($line) => filled($line)));
    }
}
