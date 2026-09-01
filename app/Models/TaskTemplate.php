<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved shape of a task: the daily routine written once and stamped onto
 * whichever day you are looking at.
 *
 * Applying one always produces ordinary tasks — nothing stays linked back, so
 * editing yesterday's copy never touches the template or tomorrow's.
 */
#[Fillable([
    'tenant_id', 'created_by', 'department_id', 'name', 'title',
    'description', 'note', 'priority', 'tags', 'checklist',
])]
class TaskTemplate extends Model
{
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'priority' => 'medium',
        'use_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'checklist' => 'array',
            'last_used_at' => 'datetime',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Most recently used first, so a daily routine stays at the top. */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderBy('name');
    }

    /** Called after the template has been stamped onto a day. */
    public function markUsed(): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'use_count' => $this->use_count + 1,
        ])->save();
    }
}
