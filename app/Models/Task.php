<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A unit of work inside a workspace. Statuses, priorities and their labels
 * mirror the mobile app (lib/constants.ts) so both clients speak the same
 * vocabulary over the API.
 */
#[Fillable([
    'tenant_id', 'department_id', 'created_by', 'assignee_id', 'parent_task_id',
    'title', 'description', 'note', 'status', 'priority', 'start_date', 'due_date', 'tags', 'position',
    'series_id',
])]
class Task extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['todo', 'in_progress', 'done', 'blocked'];

    /** Columns the board shows — `blocked` stays out of the default flow. */
    public const BOARD_STATUSES = ['todo', 'in_progress', 'done'];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** Label and colour per status, matching STATUS_META in the mobile app. */
    public const STATUS_META = [
        'todo' => ['label' => 'Todo', 'short' => 'To do', 'color' => '#9CA3AF'],
        'in_progress' => ['label' => 'In Progress', 'short' => 'Doing', 'color' => '#3B82F6'],
        'done' => ['label' => 'Completed', 'short' => 'Done', 'color' => '#22A06B'],
        'blocked' => ['label' => 'Blocked', 'short' => 'Blocked', 'color' => '#DC4C64'],
    ];

    public const PRIORITY_META = [
        'low' => ['label' => 'Low', 'color' => '#94A3B8', 'weight' => 0],
        'medium' => ['label' => 'Medium', 'color' => '#3B82F6', 'weight' => 1],
        'high' => ['label' => 'High', 'color' => '#F59E0B', 'weight' => 2],
        'urgent' => ['label' => 'Urgent', 'color' => '#EF4444', 'weight' => 3],
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'due_date' => 'datetime',
            'completed_at' => 'datetime',
            'tags' => 'array',
        ];
    }

    /*
     * Dates arrive from clients as ISO strings, and an offset in one of them
     * ("2026-09-01T09:00:00+07:00") must mean the instant it names.
     *
     * Eloquent's datetime cast stores the wall clock and drops the offset, so
     * 9am in Phnom Penh would be written as 9am UTC — seven hours adrift, and
     * a task would land on the wrong day for anyone east or west of the
     * server. Normalising on the way in keeps every stored instant in UTC.
     */
    protected function startDate(): Attribute
    {
        return Attribute::set(fn ($value) => $this->toUtc($value));
    }

    protected function dueDate(): Attribute
    {
        return Attribute::set(fn ($value) => $this->toUtc($value));
    }

    protected function toUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A string with no offset is read as UTC, which is what the app's own
        // callers send after converting from the user's zone.
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
    }

    /* ------------------------------------------------------------ relations */

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

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /** @return HasMany<Task, $this> */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklist(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /* --------------------------------------------------------------- scopes */

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Top-level tasks only — subtasks are read through their parent. */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_task_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', 'done');
    }

    /**
     * Where the task sits on a calendar: the day it is scheduled for, or the
     * deadline when no start day was given. Matches the mobile app's rule, so
     * both clients place the same task on the same square.
     */
    public function calendarDate(): ?\Illuminate\Support\Carbon
    {
        return $this->start_date ?? $this->due_date;
    }

    /** Tasks scheduled for a given local calendar day, by whichever date applies. */
    public function scopeOnCalendarDay(Builder $query, \DateTimeInterface $day, string $timezone): Builder
    {
        $start = \Carbon\CarbonImmutable::instance($day)->setTimezone($timezone)->startOfDay();
        $window = [$start->utc(), $start->endOfDay()->utc()];

        return $query->where(fn (Builder $q) => $q
            ->whereBetween('start_date', $window)
            ->orWhere(fn (Builder $inner) => $inner
                ->whereNull('start_date')
                ->whereBetween('due_date', $window)));
    }

    /** Tasks due on a given local calendar day. */
    public function scopeDueOn(Builder $query, \DateTimeInterface $day, string $timezone): Builder
    {
        $start = \Carbon\CarbonImmutable::instance($day)->setTimezone($timezone)->startOfDay();

        return $query->whereBetween('due_date', [$start->utc(), $start->endOfDay()->utc()]);
    }

    /** Board order: by hand-set position, then heaviest priority, then oldest. */
    public function scopeBoardOrder(Builder $query): Builder
    {
        return $query->orderBy('position')
            // Portable priority ordering — FIELD() would tie this to MySQL.
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('created_at');
    }

    /* -------------------------------------------------------------- helpers */

    /** Other occurrences created alongside this one. */
    public function scopeInSeries(Builder $query, string $seriesId): Builder
    {
        return $query->where('series_id', $seriesId);
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null && ! $this->isDone() && $this->due_date->isPast();
    }

    /**
     * Moving to `done` stamps completed_at; moving anywhere else clears it,
     * which is exactly what the mobile store does.
     */
    public function setStatus(string $status): void
    {
        abort_unless(in_array($status, self::STATUSES, true), 422, 'Unknown status.');

        $this->forceFill([
            'status' => $status,
            'completed_at' => $status === 'done' ? now() : null,
        ])->save();
    }

    /** The completion checkbox: done ⇄ todo. */
    public function toggleDone(): void
    {
        $this->setStatus($this->isDone() ? 'todo' : 'done');
    }

    public function statusMeta(): array
    {
        return self::STATUS_META[$this->status] ?? self::STATUS_META['todo'];
    }

    public function priorityMeta(): array
    {
        return self::PRIORITY_META[$this->priority] ?? self::PRIORITY_META['medium'];
    }
}
