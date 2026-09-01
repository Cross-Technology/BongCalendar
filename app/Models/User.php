<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'password', 'timezone', 'avatar_url', 'current_tenant_id', 'digest_enabled', 'digest_morning_hour', 'digest_evening_hour'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'digest_enabled' => 'boolean',
        ];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Claims embedded in the token so the API can resolve the active tenant
     * without an extra query on every request.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'tenant_id' => $this->current_tenant_id,
            'email' => $this->email,
        ];
    }

    /** @return BelongsToMany<Tenant, $this> */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /** @return BelongsTo<Tenant, $this> */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /** @return HasMany<Calendar, $this> */
    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class, 'owner_id');
    }

    /** Calendars owned by someone else but shared with this user. */
    public function sharedCalendars(): BelongsToMany
    {
        return $this->belongsToMany(Calendar::class, 'calendar_shares')
            ->withPivot(['permission', 'invited_by'])
            ->withTimestamps();
    }

    /** @return HasMany<PushToken, $this> */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /** @return HasMany<EventInvitation, $this> */
    public function eventInvitations(): HasMany
    {
        return $this->hasMany(EventInvitation::class);
    }

    public function belongsToTenant(int $tenantId): bool
    {
        return $this->tenants()->whereKey($tenantId)->exists();
    }

    /** Role within the given tenant, or null when not a member. */
    public function roleIn(int $tenantId): ?string
    {
        $tenant = $this->tenants()->whereKey($tenantId)->first();

        return $tenant?->pivot->role;
    }

    public function isTenantAdmin(int $tenantId): bool
    {
        return in_array($this->roleIn($tenantId), ['owner', 'admin'], true);
    }
}
