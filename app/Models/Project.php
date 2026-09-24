<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'short_code',
        'name',
        'client_id',
        'project_type_id',
        'project_status_id',
        'site_address',
        'budget',
        'start_date',
        'end_date',
        'created_by',
    ];



    protected $casts = [
        'budget'     => 'decimal:2',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    /** Longest short code the column accepts, and the cap on derivation. */
    public const SHORT_CODE_MAX = 6;

    /**
     * The project's initials, as they lead a purchase-order number
     * ("Surbana Jhons" → "SJ", so SJ-2026-09-00001).
     *
     * Pure and side-effect free, so the backfill migration, ProjectService and
     * the tests all derive the same thing. Uniqueness is NOT handled here — see
     * uniqueShortCode().
     *
     * One word has no initials to take, so its first three letters stand in
     * ("Metro" → "MET"). Digits and punctuation are ignored: "Sky 47 Data
     * Center" reads as SDC, not S4DC.
     */
    public static function deriveShortCode(string $name): string
    {
        preg_match_all('/[A-Za-z]+/', $name, $matches);
        $words = $matches[0];

        if ($words === []) {
            return 'PRJ';
        }

        $code = count($words) === 1
            ? substr($words[0], 0, 3)
            : implode('', array_map(static fn ($word) => $word[0], $words));

        return strtoupper(substr($code, 0, self::SHORT_CODE_MAX));
    }

    /**
     * deriveShortCode() plus a numeric suffix until it is free, so two projects
     * whose names share initials can both exist ("SJ", then "SJ2").
     *
     * The suffix is trimmed into the length limit rather than overflowing it.
     */
    public static function uniqueShortCode(string $name, ?int $ignoreId = null): string
    {
        $base = self::deriveShortCode($name);
        $candidate = $base;
        $suffix = 1;

        while (self::shortCodeTaken($candidate, $ignoreId)) {
            $suffix++;
            $candidate = substr($base, 0, self::SHORT_CODE_MAX - strlen((string) $suffix)).$suffix;
        }

        return $candidate;
    }

    private static function shortCodeTaken(string $shortCode, ?int $ignoreId): bool
    {
        return self::query()
            ->where('short_code', $shortCode)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /* ---- lookups ---- */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function type(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'project_type_id');
    }
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'project_status_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---- staffing ---- */
    public function projectUsers(): HasMany
    {
        return $this->hasMany(ProjectUser::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user')
            ->withPivot(['role_id', 'is_active', 'assigned_by', 'assigned_at'])
            ->wherePivotNull('deleted_at')
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    /* ---- timeline ---- */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sequence');
    }

    /* ---- logistics ---- */

    /**
     * Ship-to destinations for this project's purchase orders. Primary first,
     * then alphabetical — the order the PO dropdown wants.
     */
    public function deliveryAddresses(): HasMany
    {
        return $this->hasMany(ProjectDeliveryAddress::class)
            ->orderByDesc('is_primary')
            ->orderBy('label');
    }

    /**
     * The address a new purchase order defaults to. At most one can exist —
     * guaranteed by the project_delivery_addresses_one_primary partial index.
     */
    public function primaryDeliveryAddress(): HasOne
    {
        return $this->hasOne(ProjectDeliveryAddress::class)->where('is_primary', true);
    }

    /**
     * The General Contractor(s) this project runs under. Distinct from the
     * client: a client commissions the project, a GC is who Ponos contracts
     * under. Change orders are addressed to one of these.
     */
    public function generalContractors(): HasMany
    {
        return $this->hasMany(ProjectGeneralContractor::class)
            ->orderByDesc('is_primary')
            ->orderBy('name');
    }

    /**
     * The GC a new change order defaults to. At most one can exist — guaranteed
     * by the project_general_contractors_one_primary partial index.
     */
    public function primaryGeneralContractor(): HasOne
    {
        return $this->hasOne(ProjectGeneralContractor::class)->where('is_primary', true);
    }

    /* ---- cross-module (read-level exposure) ---- */
    // TODO: re-add materialRequests, dailyLogs, issues once those modules exist
    public function changeOrders(): HasMany
    {
        return $this->hasMany(ChangeOrder::class);
    }
}
