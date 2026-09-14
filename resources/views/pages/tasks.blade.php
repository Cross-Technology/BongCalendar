<?php

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskRecurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Tasks — BongCalendar')]
class extends Component
{
    use InteractsWithTenant;

    /** Filters, kept in the URL so a board view can be shared. */
    #[Url]
    public ?int $department = null;

    #[Url]
    public ?int $assignee = null;

    #[Url]
    public string $search = '';

    /** A named window: today, week, overdue or none. '' is every date. */
    #[Url]
    public string $due = '';

    /** One particular day, as Y-m-d. Outranks $due when both are set. */
    #[Url]
    public string $onDate = '';

    public bool $showModal = false;

    public ?int $editingId = null;

    // Task form
    public string $form_title = '';

    public string $form_description = '';

    public string $form_note = '';

    public string $form_status = 'todo';

    public string $form_priority = 'medium';

    /** @var array<int, int|string> Every department the task is filed under. */
    public array $form_department_ids = [];

    /** @var array<int, int|string> Everyone the task is on. */
    public array $form_assignee_ids = [];

    public string $form_start_date = '';

    public string $form_due_date = '';

    /** Repeat rule. '' means the task happens once. */
    public string $form_repeat = '';

    public string $form_repeat_until = '';

    public function mount(): void
    {
        $this->requireTenant();
    }

    /* ----------------------------------------------------------------- data */

