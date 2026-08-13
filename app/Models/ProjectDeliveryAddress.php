<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A ship-to destination belonging to one project. A project may have several
 * (a client running two sites under one project) and at most one primary.
 *
 * Purchase orders SNAPSHOT these values rather than only pointing at the row —
 * see the ship_to_* columns on purchase_orders — so editing or deleting an
 * address here never alters an order that has already gone out.
 */
class ProjectDeliveryAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'label',
        'attention',
        'street_1',
        'street_2',
        'city',
        'state',
        'postal_code',
        'country',
        'contact_phone',
        'delivery_notes',
        'is_primary',
        'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /**
     * Keep the fingerprint in step with the address on every write.
     *
     * A hook rather than a service call on purpose: the factory and tests
     * create rows directly, bypassing ProjectDeliveryAddressService entirely,
     * and a stale fingerprint there would silently defeat the unique index that
     * is the whole point of the column.
     *
     * Note address_fingerprint is deliberately absent from $fillable — it is
     * derived, and must never be settable from request input.
     */
    protected static function booted(): void
    {
        static::saving(function (self $address) {
            $address->address_fingerprint = self::fingerprintFor($address);
        });
    }

    /**
     * The single definition of "the same address", used by the duplicate check,
     * the migration backfill and the saving hook alike.
     *
     * Identity is WHERE it goes plus WHO receives it. The label is excluded: it
     * is a display name, so re-entering one street under a new label is the
     * same destination, not a new one. The contact IS included, so two named
     * recipients at one building remain distinct destinations — which the
     * contact-led address shape depends on.
     *
     * The one exception: an address with neither a street nor a contact has
     * nothing but its label to tell it apart, so the label joins the identity
     * there. Without this, "North Site, Wheaton" and "South Site, Wheaton"
     * would both reduce to an empty fingerprint and collide.
     *
     * Normalization is case-folding and whitespace collapsing only. It will not
     * equate "88 Ridgeview Ct." with "88 Ridgeview Court" — that needs postal
     * validation, and approximate matching here would reject good data.
     *
     * @param  self|array<string,mixed>  $source
     */
    public static function fingerprintFor(self|array $source): string
    {
        $get = fn (string $key) => is_array($source) ? ($source[$key] ?? null) : $source->{$key};

        $parts = [
            $get('attention'),
            $get('street_1'),
            $get('street_2'),
            $get('city'),
            $get('state'),
            $get('postal_code'),
            $get('country'),
        ];

        if (blank($get('street_1')) && blank($get('attention'))) {
            $parts[] = $get('label');
        }

        return implode('|', array_map(
            fn ($value) => preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $value))) ?? '',
            $parts,
        ));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The address as a purchase order snapshots it.
     *
     * Lives here rather than in the service so both the create and the update
     * path take the same columns, and adding a field to the address can't leave
     * one path silently writing a partial snapshot.
     *
     * @return array<string,mixed>
     */
    public function toShipToSnapshot(): array
    {
        return [
            'ship_to_label' => $this->label,
            'ship_to_attention' => $this->attention,
            'ship_to_street_1' => $this->street_1,
            'ship_to_street_2' => $this->street_2,
            'ship_to_city' => $this->city,
            'ship_to_state' => $this->state,
            'ship_to_postal_code' => $this->postal_code,
            'ship_to_country' => $this->country,
            'ship_to_contact_phone' => $this->contact_phone,
            'ship_to_delivery_notes' => $this->delivery_notes,
        ];
    }
}
