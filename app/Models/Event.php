<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'calendar_id', 'created_by', 'title', 'description', 'location', 'color',
    'starts_at', 'ends_at', 'timezone', 'all_day', 'status', 'recurrence_rule', 'recurrence_until',
])]
class Event extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['confirmed', 'tentative', 'cancelled'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'recurrence_until' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<EventInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(EventInvitation::class);
    }

    /** @return HasMany<EventReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(EventReminder::class);
    }

    /**
     * Events that overlap the window at all, not just those starting inside it —
     * a multi-day event beginning before `$from` still belongs on the grid.
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    public function scopeForCalendars(Builder $query, array $calendarIds): Builder
    {
        return $query->whereIn('calendar_id', $calendarIds);
    }

    public function displayColor(): string
    {
        return $this->color ?: ($this->calendar?->color ?? '#2563eb');
    }
}