    /** @return Collection<int, Task> */
    public function tasks(): Collection
    {
        return Task::forTenant($this->requireTenant()->id)
            ->roots()
            ->when($this->department, fn ($q) => $q->inDepartments([$this->department]))
            ->when($this->assignee, fn ($q) => $q->assignedTo([$this->assignee]))
            ->when($this->search !== '', function ($q) {
                // Escaped so a stray % in the box means a literal percent sign
                // rather than "match anything".
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';

                $q->where(fn ($inner) => $inner->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->tap(fn ($q) => $this->applyDateFilter($q))
            ->with(['assignees:id,name', 'departments:id,name,color'])
            ->withCount('subtasks')
            ->boardOrder()
            ->get();
    }

    /**
     * Narrows the board to a slice of the calendar.
     *
     * "When" means the day the work is scheduled for, falling back to its
     * deadline — the same rule the calendar places tasks by, so a task sits on
     * the same day whichever page you are looking at. Overdue is the exception:
     * only a deadline can be missed.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Task>  $query
     */
    protected function applyDateFilter($query): void
    {
        $tz = $this->userTimezone();
        $today = CarbonImmutable::now($tz);

        if ($this->onDate !== '') {
            $day = CarbonImmutable::parse($this->onDate, $tz);

            $query->inCalendarWindow($day, $day, $tz);

            return;
        }

        match ($this->due) {
            'today' => $query->inCalendarWindow($today, $today, $tz),
            'week' => $query->inCalendarWindow($today, $today->addDays(6), $tz),
            'overdue' => $query->open()->where('due_date', '<', $today->utc()),
            'none' => $query->unscheduled(),
            default => null,
        };
    }

    /* -------------------------------------------------------------- filters */

    /** The date chips are a toggle: pressing the active one clears it. */
    public function setDue(string $value): void
    {
        $this->due = $this->due === $value ? '' : $value;
        $this->onDate = '';
    }

    /** Picking a day off the calendar outranks any chip that was set. */
    public function updatedOnDate(): void
    {
        $this->due = '';
    }

    public function clearFilters(): void
    {
        $this->department = null;
        $this->assignee = null;
        $this->search = '';
        $this->due = '';
        $this->onDate = '';
    }

    public function hasFilters(): bool
    {
        return $this->department || $this->assignee
            || $this->search !== '' || $this->due !== '' || $this->onDate !== '';
    }

    /* -------------------------------------------------------------- actions */

    public function setStatus(int $taskId, string $status): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('update', $task);

        $task->setStatus($status);
    }

    public function toggleDone(int $taskId): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('update', $task);

        $task->toggleDone();
    }

    /* ----------------------------------------------------------------- form */

    public function create(?string $status = null): void
    {
        $this->authorize('create', [Task::class, $this->requireTenant()->id]);

        $this->resetValidation();
        $this->editingId = null;
        $this->form_title = '';
        $this->form_description = '';
        $this->form_note = '';
        $this->form_status = $status && in_array($status, Task::STATUSES, true) ? $status : 'todo';
        $this->form_priority = 'medium';
        // A filtered board is filing work for that department, so start there.
        $this->form_department_ids = $this->department ? [$this->department] : [];
        $this->form_assignee_ids = [];
        $this->form_start_date = '';
        $this->form_due_date = '';
        $this->form_repeat = '';
        $this->form_repeat_until = '';
        $this->showModal = true;
    }

    public function edit(int $taskId): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('view', $task);

        $this->resetValidation();
        $this->editingId = $task->id;
        $this->form_title = $task->title;
        $this->form_description = (string) $task->description;
        $this->form_note = (string) $task->note;
        $this->form_status = $task->status;
        $this->form_priority = $task->priority;
        $this->form_department_ids = $task->departments()->pluck('departments.id')->all();
        $this->form_assignee_ids = $task->assignees()->pluck('users.id')->all();
        $this->form_start_date = $task->start_date?->setTimezone($this->userTimezone())->format('Y-m-d\TH:i') ?? '';
        $this->form_due_date = $task->due_date?->setTimezone($this->userTimezone())->format('Y-m-d\TH:i') ?? '';
        // Repeating is a creation-time choice: editing one occurrence edits
        // that day, not the run.
        $this->form_repeat = '';
        $this->form_repeat_until = '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenantId = $this->requireTenant()->id;

        $data = $this->validate([
            'form_title' => ['required', 'string', 'max:255'],
            'form_description' => ['nullable', 'string', 'max:5000'],
            'form_note' => ['nullable', 'string', 'max:5000'],
            'form_status' => ['required', Rule::in(Task::STATUSES)],
            'form_priority' => ['required', Rule::in(Task::PRIORITIES)],
            'form_department_ids' => ['array', 'max:20'],
            'form_department_ids.*' => [
                'integer',
                Rule::exists('departments', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'form_assignee_ids' => ['array', 'max:20'],
            'form_assignee_ids.*' => [
                'integer',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            ],
            'form_start_date' => ['nullable', 'date'],
            'form_due_date' => ['nullable', 'date'],
            'form_repeat' => ['nullable', Rule::in(TaskRecurrenceService::FREQUENCIES)],
            'form_repeat_until' => ['nullable', 'date', 'required_with:form_repeat', 'after_or_equal:today'],
        ], attributes: [
            'form_title' => 'title',
            'form_due_date' => 'due date',
            'form_assignee_ids' => 'assignees',
            'form_department_ids' => 'departments',
            'form_repeat' => 'repeat',
            'form_repeat_until' => 'repeat end date',
        ]);

        $payload = [
            'title' => $data['form_title'],
            'description' => $data['form_description'] ?: null,
            'note' => $data['form_note'] ?: null,
            'priority' => $data['form_priority'],
            'start_date' => $data['form_start_date']
                ? CarbonImmutable::parse($data['form_start_date'], $this->userTimezone())->utc()
                : null,
            'due_date' => $data['form_due_date']
                ? CarbonImmutable::parse($data['form_due_date'], $this->userTimezone())->utc()
                : null,
        ];

        if ($this->editingId) {
            $task = Task::findOrFail($this->editingId);
            $this->authorize('update', $task);
            $task->update($payload);
            $task->setStatus($data['form_status']);
            $this->applyOwners($task);
            session()->flash('status', 'Task updated.');
        } else {
            $this->authorize('create', [Task::class, $tenantId]);

            // Repeats walk the scheduled day when there is one, keeping any gap
            // to the deadline — the same rule the API uses.
            $anchorField = $payload['start_date'] ? 'start_date' : 'due_date';
            $dates = $this->repeatDates($payload[$anchorField] ?? null);

            $gap = ($anchorField === 'start_date' && $payload['due_date'])
                ? $payload['start_date']->diffInSeconds($payload['due_date'])
                : null;

            // One real task per date, tied together so the run can be dropped
            // in one go. Each is completed on its own.
            $seriesId = count($dates) > 1 ? (string) Str::uuid() : null;
            $created = 0;

            foreach ($dates as $date) {
                // array_merge, not `+`: the union operator keeps the left-hand
                // value, so $payload's due_date would win and every occurrence
                // would land on the same day.
                $occurrence = [$anchorField => $date];

                if ($gap !== null && $date) {
                    $occurrence['due_date'] = $date->addSeconds($gap);
                }

                $task = Task::create(array_merge($payload, [
                    'tenant_id' => $tenantId,
                    'created_by' => $this->currentUser()->id,
                    'status' => 'todo',
                    'series_id' => $seriesId,
                ], $occurrence));
                $task->setStatus($data['form_status']);
                $this->applyOwners($task);
                $created++;
            }

            session()->flash('status', $created > 1
                ? "{$created} tasks created, one per date."
                : 'Task created.');
        }

        $this->showModal = false;
    }

    /**
     * Files the task under the departments picked and puts the chosen people
     * on it. People first: an unfiled task follows its assignee into their
     * department, and an explicit pick of departments should then win.
     */
    protected function applyOwners(Task $task): void
    {
        $task->syncAssignees($this->form_assignee_ids);
        $task->syncDepartments($this->form_department_ids);
    }

    /**
     * The dates a save should create. A single-element list for an ordinary
     * task, so the caller has one path rather than two.
     *
     * @return array<int, \Carbon\CarbonImmutable|null>
     */
    protected function repeatDates(mixed $anchor): array
    {
        if ($this->form_repeat === '') {
            return [$anchor];
        }

        $tz = $this->userTimezone();

        $start = $anchor
            ? CarbonImmutable::parse($anchor)->setTimezone($tz)
            : CarbonImmutable::now($tz)->setTime(9, 0);

        $dates = app(TaskRecurrenceService::class)->dates(
            $start,
            ['frequency' => $this->form_repeat, 'until' => $this->form_repeat_until],
            $tz,
        );

        return array_map(fn (CarbonImmutable $date) => $date->utc(), $dates);
    }

    /** Shown live in the modal, so the count promised is the count created. */
    public function repeatPreview(): ?int
    {
        if ($this->form_repeat === '' || $this->form_repeat_until === '') {
            return null;
        }

        try {
            $anchor = $this->form_start_date ?: $this->form_due_date;

            return count($this->repeatDates(
                $anchor ? CarbonImmutable::parse($anchor, $this->userTimezone())->utc() : null
            ));
        } catch (\Throwable) {
            return null;
        }
    }

    public function delete(): void
    {
        $task = Task::findOrFail($this->editingId);
        $this->authorize('delete', $task);

        $task->delete();

        $this->showModal = false;
        session()->flash('status', 'Task deleted.');
    }

    /* --------------------------------------------------------------- render */

    public function with(): array
    {
        $tenantId = $this->requireTenant()->id;
        $tasks = $this->tasks();

        /*
         * Blocked only takes a column when something is actually blocked. It
         * used to sit in a strip under the board, which read as a footnote —
         * blocked work is the work most worth looking at.
         */
        $statuses = Task::BOARD_STATUSES;
        $blocked = $tasks->where('status', 'blocked')->values();

        if ($blocked->isNotEmpty()) {
            $statuses[] = 'blocked';
        }

        $columns = [];
        foreach ($statuses as $status) {
            $columns[$status] = $tasks->where('status', $status)->values();
        }

        return [
            'columns' => $columns,
            'shown' => $tasks->count(),
            // What the board holds with no filters on, so the header can say
            // "12 of 240" rather than leaving you to wonder what is hidden.
            'boardTotal' => Task::forTenant($tenantId)->roots()->count(),
            'dueOptions' => [
                'today' => 'Today',
                'week' => 'Next 7 days',
                'overdue' => 'Overdue',
                'none' => 'No date',
            ],
            'statusMeta' => Task::STATUS_META,
            'priorityMeta' => Task::PRIORITY_META,
            'departmentList' => Department::forTenant($tenantId)->get(),
            'memberList' => $this->currentTenant()->users()->orderBy('name')->get(['users.id', 'users.name']),
            // Who actually works in the chosen departments, so the assignee
            // picker leads with them instead of the whole workspace.
            'departmentMemberIds' => $this->form_department_ids
                ? Department::forTenant($tenantId)
                    ->whereIn('id', $this->form_department_ids)
                    ->with('members:id')
                    ->get()
                    ->flatMap->members
                    ->pluck('id')
                    ->unique()
                    ->values()
                    ->all()
                : [],
            'timezone' => $this->userTimezone(),
            'repeatOptions' => [
                'daily' => 'Every day',
                'weekdays' => 'Every weekday',
                'weekly' => 'Every week',
                'monthly' => 'Every month',
            ],
            'repeatCount' => $this->repeatPreview(),
        ];
    }
};
?>

<div class="mx-auto flex max-w-7xl flex-col gap-4">
    <header class="flex flex-wrap items-center gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="text-3xl font-extrabold tracking-tight">Tasks</h1>
            <p class="mt-1 text-[14px] text-ink-500 dark:text-ink-400">
                @if ($this->hasFilters())
                    <span class="font-semibold text-ink-700 dark:text-ink-200">{{ $shown }}</span>
                    of {{ $boardTotal }} {{ Str::plural('task', $boardTotal) }}
                @else
                    {{ $boardTotal }} {{ Str::plural('task', $boardTotal) }} on this board
                @endif
            </p>
        </div>

        <button type="button" wire:click="create()"
                class="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
            <x-icon name="plus" class="size-4" />
            New task
        </button>
    </header>

    {{-- Toolbar. Everything that narrows the board lives here, in one strip,
         so the board below is only ever tasks. --}}
    @php
        $control = 'rounded-xl border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold outline-none transition focus:border-brand-400 dark:border-ink-700 dark:bg-ink-900';
        $chip = 'rounded-lg px-2.5 py-1.5 text-[12px] font-bold transition';
    @endphp

    <div class="panel flex flex-col gap-3 p-3 shadow-sm shadow-ink-900/[0.03]">
        <div class="flex flex-wrap items-center gap-2">
            <label class="relative min-w-[12rem] flex-1">
                <span class="sr-only">Search tasks</span>
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search tasks…"
                       class="{{ $control }} w-full pl-9 font-medium placeholder:text-ink-400">
            </label>

            <select wire:model.live="department" aria-label="Filter by department" class="{{ $control }}">
                <option value="">All departments</option>
                @foreach ($departmentList as $dept)
                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="assignee" aria-label="Filter by assignee" class="{{ $control }}">
                <option value="">Anyone</option>
                @foreach ($memberList as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- When the work is due. The chips are the common answers; the date
             box is for any other day. --}}
        <div class="flex flex-wrap items-center gap-2 border-t border-ink-200/70 pt-3 dark:border-ink-800">
            <span class="mr-1 text-[11px] font-bold uppercase tracking-wider text-ink-400">When</span>

