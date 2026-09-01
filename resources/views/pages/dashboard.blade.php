<?php

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\ChecklistItem;
use App\Models\Department;
use App\Models\Task;
use App\Services\EventService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Calendar — BongCalendar')]
class extends Component
{
    use InteractsWithTenant;

    /** Anchor month, as Y-m. Kept in the URL so a view can be shared or reloaded. */
    #[Url]
    public string $month = '';

    /** The day whose agenda is open in the panel below the grid, as Y-m-d. */
    #[Url]
    public string $day = '';

    /** Calendar ids currently shown. Empty means "all visible calendars". */
    #[Url]
    public array $selected = [];

    /** Restrict the whole view to one department. Null means every department. */
    #[Url]
    public ?int $department = null;

    public bool $showModal = false;

    public ?int $editingId = null;

    /** Quick-add box in the day panel. */
    public string $newTaskTitle = '';

    /** Task open in the detail sidebar, kept in the URL so it can be linked. */
    #[Url]
    public ?int $taskId = null;

    // Detail sidebar fields. Bound with .blur, so each commits when you leave it.
    public string $detail_title = '';

    public string $detail_description = '';

    public string $detail_note = '';

    public string $detail_start = '';

    public string $detail_due = '';

    public ?int $detail_department_id = null;

    public ?int $detail_assignee_id = null;

    public string $newSubtaskTitle = '';

    public string $newChecklistTitle = '';

    // Event form
    public ?int $form_calendar_id = null;

    public string $form_title = '';

    public string $form_description = '';

    public string $form_location = '';

    public string $form_starts_at = '';

    public string $form_ends_at = '';

    public bool $form_all_day = false;

    public string $form_status = 'confirmed';

    public string $form_invitees = '';

    public function mount(): void
    {
        $this->requireTenant();

        $today = CarbonImmutable::now($this->userTimezone());

        $this->month = $this->month ?: $today->format('Y-m');
        $this->day = $this->day ?: $today->format('Y-m-d');

        $this->mountDetail();
    }

    /* ---------------------------------------------------------------- state */

