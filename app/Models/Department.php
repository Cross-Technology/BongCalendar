<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A grouping of calendars inside a workspace — Sales, Ops, Errands. Calendars
 * may sit outside every department, in which case they read as "ungrouped".
 */
#[Fillable(['tenant_id', 'name', 'slug', 'description', 'color', 'icon', 'position'])]
class Department extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Department $department) {
            $department->slug ??= static::uniqueSlug($department->tenant_id, $department->name);
        });
    }

    /** Slugs are unique per workspace, not globally. */
    public static function uniqueSlug(int $tenantId, string $name): string
    {
        $base = Str::slug($name) ?: 'department';
        $slug = $base;
        $i = 1;

        while (static::withTrashed()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The people who work in this department.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Calendar, $this> */
    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    /** @return HasManyThrough<Event, Calendar, $this> */
    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, Calendar::class);
    }

    /** Departments in a workspace, in the order the sidebar shows them. */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->orderBy('position')
            ->orderBy('name');
    }
}
