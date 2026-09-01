<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'owner_id', 'department_id', 'name', 'description', 'color', 'timezone', 'visibility', 'is_default'])]
class Calendar extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<CalendarShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(CalendarShare::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'calendar_shares')
            ->withPivot(['permission', 'invited_by'])
            ->withTimestamps();
    }

    /**
     * Calendars the user may read: their own, ones shared with them, and
     * tenant-visible ones. Always scoped to a single tenant.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->where(function (Builder $q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhere('visibility', 'tenant')
                    ->orWhereHas('shares', fn (Builder $s) => $s->where('user_id', $user->id));
            });
    }

    /** Restrict to one department, or to the ungrouped calendars when null. */
    public function scopeInDepartment(Builder $query, ?int $departmentId): Builder
    {
        return $departmentId
            ? $query->where('department_id', $departmentId)
            : $query->whereNull('department_id');
    }

    public function permissionFor(User $user): ?string
    {
        if ($this->owner_id === $user->id) {
            return 'manage';
        }

        $share = $this->shares()->where('user_id', $user->id)->first();

        if ($share) {
            return $share->permission;
        }

        return $this->visibility === 'tenant' && $user->belongsToTenant($this->tenant_id)
            ? 'view'
            : null;
    }

    public function isReadableBy(User $user): bool
    {
        return $this->permissionFor($user) !== null;
    }

    public function isWritableBy(User $user): bool
    {
        return in_array($this->permissionFor($user), ['edit', 'manage'], true);
    }

    public function isManageableBy(User $user): bool
    {
        return $this->permissionFor($user) === 'manage';
    }
}