    public function anchor(): CarbonImmutable
    {
        $tz = $this->userTimezone();

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01', $tz)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now($tz)->startOfMonth();
        }
    }

    public function selectedDay(): CarbonImmutable
    {
        $tz = $this->userTimezone();

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $this->day, $tz)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now($tz)->startOfDay();
        }
    }

    public function goToMonth(int $offset): void
    {
        $anchor = $this->anchor()->addMonths($offset);

        $this->month = $anchor->format('Y-m');

        // Keep the agenda panel inside the month being browsed.
        if ($this->selectedDay()->format('Y-m') !== $this->month) {
            $this->day = $anchor->startOfMonth()->format('Y-m-d');
        }
    }

    public function today(): void
    {
        $today = CarbonImmutable::now($this->userTimezone());

        $this->month = $today->format('Y-m');
        $this->day = $today->format('Y-m-d');
    }

    public function selectDay(string $key): void
    {
        $this->day = $key;

        if (str_starts_with($key, $this->month) === false) {
            $this->month = substr($key, 0, 7);
        }
    }

    /* ---------------------------------------------------------------- tasks */

    public function toggleTask(int $taskId): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('update', $task);

        $task->toggleDone();
    }

    public function mountDetail(): void
    {
        // A task id arriving in the URL has to populate the form fields too.
        if ($this->taskId && $this->detail_title === '') {
            $task = $this->activeTask();

            if ($task) {
                $this->fillDetail($task);
            } else {
                $this->taskId = null;
            }
        }
    }

    public function setTaskStatus(int $taskId, string $status): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('update', $task);

        $task->setStatus($status);
    }

    /* ------------------------------------------------------- detail sidebar */

    public function selectTask(int $taskId): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('view', $task);

        $this->resetValidation();
        $this->taskId = $task->id;
        $this->fillDetail($task);
    }

    public function closeTask(): void
    {
        $this->taskId = null;
    }

    protected function fillDetail(Task $task): void
    {
        $this->detail_title = $task->title;
        $this->detail_description = (string) $task->description;
        $this->detail_note = (string) $task->note;
        $this->detail_start = $task->start_date?->setTimezone($this->userTimezone())->format('Y-m-d\TH:i') ?? '';
        $this->detail_due = $task->due_date?->setTimezone($this->userTimezone())->format('Y-m-d\TH:i') ?? '';
        $this->detail_department_id = $task->department_id;
        $this->detail_assignee_id = $task->assignee_id;
        $this->newSubtaskTitle = '';
    }

    /** The task the sidebar is showing, or null when it is closed. */
    public function activeTask(): ?Task
    {
        if (! $this->taskId) {
            return null;
        }

        return Task::forTenant($this->requireTenant()->id)
            ->with(['department:id,name,color', 'assignee:id,name'])
            ->find($this->taskId);
    }

    /** Writes one field of the open task, after checking it is still ours. */
    protected function updateActiveTask(array $payload): void
    {
        $task = $this->activeTask();

        if (! $task) {
            $this->taskId = null;

            return;
        }

        $this->authorize('update', $task);
        $task->update($payload);
    }

    public function updatedDetailTitle(string $value): void
    {
        $title = trim($value);

        // An empty title is a slip, not an instruction: put the old one back.
        if ($title === '') {
            $this->detail_title = $this->activeTask()?->title ?? '';

            return;
        }

        $this->validateOnly('detail_title', ['detail_title' => ['required', 'string', 'max:255']]);
        $this->updateActiveTask(['title' => $title]);
    }

    public function updatedDetailDescription(string $value): void
    {
        $this->updateActiveTask(['description' => trim($value) ?: null]);
    }

    public function updatedDetailNote(string $value): void
    {
        $this->updateActiveTask(['note' => trim($value) ?: null]);
    }

    public function updatedDetailStart(string $value): void
    {
        $this->updateActiveTask([
            'start_date' => $value
                ? CarbonImmutable::parse($value, $this->userTimezone())->utc()
                : null,
        ]);
    }

    public function updatedDetailDue(string $value): void
    {
        $this->updateActiveTask([
            'due_date' => $value
                ? CarbonImmutable::parse($value, $this->userTimezone())->utc()
                : null,
        ]);
    }

    public function updatedDetailDepartmentId(mixed $value): void
    {
        $this->updateActiveTask([
            'department_id' => $value
                ? Department::where('tenant_id', $this->requireTenant()->id)->findOrFail($value)->id
                : null,
        ]);
    }

    public function updatedDetailAssigneeId(mixed $value): void
    {
        if ($value && ! $this->requireTenant()->users()->whereKey($value)->exists()) {
            abort(403, 'That person is not a member of this workspace.');
        }

        $this->updateActiveTask(['assignee_id' => $value ?: null]);
    }

    public function setTaskPriority(int $taskId, string $priority): void
    {
        abort_unless(in_array($priority, Task::PRIORITIES, true), 422);

        $task = Task::findOrFail($taskId);
        $this->authorize('update', $task);

        $task->update(['priority' => $priority]);
    }

    public function clearTaskDue(int $taskId): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('update', $task);

        $task->update(['due_date' => null]);
        $this->detail_due = '';
    }

    public function clearTaskStart(int $taskId): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('update', $task);

        $task->update(['start_date' => null]);
        $this->detail_start = '';
    }

    public function addSubtask(): void
    {
        $parent = $this->activeTask();

        if (! $parent || $parent->parent_task_id !== null) {
            return;
        }

        $this->authorize('update', $parent);

        $data = $this->validate(
            ['newSubtaskTitle' => ['required', 'string', 'max:255']],
            attributes: ['newSubtaskTitle' => 'subtask']
        );

        Task::create([
            'tenant_id' => $parent->tenant_id,
            'created_by' => $this->currentUser()->id,
            'department_id' => $parent->department_id,
            'parent_task_id' => $parent->id,
            'title' => $data['newSubtaskTitle'],
            'status' => 'todo',
            'priority' => $parent->priority,
        ]);

        $this->newSubtaskTitle = '';
    }

    /* ------------------------------------------------------------ checklist */

    /** Checklist items borrow their task's permissions. */
    protected function checklistItem(int $itemId): ChecklistItem
    {
        $item = ChecklistItem::with('task')->findOrFail($itemId);

        $this->authorize('update', $item->task);

        return $item;
    }

    public function addChecklistItem(): void
    {
        $task = $this->activeTask();

        if (! $task) {
            return;
        }

        $this->authorize('update', $task);

        $data = $this->validate(
            ['newChecklistTitle' => ['required', 'string', 'max:255']],
            attributes: ['newChecklistTitle' => 'checklist item']
        );

        $task->checklist()->create([
            'title' => $data['newChecklistTitle'],
            'position' => (int) $task->checklist()->max('position') + 1,
        ]);

        $this->newChecklistTitle = '';
    }

    public function toggleChecklistItem(int $itemId): void
    {
        $this->checklistItem($itemId)->toggle();
    }

    public function deleteChecklistItem(int $itemId): void
    {
        $this->checklistItem($itemId)->delete();
    }

    /** Swaps an item with its neighbour. Dragging is never the only way to move. */
    public function moveChecklistItem(int $itemId, int $direction): void
    {
        $item = $this->checklistItem($itemId);

        $siblings = $item->task->checklist()->get();
        $index = $siblings->search(fn (ChecklistItem $entry) => $entry->id === $item->id);
        $target = $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $target < 0 || $target >= $siblings->count()) {
            return;
        }

        $other = $siblings[$target];

        // Positions may have collided or never been set; renumber the list so a
        // swap always sticks.
        $ordered = $siblings->all();
        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        foreach ($ordered as $position => $entry) {
            $entry->update(['position' => $position]);
        }

        unset($other);
    }

    public function deleteTask(int $taskId): void
    {
        $task = Task::findOrFail($taskId);
        $this->authorize('delete', $task);

        $wasOpen = $task->id === $this->taskId;
        $task->delete();

        if ($wasOpen) {
            $this->taskId = null;
        }

        session()->flash('status', 'Task deleted.');
    }

    /** Adds a task due on the day the panel is showing. */
    public function addTask(): void
    {
        $tenantId = $this->requireTenant()->id;

        $this->authorize('create', [Task::class, $tenantId]);

        $data = $this->validate(
            ['newTaskTitle' => ['required', 'string', 'max:255']],
            attributes: ['newTaskTitle' => 'task']
        );

        Task::create([
            'tenant_id' => $tenantId,
            'created_by' => $this->currentUser()->id,
            'department_id' => $this->department,
            'title' => $data['newTaskTitle'],
            'status' => 'todo',
            'priority' => 'medium',
            // Added from a day on the calendar, that day is when the work is
            // scheduled — not a deadline it must be finished by.
            'start_date' => $this->selectedDay()->setTime(9, 0)->utc(),
        ]);

        $this->newTaskTitle = '';
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Task> */
    protected function taskQuery()
    {
        return Task::forTenant($this->requireTenant()->id)
            ->roots()
            ->when($this->department, fn ($q) => $q->where('department_id', $this->department));
    }

    public function clearDepartment(): void
    {
        $this->department = null;
        $this->selected = [];
    }

    public function toggleCalendar(int $calendarId): void
    {
        $this->selected = in_array($calendarId, $this->selected, true)
            ? array_values(array_diff($this->selected, [$calendarId]))
            : [...$this->selected, $calendarId];
    }

    /* ----------------------------------------------------------------- data */

    /** @return Collection<int, Calendar> */
    public function calendars(): Collection
    {
        return Calendar::visibleTo($this->currentUser(), $this->requireTenant()->id)
            ->when($this->department, fn ($q) => $q->where('department_id', $this->department))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * Calendar ids the grid should read, or null for "everything visible".
     * A department narrows the pool; the chips narrow it further.
     */
    protected function scopedCalendarIds(): ?array
    {
        if (! $this->department) {
            return $this->selected ?: null;
        }

        $inDepartment = $this->calendars()->pluck('id')->all();

        return $this->selected
            ? array_values(array_intersect($inDepartment, $this->selected))
            : $inDepartment;
    }

    /** @return Collection<int, Event> */
    public function events(EventService $events): Collection
    {
        return $events->inRange(
            $this->currentUser(),
            $this->requireTenant()->id,
            $this->anchor()->startOfMonth()->startOfWeek(CarbonInterface::SUNDAY)->utc(),
            $this->anchor()->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY)->utc(),
            $this->scopedCalendarIds(),
        );
    }

    /* ----------------------------------------------------------------- form */

    public function createEvent(?string $date = null): void
    {
        $tz = $this->userTimezone();
        $now = CarbonImmutable::now($tz);
        $target = $date ? CarbonImmutable::parse($date, $tz) : $this->selectedDay();

        // Today opens at the next hour; any other day opens at 9am.
        $start = $target->isSameDay($now)
            ? $now->addHour()->startOfHour()
            : $target->setTime(9, 0);

        $this->resetValidation();
        $this->editingId = null;
        $this->form_calendar_id = $this->defaultWritableCalendarId();
        $this->form_title = '';
        $this->form_description = '';
        $this->form_location = '';
        $this->form_starts_at = $start->format('Y-m-d\TH:i');
        $this->form_ends_at = $start->addHour()->format('Y-m-d\TH:i');
        $this->form_all_day = false;
        $this->form_status = 'confirmed';
        $this->form_invitees = '';
        $this->showModal = true;
    }

    public function editEvent(int $eventId): void
    {
        $event = Event::with('invitations')->findOrFail($eventId);
        $this->authorize('view', $event);

        $tz = $this->userTimezone();

        $this->resetValidation();
        $this->editingId = $event->id;
        $this->form_calendar_id = $event->calendar_id;
        $this->form_title = $event->title;
        $this->form_description = (string) $event->description;
        $this->form_location = (string) $event->location;
        $this->form_starts_at = $event->starts_at->setTimezone($tz)->format('Y-m-d\TH:i');
        $this->form_ends_at = $event->ends_at->setTimezone($tz)->format('Y-m-d\TH:i');
        $this->form_all_day = $event->all_day;
        $this->form_status = $event->status;
        $this->form_invitees = $event->invitations->pluck('email')->implode(', ');
        $this->showModal = true;
    }

    public function saveEvent(EventService $events): void
    {
        $data = $this->validate([
            'form_calendar_id' => ['required', 'integer', 'exists:calendars,id'],
            'form_title' => ['required', 'string', 'max:255'],
            'form_description' => ['nullable', 'string', 'max:5000'],
            'form_location' => ['nullable', 'string', 'max:255'],
            'form_starts_at' => ['required', 'date'],
            'form_ends_at' => ['required', 'date', 'after_or_equal:form_starts_at'],
            'form_status' => ['required', 'in:confirmed,tentative,cancelled'],
            'form_invitees' => ['nullable', 'string'],
        ], attributes: [
            'form_calendar_id' => 'calendar',
            'form_title' => 'title',
            'form_starts_at' => 'start',
            'form_ends_at' => 'end',
        ]);

        $calendar = Calendar::findOrFail($data['form_calendar_id']);
        $this->authorize('addEvents', $calendar);

        $payload = [
            'title' => $data['form_title'],
            'description' => $data['form_description'] ?: null,
            'location' => $data['form_location'] ?: null,
            'starts_at' => $data['form_starts_at'],
            'ends_at' => $data['form_ends_at'],
            'timezone' => $this->userTimezone(),
            'all_day' => $this->form_all_day,
            'status' => $data['form_status'],
            'invitees' => $this->parsedInvitees(),
            'calendar_id' => $calendar->id,
        ];

        if ($this->editingId) {
            $event = Event::findOrFail($this->editingId);
            $this->authorize('update', $event);
            $events->update($event, $payload);
            session()->flash('status', 'Event updated.');
        } else {
            $events->create($calendar, $this->currentUser(), $payload);
            session()->flash('status', 'Event created.');
        }

        // Jump the agenda panel to the day the event landed on.
        $this->selectDay(CarbonImmutable::parse($data['form_starts_at'], $this->userTimezone())->format('Y-m-d'));

        $this->showModal = false;
    }

    public function deleteEvent(): void
    {
        $event = Event::findOrFail($this->editingId);
        $this->authorize('delete', $event);

        $event->delete();

        $this->showModal = false;
        session()->flash('status', 'Event deleted.');
    }

    /** @return array<int, string> */
    protected function parsedInvitees(): array
    {
        return collect(preg_split('/[,\s;]+/', $this->form_invitees) ?: [])
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    protected function defaultWritableCalendarId(): ?int
    {
        $user = $this->currentUser();

        return $this->calendars()
            ->first(fn (Calendar $calendar) => $calendar->isWritableBy($user))?->id;
    }

    /* --------------------------------------------------------------- render */

    public function with(EventService $events): array
    {
        $tz = $this->userTimezone();
        $anchor = $this->anchor();
        $now = CarbonImmutable::now($tz);

        $gridStart = $anchor->startOfMonth()->startOfWeek(CarbonInterface::SUNDAY);
        $gridEnd = $anchor->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY);

        // Group by local calendar day, expanding multi-day events across cells.
        $byDay = [];

        foreach ($this->events($events) as $event) {
            $start = $event->starts_at->setTimezone($tz);
            $end = $event->ends_at->setTimezone($tz);
            $cursor = CarbonImmutable::instance($start)->startOfDay();
            $last = CarbonImmutable::instance($end)->startOfDay();

            while ($cursor <= $last) {
                $byDay[$cursor->format('Y-m-d')][] = $event;
                $cursor = $cursor->addDay();
            }
        }

        // Tasks land on the grid by due date, in the viewer's timezone.
        $tasksByDay = [];

        $window = [$gridStart->utc(), $gridEnd->endOfDay()->utc()];

        foreach ($this->taskQuery()
            ->where(fn ($q) => $q
                ->whereBetween('start_date', $window)
                ->orWhere(fn ($inner) => $inner->whereNull('start_date')->whereBetween('due_date', $window)))
            ->with(['department:id,name,color', 'assignee:id,name'])
            ->boardOrder()
            ->get() as $task) {
            // The scheduled day wins; the deadline is the fallback, so a task
            // with either one still lands on the grid.
            $tasksByDay[$task->calendarDate()->setTimezone($tz)->format('Y-m-d')][] = $task;
        }

        $selected = $this->selectedDay();
        $days = [];

        for ($day = $gridStart; $day <= $gridEnd; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $dayEvents = $byDay[$key] ?? [];
            $dayTasks = $tasksByDay[$key] ?? [];

            // Dots read left to right: calendars first, then task statuses.
            $dots = array_merge(
                array_map(fn (Event $event) => $event->displayColor(), $dayEvents),
                array_map(fn (Task $task) => $task->statusMeta()['color'], $dayTasks),
            );

            $days[] = [
                'date' => $day,
                'key' => $key,
                'in_month' => $day->month === $anchor->month,
                'is_today' => $day->isSameDay($now),
                'is_selected' => $day->isSameDay($selected),
                'count' => count($dayEvents) + count($dayTasks),
                'dots' => array_slice(array_values(array_unique($dots)), 0, 3),
            ];
        }

        $activeTask = $this->activeTask();
        $tenantIdForView = $this->requireTenant()->id;

        $greeting = match (true) {
            $now->hour < 12 => 'Good morning',
            $now->hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        return [
            'activeDepartment' => $this->department
                ? \App\Models\Department::where('tenant_id', $this->requireTenant()->id)->find($this->department)
                : null,
            'anchor' => $anchor,
            'weeks' => array_chunk($days, 7),
            'calendarList' => $this->calendars(),
            'timezone' => $tz,
            'greeting' => $greeting,
            'selectedDate' => $selected,
            'dayEvents' => collect($byDay[$selected->format('Y-m-d')] ?? [])
                ->sortBy(fn (Event $event) => $event->starts_at)
                ->values(),
            'dayTasks' => collect($tasksByDay[$selected->format('Y-m-d')] ?? []),
            'activeTask' => $activeTask,
            'subtasks' => $activeTask
                ? Task::where('parent_task_id', $activeTask->id)->boardOrder()->get()
                : collect(),
            'checklist' => $activeTask ? $activeTask->checklist()->get() : collect(),
            'departmentList' => Department::forTenant($tenantIdForView)->get(),
            'memberList' => $this->currentTenant()->users()->orderBy('name')->get(['users.id', 'users.name']),
            'statusMeta' => Task::STATUS_META,
            'priorityMeta' => Task::PRIORITY_META,
            'openTaskCount' => $this->taskQuery()->open()->count(),
        ];
    }
};
?>

<div class="mx-auto w-full {{ $activeTask ? 'max-w-[80rem]' : 'max-w-6xl' }} xl:flex xl:items-start xl:gap-5">
<div class="flex min-w-0 flex-1 flex-col gap-5">
    {{-- ─────────────────────────────── Page head ─────────────────────────────── --}}
    <header class="flex flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="text-3xl font-extrabold tracking-tight sm:text-[2rem]">
                {{ $selectedDate->isSameDay(now($timezone)) ? 'Today' : $selectedDate->format('l') }}
            </h1>
            <p class="mt-1.5 text-[15px] font-semibold text-ink-600 dark:text-ink-300">
                {{ $greeting }}, {{ auth()->user()->name }} <span class="ml-0.5">👋</span>
            </p>
            <p class="mt-0.5 text-[13px] text-ink-400">
                {{ $selectedDate->format('l, M j') }}
                · {{ $dayEvents->count() }} {{ \Illuminate\Support\Str::plural('event', $dayEvents->count()) }}
                · {{ $dayTasks->count() }} {{ \Illuminate\Support\Str::plural('task', $dayTasks->count()) }}
            </p>

            @if ($activeDepartment)
                <button type="button" wire:click="clearDepartment"
                        class="mt-2.5 inline-flex items-center gap-2 rounded-full border border-ink-200 bg-white py-1 pl-2.5 pr-2 text-[13px] font-bold shadow-sm transition hover:border-ink-300 dark:border-ink-700 dark:bg-ink-900">
                    <span class="size-2 rounded-full" style="background-color: {{ $activeDepartment->color }}"></span>
                    {{ $activeDepartment->name }}
                    <span class="grid size-4 place-items-center rounded-full text-ink-400" aria-hidden="true">✕</span>
                    <span class="sr-only">Show every department</span>
                </button>
            @endif
        </div>

        <div class="flex items-center gap-1.5">
            <a href="{{ route('invitations.index') }}" aria-label="Invitations"
               class="relative grid size-10 place-items-center rounded-xl text-ink-500 transition hover:bg-white hover:text-ink-800 hover:shadow-sm dark:hover:bg-ink-800 dark:hover:text-white">
                <x-icon name="bell" />
            </a>
            <button type="button" wire:click="createEvent()" aria-label="New event"
                    class="grid size-10 place-items-center rounded-xl bg-brand-600 text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                <x-icon name="plus" />
            </button>
        </div>
    </header>

    {{-- ─────────────────────────────── Month grid ────────────────────────────── --}}
    <section class="panel overflow-hidden shadow-sm shadow-ink-900/[0.03]">
        <header class="flex items-center gap-3 px-4 pt-4 pb-3 sm:px-6">
            <h2 class="text-lg font-bold tracking-tight">{{ $anchor->format('F Y') }}</h2>

            <div class="ml-auto flex items-center gap-1">
                <button type="button" wire:click="today"
                        class="rounded-xl bg-brand-50 px-3.5 py-1.5 text-[13px] font-bold text-brand-700 transition hover:bg-brand-100 dark:bg-brand-950 dark:text-brand-200 dark:hover:bg-brand-900">
                    Today
                </button>
                <button type="button" wire:click="goToMonth(-1)" aria-label="Previous month"
                        class="grid size-9 place-items-center rounded-xl text-ink-500 transition hover:bg-ink-100 hover:text-ink-800 dark:hover:bg-ink-800 dark:hover:text-white">
                    <x-icon name="chevron-left" class="size-[18px]" />
                </button>
                <button type="button" wire:click="goToMonth(1)" aria-label="Next month"
                        class="grid size-9 place-items-center rounded-xl text-ink-500 transition hover:bg-ink-100 hover:text-ink-800 dark:hover:bg-ink-800 dark:hover:text-white">
                    <x-icon name="chevron-right" class="size-[18px]" />
                </button>
            </div>
        </header>

        <div class="grid grid-cols-7 px-2 pb-1 text-center text-[13px] font-semibold text-ink-400 sm:px-4">
            @foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $index => $label)
                <div class="py-2" aria-hidden="true">{{ $label }}</div>
            @endforeach
        </div>

        <div class="px-2 pb-4 sm:px-4" wire:loading.class="opacity-40" wire:target="goToMonth,today,toggleCalendar">
            @foreach ($weeks as $week)
                <div class="grid grid-cols-7">
                    @foreach ($week as $day)
                        <button type="button" wire:click="selectDay('{{ $day['key'] }}')" wire:key="day-{{ $day['key'] }}"
                                aria-label="{{ $day['date']->format('l, j F Y') }}, {{ $day['count'] }} events"
                                @if ($day['is_selected']) aria-current="date" @endif
                                class="group flex flex-col items-center gap-1.5 rounded-xl py-2 transition">
                            @php
                                $pill = match (true) {
                                    $day['is_selected'] => 'bg-brand-600 text-white shadow-sm shadow-brand-600/30',
                                    $day['is_today'] => 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-200',
                                    $day['in_month'] => 'text-ink-800 group-hover:bg-ink-100 dark:text-ink-100 dark:group-hover:bg-ink-800',
                                    default => 'text-ink-300 group-hover:bg-ink-100 dark:text-ink-600 dark:group-hover:bg-ink-800',
                                };
                            @endphp

                            <span class="grid size-10 place-items-center rounded-full text-[15px] font-semibold transition {{ $pill }}">
                                {{ $day['date']->day }}
                            </span>

                            <span class="flex h-1.5 items-center gap-1">
                                @foreach ($day['dots'] as $color)
                                    <span class="size-1.5 rounded-full transition {{ $day['in_month'] ? '' : 'opacity-40' }}"
                                          style="background-color: {{ $color }}"></span>
                                @endforeach
                            </span>
                        </button>
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>

    {{-- ────────────────────────────── Day agenda ─────────────────────────────── --}}
    <section class="panel p-4 shadow-sm shadow-ink-900/[0.03] sm:p-6">
        <header class="mb-4 flex items-center gap-3">
            <div>
                <h2 class="text-[15px] font-bold tracking-tight">{{ $selectedDate->format('l, j F') }}</h2>
                <p class="text-[13px] text-ink-400">Times in {{ $timezone }}</p>
            </div>

            @if ($dayEvents->isNotEmpty())
                <button type="button" wire:click="createEvent('{{ $selectedDate->format('Y-m-d') }}')"
                        class="ml-auto flex items-center gap-1.5 rounded-xl border border-ink-200 px-3 py-1.5 text-[13px] font-bold text-ink-700 transition hover:border-brand-300 hover:text-brand-700 dark:border-ink-700 dark:text-ink-200">
                    <x-icon name="plus" class="size-4" />
                    Add event
                </button>
            @endif
        </header>

        {{-- Tasks due on this day --}}
        @if ($dayTasks->isNotEmpty())
            <ul class="mb-3 space-y-1 border-b border-ink-200/70 pb-3 dark:border-ink-800">
                @foreach ($dayTasks as $index => $task)
                    <li wire:key="day-task-{{ $task->id }}"
                        class="rise group flex items-center gap-3 rounded-xl px-2 py-2 transition
                               {{ $activeTask && $activeTask->id === $task->id
                                   ? 'bg-brand-50 dark:bg-brand-950/60'
                                   : 'hover:bg-ink-50 dark:hover:bg-ink-800' }}"
                        style="animation-delay: {{ $index * 40 }}ms">
                        <button type="button" wire:click="toggleTask({{ $task->id }})"
                                role="checkbox" aria-checked="{{ $task->isDone() ? 'true' : 'false' }}"
                                aria-label="{{ $task->isDone() ? 'Reopen' : 'Complete' }} {{ $task->title }}"
                                class="grid size-[18px] shrink-0 place-items-center rounded-full border-2 transition"
                                style="{{ $task->isDone()
                                    ? 'background-color: '.$statusMeta['done']['color'].'; border-color: '.$statusMeta['done']['color']
                                    : 'border-color: '.$task->statusMeta()['color'] }}">
                            @if ($task->isDone())
                                <svg class="size-2.5 text-white" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m2.5 6.5 2.5 2.5 4.5-5"/></svg>
                            @endif
                        </button>

                        <button type="button" wire:click="selectTask({{ $task->id }})"
                                aria-label="Open {{ $task->title }}"
                                @if ($activeTask && $activeTask->id === $task->id) aria-current="true" @endif
                                class="min-w-0 flex-1 text-left">
                            <span class="block truncate text-[15px] font-semibold {{ $task->isDone() ? 'text-ink-400 line-through' : '' }}">
                                {{ $task->title }}
                            </span>
                            @if ($task->department || $task->assignee)
                                <span class="mt-0.5 flex items-center gap-2 text-[13px] text-ink-400">
                                    @if ($task->department)
                                        <span class="inline-flex items-center gap-1">
                                            <span class="size-1.5 rounded-full" style="background-color: {{ $task->department->color }}"></span>
                                            {{ $task->department->name }}
                                        </span>
                                    @endif
                                    @if ($task->assignee) <span class="truncate">{{ $task->assignee->name }}</span> @endif
                                </span>
                            @endif
                        </button>

                        {{-- The status pill doubles as the status picker. --}}
                        <span x-data="{ open: false }" class="relative shrink-0">
                            <button type="button" x-on:click="open = !open"
                                    class="rounded-full px-2.5 py-1 text-[11px] font-bold transition"
                                    style="background-color: {{ $task->statusMeta()['color'] }}1a; color: {{ $task->statusMeta()['color'] }}">
                                {{ $task->statusMeta()['label'] }}
                            </button>

                            <span x-show="open" x-on:click.outside="open = false" x-transition x-cloak
                                  class="absolute right-0 top-full z-10 mt-1 flex w-36 flex-col rounded-xl border border-ink-200 bg-white p-1 shadow-lg dark:border-ink-700 dark:bg-ink-800">
                                @foreach ($statusMeta as $value => $meta)
                                    <button type="button" x-on:click="open = false" wire:click="setTaskStatus({{ $task->id }}, '{{ $value }}')"
                                            class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px] font-semibold transition hover:bg-ink-100 dark:hover:bg-ink-700">
                                        <span class="size-2 rounded-full" style="background-color: {{ $meta['color'] }}"></span>
                                        {{ $meta['label'] }}
                                    </button>
                                @endforeach
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Quick add, the way the tablet app does it --}}
        <form wire:submit="addTask" class="mb-3 flex items-center gap-2">
            <input type="text" wire:model="newTaskTitle" placeholder="Add a task for this day…"
                   class="min-w-0 flex-1 rounded-xl border border-ink-200 bg-white px-3.5 py-2 text-[14px] outline-none transition placeholder:text-ink-300 focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950">
            <button type="submit"
                    class="shrink-0 rounded-xl bg-ink-900 px-3.5 py-2 text-[13px] font-bold text-white transition hover:bg-ink-800 active:scale-95 dark:bg-white dark:text-ink-900">
                Add task
            </button>
        </form>
        @error('newTaskTitle') <p class="-mt-2 mb-3 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror

        @forelse ($dayEvents as $index => $event)
            <button type="button" wire:click="editEvent({{ $event->id }})" wire:key="event-{{ $event->id }}"
                    class="rise flex w-full items-center gap-3.5 rounded-xl px-2 py-2.5 text-left transition hover:bg-ink-50 dark:hover:bg-ink-800"
                    style="animation-delay: {{ $index * 40 }}ms">
                <span class="h-10 w-1 shrink-0 rounded-full" style="background-color: {{ $event->displayColor() }}"></span>

                <span class="w-16 shrink-0 text-[13px] font-bold tabular-nums text-ink-500 dark:text-ink-300">
                    {{ $event->all_day ? 'All day' : $event->starts_at->setTimezone($timezone)->format('H:i') }}
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold {{ $event->status === 'cancelled' ? 'text-ink-400 line-through' : '' }}">
                        {{ $event->title }}
                    </span>
                    <span class="mt-0.5 flex items-center gap-2 text-[13px] text-ink-400">
                        <span class="truncate">{{ $event->calendar->name }}</span>
                        @if ($event->location)
                            <span class="flex min-w-0 items-center gap-1">
                                <x-icon name="pin" class="size-3.5 shrink-0" />
                                <span class="truncate">{{ $event->location }}</span>
                            </span>
                        @endif
                    </span>
                </span>

                @if ($event->status === 'tentative')
                    <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300">Tentative</span>
                @endif
            </button>
        @empty
            @if ($dayTasks->isEmpty())
            <div class="flex flex-col items-center px-4 py-12 text-center">
                <span class="grid size-12 place-items-center rounded-2xl bg-ink-100 text-ink-400 dark:bg-ink-800">
                    <x-icon name="empty" class="size-6" />
                </span>
                <p class="mt-4 text-[15px] font-bold">Nothing on this day</p>
                <p class="mt-1 text-[13px] text-ink-400">Add an event and it will show up on the calendar.</p>
                <button type="button" wire:click="createEvent('{{ $selectedDate->format('Y-m-d') }}')"
                        class="mt-5 rounded-xl bg-brand-600 px-5 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                    Add event
                </button>
            </div>
            @endif
        @endforelse
    </section>

    @if ($activeDepartment && $calendarList->isEmpty())
        <p class="pb-2 text-[13px] text-ink-400">
            {{ $activeDepartment->name }} has no calendars you can see yet.
            <a href="{{ route('departments.index') }}" class="font-bold text-brand-600 hover:underline">Manage departments</a>
        </p>
    @endif

    {{-- Calendar filter chips — mirrors the sidebar, but toggleable in place. --}}
    @if ($calendarList->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 pb-2">
            <span class="text-[13px] font-semibold text-ink-400">
                {{ $activeDepartment ? "In {$activeDepartment->name}" : 'Showing' }}
            </span>
            @foreach ($calendarList as $calendar)
                @php $active = empty($selected) || in_array($calendar->id, $selected, true); @endphp
                <button type="button" wire:click="toggleCalendar({{ $calendar->id }})"
                        class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-[13px] font-semibold transition
                               {{ $active
                                   ? 'border-ink-200 bg-white text-ink-700 shadow-sm dark:border-ink-700 dark:bg-ink-900 dark:text-ink-100'
                                   : 'border-transparent bg-ink-100 text-ink-400 dark:bg-ink-800' }}">
                    <span class="size-2 rounded-full transition" style="background-color: {{ $active ? $calendar->color : 'transparent' }}; box-shadow: inset 0 0 0 1.5px {{ $calendar->color }}"></span>
                    {{ $calendar->name }}
                </button>
            @endforeach
            @if ($selected)
                <button type="button" wire:click="$set('selected', [])" class="text-[13px] font-bold text-brand-600 hover:underline">Reset</button>
            @endif
        </div>
    @endif

    {{-- ──────────────────────────────── Modal ───────────────────────────────── --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 grid place-items-end bg-ink-950/50 p-0 backdrop-blur-[2px] sm:place-items-center sm:p-4"
             wire:keydown.escape="$set('showModal', false)">
            <div class="max-h-[92vh] w-full max-w-lg overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl dark:bg-ink-900">
                <form wire:submit="saveEvent">
                    <header class="flex items-center justify-between px-6 pt-5 pb-4">
                        <h2 class="text-lg font-bold tracking-tight">{{ $editingId ? 'Edit event' : 'New event' }}</h2>
                        <button type="button" wire:click="$set('showModal', false)" aria-label="Close"
                                class="grid size-8 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">✕</button>
                    </header>

                    @php
                        $field = 'w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-ink-300 focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950';
                        $label = 'mb-1.5 block text-[13px] font-bold text-ink-600 dark:text-ink-300';
                        $error = 'mt-1.5 text-[13px] font-medium text-red-600';
                    @endphp

                    <div class="max-h-[65vh] space-y-4 overflow-y-auto px-6 pb-2">
                        <div>
                            <label class="{{ $label }}">Title</label>
                            <input type="text" wire:model="form_title" autofocus placeholder="Team sync" class="{{ $field }}">
                            @error('form_title') <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $label }}">Calendar</label>
                            <select wire:model="form_calendar_id" class="{{ $field }}">
                                <option value="">Select a calendar…</option>
                                @foreach ($calendarList as $calendar)
                                    <option value="{{ $calendar->id }}" @disabled(! $calendar->isWritableBy(auth()->user()))>
                                        {{ $calendar->name }}{{ $calendar->isWritableBy(auth()->user()) ? '' : ' (read-only)' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('form_calendar_id') <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="{{ $label }}">Starts</label>
                                <input type="datetime-local" wire:model="form_starts_at" class="{{ $field }}">
                                @error('form_starts_at') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $label }}">Ends</label>
                                <input type="datetime-local" wire:model="form_ends_at" class="{{ $field }}">
                                @error('form_ends_at') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-ink-200 px-3.5 py-2.5 text-[14px] font-semibold dark:border-ink-700">
                                <input type="checkbox" wire:model="form_all_day" class="size-4 rounded border-ink-300 text-brand-600 focus:ring-brand-400">
                                All day
                            </label>
                            <select wire:model="form_status" class="{{ $field }} w-auto flex-1">
                                <option value="confirmed">Confirmed</option>
                                <option value="tentative">Tentative</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>

                        <div>
                            <label class="{{ $label }}">Location</label>
                            <input type="text" wire:model="form_location" placeholder="Phnom Penh HQ" class="{{ $field }}">
                        </div>

                        <div>
                            <label class="{{ $label }}">Description</label>
                            <textarea wire:model="form_description" rows="3" class="{{ $field }}"></textarea>
                        </div>

                        <div>
                            <label class="{{ $label }}">Invite guests <span class="font-medium text-ink-400">(comma-separated)</span></label>
                            <input type="text" wire:model="form_invitees" placeholder="sok@example.com, dara@example.com" class="{{ $field }}">
                        </div>
                    </div>

                    <footer class="mt-2 flex items-center gap-3 border-t border-ink-200/80 px-6 py-4 dark:border-ink-800">
                        @if ($editingId)
                            <button type="button" wire:click="deleteEvent" wire:confirm="Delete this event?"
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
                                {{ $editingId ? 'Save changes' : 'Create event' }}
                            </button>
                        </div>
                    </footer>
                </form>
            </div>
        </div>
    @endif
</div>

    {{-- ────────────────────────── Task detail sidebar ───────────────────────── --}}
    @if ($activeTask)
        <x-task-detail :task="$activeTask"
                       :status-meta="$statusMeta"
                       :priority-meta="$priorityMeta"
                       :timezone="$timezone"
                       :departments="$departmentList"
                       :members="$memberList"
                       :subtasks="$subtasks"
                       :checklist="$checklist" />
    @endif
</div>
