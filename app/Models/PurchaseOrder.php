<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'po_number',
        'material_request_id',
        'project_id',
        'vendor_id',
        'purchase_order_status_id',
        'total_amount',
        'issued_by',
        'issued_at',
        'sent_at',
        'expected_delivery_date',
        'notes',
        'created_by',

        // Ship-to: the reference plus the printed snapshot. Written only by
        // PurchaseOrderService::resolveShipTo(), never straight from request
        // input — the snapshot must always be consistent with the FK.
        'ship_to_address_id',
        'ship_to_label',
        'ship_to_attention',
        'ship_to_street_1',
        'ship_to_street_2',
        'ship_to_city',
        'ship_to_state',
        'ship_to_postal_code',
        'ship_to_country',
        'ship_to_contact_phone',
        'ship_to_delivery_notes',
        'ship_to_project_name',
        'ship_to_project_code',

        // Terms & Conditions: reference plus printed snapshot. Written only by
        // PurchaseOrderService::resolveTerms(), at create and again at issue.
        'terms_id',
        'terms_title',
        'terms_body',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'sent_at' => 'datetime',
        'expected_delivery_date' => 'date',
    ];

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderStatus::class, 'purchase_order_status_id');
    }

    /**
     * The address row this PO was shipped to. Traceability only — everything
     * printed comes from the ship_to_* snapshot, so this relation may point at
     * a since-edited or soft-deleted address without affecting the document.
     */
    public function shipToAddress(): BelongsTo
    {
        return $this->belongsTo(ProjectDeliveryAddress::class, 'ship_to_address_id');
    }

    /**
     * The terms row this PO was issued under. Traceability only — everything
     * printed comes from the terms_* snapshot, so this may point at a since-
     * edited or soft-deleted row without affecting the document.
     */
    public function terms(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderTerm::class, 'terms_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * True once this PO has a destination — the condition issue() requires.
     *
     * Keyed on the CITY, not the street: a contact-led destination ("Tyler
     * Blake / PWC Companies – PWC Headquarters / Cornwall-on-Hudson, NY") has
     * no street at all, and keying on street_1 would class it as having no
     * ship-to — blanking the printed block and blocking the PO from issuing.
     * City is required on every address, so it is the reliable marker.
     */
    public function hasShipTo(): bool
    {
        return $this->ship_to_city !== null;
    }

    /**
     * The Terms & Conditions clauses as printed, one array entry per clause.
     *
     * Reads the SNAPSHOT, never the related terms row, which is what keeps an
     * issued order's terms fixed after the company revises its standard set.
     * Splitting is delegated so the frozen copy and the live CRUD view break
     * into clauses by identical rules.
     *
     * @return array<int,string>
     */
    public function termsClauses(): array
    {
        return PurchaseOrderTerm::splitClauses($this->terms_body);
    }

    /**
     * The ship-to block as printed, one array entry per line:
     *
     *     Harrington Residence — Full Renovation
     *     88 Ridgeview Court
     *     Wheaton, IL 60187
     *     United States
     *
     * A POSTAL BLOCK ONLY. It deliberately no longer appends "Project {code}"
     * and "Deliver by {date}": the document already prints both, from these very
     * columns, in the reference strip immediately beneath the panel ("Project"
     * and "Expected delivery"), so they appeared twice within a few millimetres
     * of each other. Neither is part of an address in any case.
     *
     * Nothing is lost to API consumers — expected_delivery_date is a top-level
     * field on PurchaseOrderDetailResource, and project_code / project_name sit
     * beside formatted_lines inside `ship_to`, all as discrete values rather
     * than prose a client would have to parse back out of a string.
     *
     * Formatted here, once, so the API response and the PO PDF cannot drift
     * apart — neither re-derives the layout. Empty parts are dropped rather than
     * left as blank lines, so an address with no state or postal code still
     * prints cleanly.
     *
     * Reads exclusively from the snapshot columns, never from the related
     * address or project, which is what makes the block immutable once issued.
     *
     * @return array<int,string>
     */
    public function shipToLines(): array
    {
        if (! $this->hasShipTo()) {
            return [];
        }

        // "Wheaton, IL 60187" — comma only when a city is followed by something.
        $locality = trim(implode(' ', array_filter([$this->ship_to_state, $this->ship_to_postal_code])));
        $cityLine = trim(implode(', ', array_filter([$this->ship_to_city, $locality])));

        return array_values(array_filter([
            // Contact first and unprefixed — a named person is the recipient,
            // and the company/site line qualifies them:
            //
            //     Tyler Blake
            //     PWC Companies – PWC Headquarters
            //     Cornwall-on-Hudson, NY
            //
            // A site address simply carries no contact, so it still leads with
            // its label and is unaffected by the ordering.
            $this->ship_to_attention,
            $this->ship_to_label,
            $this->ship_to_street_1,
            $this->ship_to_street_2,
            $cityLine !== '' ? $cityLine : null,
            $this->ship_to_country,
        ], fn ($line) => filled($line)));
    }
}
