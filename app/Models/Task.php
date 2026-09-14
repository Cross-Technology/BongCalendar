<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
    use HasFactory, RecordsActivity, SoftDeletes;

    protected static function booted(): void
    {
        /*
         * A task handed to someone who works in a department belongs to that
         * department. Filed here rather than in each caller because tasks are
         * created from four places — the API, the board, a template and a
         * recurrence — and three of them would have been easy to miss.
         *
         * It only ever fills a blank: a deliberate filing outranks the
         * assignee's default, and reassigning never moves a task that already
         * has a home.
         */
        static::saving(function (Task $task) {
            if ($task->department_id !== null || $task->assignee_id === null || $task->tenant_id === null) {
                return;
            }

            $task->department_id = User::find($task->assignee_id)?->primaryDepartmentId($task->tenant_id);
        });

        /*
         * The pivots are what every read counts, so a plain write of the
         * primary column — the API, a template, a subtask inheriting its
         * parent — has to land there too. A sync sets both sides itself and
         * says so, which is why these stand aside for it.
         */
        static::created(function (Task $task) {
            $task->mirrorPrimaryOwners(force: true);
        });

        static::updated(function (Task $task) {
            $task->mirrorPrimaryOwners(force: false);
        });
    }

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

    /**
     * The department the task is filed under first. Kept in step with
     * {@see self::departments()}, which is the full set.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Every department the task is filed under. Work that two teams share is
     * one task on both their boards, not a copy each.
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_task')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The first person on the task — who it is listed against where there is
     * only room for one name.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Everyone the task is on. All of them own it equally; `assignee` is just
     * the first of them.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_user')->withTimestamps();
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
    public function calendarDate(): ?Carbon
    {
        return $this->start_date ?? $this->due_date;
    }

    /**
     * Tasks landing between two local calendar days, by whichever date
     * applies — the day the work is scheduled for, or the deadline when it was
     * never scheduled. Both ends are inclusive whole days.
     */
    public function scopeInCalendarWindow(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to, string $timezone): Builder
    {
        $window = [
            CarbonImmutable::instance($from)->setTimezone($timezone)->startOfDay()->utc(),
            CarbonImmutable::instance($to)->setTimezone($timezone)->endOfDay()->utc(),
        ];

        return $query->where(fn (Builder $q) => $q
            ->whereBetween('start_date', $window)
            ->orWhere(fn (Builder $inner) => $inner
                ->whereNull('start_date')
                ->whereBetween('due_date', $window)));
    }

    /** Tasks scheduled for a given local calendar day, by whichever date applies. */
    public function scopeOnCalendarDay(Builder $query, \DateTimeInterface $day, string $timezone): Builder
    {
        return $query->inCalendarWindow($day, $day, $timezone);
    }

    /** Tasks with no day of their own — neither scheduled nor due. */
    public function scopeUnscheduled(Builder $query): Builder
    {
        return $query->whereNull('start_date')->whereNull('due_date');
    }

    /** Tasks due on a given local calendar day. */
    public function scopeDueOn(Builder $query, \DateTimeInterface $day, string $timezone): Builder
    {
        $start = CarbonImmutable::instance($day)->setTimezone($timezone)->startOfDay();

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

    /** Tasks filed under any of these departments. */
    public function scopeInDepartments(Builder $query, array $departmentIds): Builder
    {
        return $query->whereHas('departments', fn (Builder $q) => $q->whereIn('departments.id', $departmentIds));
    }

    /** Tasks any of these people are on. */
    public function scopeAssignedTo(Builder $query, array $userIds): Builder
    {
        return $query->whereHas('assignees', fn (Builder $q) => $q->whereIn('users.id', $userIds));
    }

    /** Tasks nobody has picked up. */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereDoesntHave('assignees');
    }

    /* --------------------------------------------------------------- owners */

    /**
     * Set while a sync is writing both sides, so the mirror hooks stand aside
     * and do not flatten the set back to its first member.
     */
    protected bool $syncingOwners = false;

    /**
     * Files the task under a set of departments. The first is the primary —
     * the one `department_id` carries — so a single-name read still answers
     * with the same department it always did.
     *
     * @param  array<int, int|string|null>  $ids
     */
    public function syncDepartments(array $ids): void
    {
        $ids = self::normaliseIds($ids);

        $this->syncingOwners = true;

        try {
            $this->departments()->sync($ids);
            $this->forceFill(['department_id' => $ids[0] ?? null])->save();

            // Handing an unfiled task to someone files it under their own
            // department (see the saving hook). The set has to agree.
            if ($this->department_id !== null && ! in_array($this->department_id, $ids, true)) {
                $this->departments()->syncWithoutDetaching([$this->department_id]);
            }
        } finally {
            $this->syncingOwners = false;
        }

        $this->unsetRelation('departments')->unsetRelation('department');
    }

    /**
     * Puts a set of people on the task. The first is the primary, for the same
     * reason departments have one.
     *
     * @param  array<int, int|string|null>  $ids
     */
    public function syncAssignees(array $ids): void
    {
        $ids = self::normaliseIds($ids);

        $this->syncingOwners = true;

        try {
            $this->assignees()->sync($ids);
            $this->forceFill(['assignee_id' => $ids[0] ?? null])->save();

            if ($this->department_id !== null && ! $this->departments()->whereKey($this->department_id)->exists()) {
                $this->departments()->syncWithoutDetaching([$this->department_id]);
            }
        } finally {
            $this->syncingOwners = false;
        }

        $this->unsetRelation('assignees')->unsetRelation('assignee');
    }

    /**
     * Whole positive ids, in the order given, without repeats. Blank picks —
     * the "None" option of a multi-select posts an empty string — drop out.
     *
     * @param  array<int, int|string|null>  $ids
     * @return array<int, int>
     */
    protected static function normaliseIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Copies a straight write of `department_id` / `assignee_id` into the
     * pivots, so callers that know nothing about sets still file the task
     * somewhere the board can find it.
     *
     * @param  bool  $force  True on create, when neither column counts as changed.
     */
    protected function mirrorPrimaryOwners(bool $force): void
    {
        if ($this->syncingOwners) {
            return;
        }

        if ($force || $this->wasChanged('department_id')) {
            $this->departments()->sync(array_filter([$this->department_id]));
        }

        if ($force || $this->wasChanged('assignee_id')) {
            $this->assignees()->sync(array_filter([$this->assignee_id]));
        }
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

    /* ---------------------------------------------------------------- audit */

    /**
     * Board housekeeping, not edits anyone asked for: a card dragged between
     * columns moves `position`, and `completed_at` only ever restates what the
     * status entry beside it already says.
     */
    protected function activityIgnored(): array
    {
        return ['position', 'completed_at', 'series_id', 'tenant_id'];
    }

    protected function activityLabels(): array
    {
        return [
            'created_by' => 'Creator',
            'assignee_id' => 'Assignee',
            'department_id' => 'Department',
            'parent_task_id' => 'Parent task',
            'start_date' => 'Start date',
            'due_date' => 'Due date',
        ];
    }

    /**
     * Ids and codes read back as the names and labels they stand for — "Doing",
     * not "in_progress"; a person, not a number.
     */
    public function activityValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'status' => self::STATUS_META[$value]['label'] ?? (string) $value,
            'priority' => self::PRIORITY_META[$value]['label'] ?? (string) $value,
            'assignee_id', 'created_by' => User::find($value)?->name ?? 'Unknown user',
            'department_id' => Department::withTrashed()->find($value)?->name ?? 'Unknown department',
            'parent_task_id' => self::withTrashed()->find($value)?->title ?? 'Unknown task',
            default => $this->defaultActivityValue($value),
        };
    }
}
