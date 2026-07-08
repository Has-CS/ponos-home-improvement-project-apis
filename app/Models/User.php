<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */

    protected string $guard_name = 'api';

    protected $fillable = [
        'first_name',
        'last_name',
        'gender_id',
        'date_of_birth',
        'picture_path',
        'user_status_id',
        'last_login_at',
        'created_by',
    ];
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $casts = [
        'date_of_birth' => 'date',
        'last_login_at' => 'datetime',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */

    /* ---------------- JWTSubject ---------------- */

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Custom claims embedded in the token. We do NOT put roles/permissions here
     * because they're project-scoped and can change; stale claims would be a
     * security risk. Resolve them fresh per request instead.
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /* ---------------- Relationships ---------------- */

    public function credential(): HasOne
    {
        return $this->hasOne(UserCredential::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(UserStatus::class, 'user_status_id');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------------- Helpers ---------------- */

    public function isActive(): bool
    {
        return $this->status?->code === UserStatus::ACTIVE;
    }
}