            <button type="button" wire:click="clearFilters"
                    class="{{ $chip }} {{ ! $this->hasFilters() ? 'bg-ink-900 text-white dark:bg-white dark:text-ink-900' : 'text-ink-500 hover:bg-ink-100 dark:text-ink-400 dark:hover:bg-ink-800' }}">
                Any date
            </button>

            @foreach ($dueOptions as $value => $optionLabel)
                <button type="button" wire:click="setDue('{{ $value }}')"
                        class="{{ $chip }} {{ $due === $value
                            ? 'bg-ink-900 text-white dark:bg-white dark:text-ink-900'
                            : 'text-ink-500 hover:bg-ink-100 dark:text-ink-400 dark:hover:bg-ink-800' }}">
                    {{ $optionLabel }}
                </button>
            @endforeach

            <label class="flex items-center gap-2 sm:ml-auto">
                <span class="text-[11px] font-bold uppercase tracking-wider text-ink-400">On</span>
                <input type="date" wire:model.live="onDate"
                       class="{{ $control }} {{ $onDate ? 'border-brand-400 text-brand-700 dark:text-brand-300' : '' }}">
            </label>

            @if ($this->hasFilters())
                <button type="button" wire:click="clearFilters"
                        class="{{ $chip }} text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-950">
                    Clear all
                </button>
            @endif
        </div>
    </div>

    {{-- Board. Columns scroll on their own, so a long Todo list never pushes
         In Progress off the bottom of the screen. --}}
    @if ($shown === 0)
        <div class="panel grid place-items-center gap-3 px-6 py-16 text-center">
            <x-icon name="search" class="size-8 text-ink-300" />
            <div>
                <p class="text-[15px] font-bold">Nothing matches</p>
                <p class="mt-1 text-[13px] text-ink-500 dark:text-ink-400">
                    @if ($this->hasFilters())
                        No task on this board fits the filters you have set.
                    @else
                        This board is empty. Add the first task.
                    @endif
                </p>
            </div>

            @if ($this->hasFilters())
                <button type="button" wire:click="clearFilters"
                        class="rounded-xl border border-ink-200 px-4 py-2 text-[13px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                    Clear filters
                </button>
            @else
                <button type="button" wire:click="create()"
                        class="rounded-xl bg-brand-600 px-4 py-2 text-[13px] font-bold text-white transition hover:bg-brand-700">
                    New task
                </button>
            @endif
        </div>
    @else
        <div class="grid gap-3 md:grid-cols-2 {{ count($columns) > 3 ? 'xl:grid-cols-4' : 'xl:grid-cols-3' }}">
            @foreach ($columns as $status => $items)
                <section class="panel flex min-h-[8rem] flex-col shadow-sm shadow-ink-900/[0.03] md:max-h-[calc(100vh-19rem)]"
                         wire:key="col-{{ $status }}">
                    <header class="flex items-center gap-2 px-3 py-2.5">
                        <span class="size-2 rounded-full" style="background-color: {{ $statusMeta[$status]['color'] }}"></span>
                        <h2 class="text-[13px] font-bold tracking-tight">{{ $statusMeta[$status]['label'] }}</h2>
                        <span class="text-[12px] font-bold text-ink-400">{{ $items->count() }}</span>

                        <button type="button" wire:click="create('{{ $status }}')"
                                aria-label="Add a task to {{ $statusMeta[$status]['label'] }}"
                                class="ml-auto grid size-7 place-items-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">
                            <x-icon name="plus" class="size-4" />
                        </button>
                    </header>

                    <div class="flex flex-col gap-1.5 overflow-y-auto px-2 pb-2">
                        @forelse ($items as $task)
                            @php
                                // Priority earns ink only when it is asking for
                                // attention. A board where every card shouts
                                // "Medium" is a board where nothing stands out.
                                $loud = in_array($task->priority, ['high', 'urgent'], true);
                                $when = $task->calendarDate();
                                $late = $task->isOverdue();
                            @endphp

                            {{-- shrink-0: without it the cards compress to fit the
                                 column instead of overflowing it, and a long list
                                 silently crushes every card's second line. --}}
                            <article wire:key="task-{{ $task->id }}"
                                     class="group relative shrink-0 overflow-hidden rounded-xl border border-ink-200/70 bg-white transition
                                            hover:border-ink-300 hover:shadow-sm dark:border-ink-800 dark:bg-ink-950/40">
                                @if ($loud)
                                    <span class="absolute inset-y-0 left-0 w-[3px]" aria-hidden="true"
                                          style="background-color: {{ $priorityMeta[$task->priority]['color'] }}"></span>
                                @endif

                                {{-- The gutter is always there; only a priority worth
                                     chasing paints it, so the titles still line up. --}}
                                <div class="flex items-start gap-2.5 p-2.5 pl-3.5">
                                    <button type="button" wire:click="toggleDone({{ $task->id }})"
                                            role="checkbox" aria-checked="{{ $task->isDone() ? 'true' : 'false' }}"
                                            aria-label="{{ $task->isDone() ? 'Reopen' : 'Complete' }} {{ $task->title }}"
                                            class="mt-px grid size-[17px] shrink-0 place-items-center rounded-full border-2 transition hover:border-brand-500"
                                            style="{{ $task->isDone()
                                                ? 'background-color: '.$statusMeta['done']['color'].'; border-color: '.$statusMeta['done']['color']
                                                : 'border-color: var(--color-ink-300)' }}">
                                        @if ($task->isDone())
                                            <svg class="size-2.5 text-white" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m2.5 6.5 2.5 2.5 4.5-5"/></svg>
                                        @endif
                                    </button>

                                    <button type="button" wire:click="edit({{ $task->id }})" class="min-w-0 flex-1 text-left">
                                        <span class="block text-[13.5px] font-semibold leading-snug {{ $task->isDone() ? 'text-ink-400 line-through' : '' }}">
                                            {{ $task->title }}
                                        </span>

                                        {{-- One quiet line of context. Everything here
                                             is grey unless it needs chasing. --}}
                                        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11.5px] font-medium text-ink-400">
                                            @if ($task->priority === 'urgent')
                                                <span class="font-bold" style="color: {{ $priorityMeta['urgent']['color'] }}">Urgent</span>
                                            @endif

                                            @foreach ($task->departments->take(2) as $dept)
                                                <span class="inline-flex items-center gap-1">
                                                    <span class="size-1.5 rounded-full" style="background-color: {{ $dept->color }}"></span>
                                                    {{ $dept->name }}
                                                </span>
                                            @endforeach

                                            @if ($task->departments->count() > 2)
                                                <span>+{{ $task->departments->count() - 2 }}</span>
                                            @endif

                                            @if ($when)
                                                <span class="inline-flex items-center gap-1 {{ $late ? 'font-bold text-red-600' : '' }}">
                                                    <x-icon name="clock" class="size-3" />
                                                    {{ $when->setTimezone($timezone)->format('j M') }}
                                                </span>
                                            @endif

                                            @if ($task->subtasks_count)
                                                <span>{{ $task->subtasks_count }} {{ Str::plural('subtask', $task->subtasks_count) }}</span>
                                            @endif
                                        </span>
                                    </button>

                                    {{-- Overlapping initials, newest tucked behind — three
                                         is as many as fits before the card stops reading. --}}
                                    @if ($task->assignees->isNotEmpty())
                                        <span class="flex shrink-0 -space-x-1.5" title="{{ $task->assignees->pluck('name')->join(', ') }}">
                                            @foreach ($task->assignees->take(3) as $person)
                                                <span class="grid size-6 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[10px] font-bold text-white ring-2 ring-white dark:ring-ink-900">
                                                    {{ Str::of($person->name)->substr(0, 1)->upper() }}
                                                </span>
                                            @endforeach

                                            @if ($task->assignees->count() > 3)
                                                <span class="grid size-6 place-items-center rounded-full bg-ink-200 text-[10px] font-bold text-ink-600 ring-2 ring-white dark:bg-ink-700 dark:text-ink-200 dark:ring-ink-900">
                                                    +{{ $task->assignees->count() - 3 }}
                                                </span>
                                            @endif
                                        </span>
                                    @endif
                                </div>

                                {{-- Status moves: the board's stand-in for dragging.
                                     Held back until the card is pointed at, so a
                                     full column stays readable. --}}
                                <div class="hidden gap-0.5 border-t border-ink-200/60 px-2 py-1 group-hover:flex focus-within:flex dark:border-ink-800">
                                    @foreach (\App\Models\Task::STATUSES as $option)
                                        @continue($option === $task->status)
                                        <button type="button" wire:click="setStatus({{ $task->id }}, '{{ $option }}')"
                                                class="rounded-md px-1.5 py-0.5 text-[11px] font-bold transition hover:bg-ink-100 dark:hover:bg-ink-800"
                                                style="color: {{ $statusMeta[$option]['color'] }}">
                                            {{ $statusMeta[$option]['short'] }}
                                        </button>
                                    @endforeach
                                </div>
                            </article>
                        @empty
                            <button type="button" wire:click="create('{{ $status }}')"
                                    class="rounded-xl border border-dashed border-ink-200 px-3 py-5 text-[12.5px] font-medium text-ink-400 transition hover:border-brand-300 hover:text-brand-600 dark:border-ink-800">
                                Nothing here — add one
                            </button>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    {{-- Modal --}}
    @if ($showModal)
        @php
            $field = 'w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-ink-300 focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950';
            $label = 'mb-1.5 block text-[13px] font-bold text-ink-600 dark:text-ink-300';
        @endphp

        <div class="fixed inset-0 z-50 grid place-items-end bg-ink-950/50 p-0 backdrop-blur-[2px] sm:place-items-center sm:p-4"
             wire:keydown.escape="$set('showModal', false)">
            <div class="max-h-[92vh] w-full max-w-lg overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl dark:bg-ink-900">
                <form wire:submit="save">
                    <header class="flex items-center justify-between px-6 pt-5 pb-4">
                        <h2 class="text-lg font-bold tracking-tight">{{ $editingId ? 'Edit task' : 'New task' }}</h2>
                        <button type="button" wire:click="$set('showModal', false)" aria-label="Close"
                                class="grid size-8 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">✕</button>
                    </header>

                    <div class="max-h-[65vh] space-y-4 overflow-y-auto px-6 pb-2">
                        <div>
                            <label class="{{ $label }}">Title</label>
                            <input type="text" wire:model="form_title" autofocus placeholder="Open the shop" class="{{ $field }}">
                            @error('form_title') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $label }}">Status</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($statusMeta as $value => $meta)
                                    <button type="button" wire:click="$set('form_status', '{{ $value }}')"
                                            class="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-[13px] font-bold transition
                                                   {{ $form_status === $value ? 'border-transparent text-white' : 'border-ink-200 dark:border-ink-700' }}"
                                            style="{{ $form_status === $value ? 'background-color: '.$meta['color'] : 'color: '.$meta['color'] }}">
                                        <span class="size-2 rounded-full" style="background-color: {{ $form_status === $value ? '#ffffff' : $meta['color'] }}"></span>
                                        {{ $meta['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label class="{{ $label }}">Priority</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($priorityMeta as $value => $meta)
                                    <button type="button" wire:click="$set('form_priority', '{{ $value }}')"
                                            class="rounded-xl border px-3 py-2 text-[13px] font-bold transition
                                                   {{ $form_priority === $value ? 'border-transparent text-white' : 'border-ink-200 dark:border-ink-700' }}"
                                            style="{{ $form_priority === $value ? 'background-color: '.$meta['color'] : 'color: '.$meta['color'] }}">
                                        {{ $meta['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Both take as many as apply: work two teams share is
                             one task on both boards, and a job two people are on
                             is one task with both names. --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="{{ $label }}">Departments</label>
                                {{-- Folding the list tells the server, so the
                                     assignee list below can lead with the people
                                     who actually work in what was just picked. --}}
                                <x-multi-select
                                    model="form_department_ids"
                                    :selected="$form_department_ids"
                                    commit-on-close
                                    :options="$departmentList->map(fn ($dept) => ['id' => $dept->id, 'label' => $dept->name, 'color' => $dept->color])"
                                    placeholder="Unfiled"
                                    search-placeholder="Search departments…"
                                    empty="No departments yet" />
                                @error('form_department_ids.*') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $label }}">Assignees</label>
                                <x-multi-select
                                    model="form_assignee_ids"
                                    :selected="$form_assignee_ids"
                                    :options="$memberList->map(fn ($member) => ['id' => $member->id, 'label' => $member->name])"
                                    :prefer="$departmentMemberIds"
                                    prefer-label="In these departments"
                                    other-label="Everyone else"
                                    placeholder="Unassigned"
                                    search-placeholder="Search people…"
                                    empty="No members yet" />
                                @error('form_assignee_ids.*') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label class="{{ $label }}">Start <span class="font-medium text-ink-400">(when it is scheduled)</span></label>
                            <input type="datetime-local" wire:model="form_start_date" class="{{ $field }}">
                            @error('form_start_date') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $label }}">Due <span class="font-medium text-ink-400">(deadline, optional)</span></label>
                            <input type="datetime-local" wire:model="form_due_date" class="{{ $field }}">
                            @error('form_due_date') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Repeating makes one real task per date, so each day is
                             ticked off on its own. Creation-time only: editing one
                             occurrence edits that day, not the run. --}}
                        @unless ($editingId)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="{{ $label }}">Repeat</label>
                                    <select wire:model.live="form_repeat" class="{{ $field }}">
                                        <option value="">Does not repeat</option>
                                        @foreach ($repeatOptions as $value => $optionLabel)
                                            <option value="{{ $value }}">{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                @if ($form_repeat)
                                    <div>
                                        <label class="{{ $label }}">Until</label>
                                        <input type="date" wire:model.live="form_repeat_until" class="{{ $field }}">
                                        @error('form_repeat_until') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </div>

                            @if ($repeatCount)
                                <p class="-mt-2 text-[13px] text-ink-500 dark:text-ink-400">
                                    Creates <span class="font-bold">{{ $repeatCount }}</span> {{ Str::plural('task', $repeatCount) }}, one per date.
                                    Completing one leaves the others alone.
                                </p>
                            @endif
                        @endunless

                        <div>
                            <label class="{{ $label }}">Description</label>
                            <textarea wire:model="form_description" rows="2" class="{{ $field }}"></textarea>
                        </div>

                        <div>
                            <label class="{{ $label }}">Note <span class="font-medium text-ink-400">(working notes)</span></label>
                            <textarea wire:model="form_note" rows="2" class="{{ $field }}"></textarea>
                        </div>
                    </div>

                    <footer class="mt-2 flex items-center gap-3 border-t border-ink-200/80 px-6 py-4 dark:border-ink-800">
                        @if ($editingId)
                            <button type="button" wire:click="delete" wire:confirm="Delete this task?"
                                    class="rounded-xl px-3 py-2.5 text-[14px] font-bold text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950">
                                Delete
                            </button>
                        @endif

                        <div class="ml-auto flex gap-2">
                            <button type="button" wire:click="$set('showModal', false)"
                                    class="rounded-xl border border-ink-200 px-4 py-2.5 text-[14px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="rounded-xl bg-brand-600 px-5 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                                {{ $editingId
                                    ? 'Save changes'
                                    : ($repeatCount > 1 ? "Create {$repeatCount} tasks" : 'Create task') }}
                            </button>
                        </div>
                    </footer>
                </form>
            </div>
        </div>
    @endif
</div>
