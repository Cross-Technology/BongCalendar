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
    public bool $showBlocked = false;

    public bool $showModal = false;

    public ?int $editingId = null;

    // Task form
    public string $form_title = '';

    public string $form_description = '';

    public string $form_note = '';

    public string $form_status = 'todo';

    public string $form_priority = 'medium';

    public ?int $form_department_id = null;

    public ?int $form_assignee_id = null;

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
            ->when($this->department, fn ($q) => $q->where('department_id', $this->department))
            ->when($this->assignee, fn ($q) => $q->where('assignee_id', $this->assignee))
            ->with(['assignee:id,name', 'department:id,name,color'])
            ->withCount('subtasks')
            ->boardOrder()
            ->get();
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
        $this->form_department_id = $this->department;
        $this->form_assignee_id = null;
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
        $this->form_department_id = $task->department_id;
        $this->form_assignee_id = $task->assignee_id;
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
            'form_department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'form_assignee_id' => [
                'nullable', 'integer',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            ],
            'form_start_date' => ['nullable', 'date'],
            'form_due_date' => ['nullable', 'date'],
            'form_repeat' => ['nullable', Rule::in(TaskRecurrenceService::FREQUENCIES)],
            'form_repeat_until' => ['nullable', 'date', 'required_with:form_repeat', 'after_or_equal:today'],
        ], attributes: [
            'form_title' => 'title',
            'form_due_date' => 'due date',
            'form_assignee_id' => 'assignee',
            'form_department_id' => 'department',
            'form_repeat' => 'repeat',
            'form_repeat_until' => 'repeat end date',
        ]);

        $payload = [
            'title' => $data['form_title'],
            'description' => $data['form_description'] ?: null,
            'note' => $data['form_note'] ?: null,
            'priority' => $data['form_priority'],
            'department_id' => $data['form_department_id'] ?: null,
            'assignee_id' => $data['form_assignee_id'] ?: null,
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
                $created++;
            }

            session()->flash('status', $created > 1
                ? "{$created} tasks created, one per date."
                : 'Task created.');
        }

        $this->showModal = false;
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

        // `blocked` sits outside the default flow, exactly as on mobile.
        $columns = [];
        foreach (Task::BOARD_STATUSES as $status) {
            $columns[$status] = $tasks->where('status', $status)->values();
        }

        return [
            'columns' => $columns,
            'blocked' => $tasks->where('status', 'blocked')->values(),
            'statusMeta' => Task::STATUS_META,
            'priorityMeta' => Task::PRIORITY_META,
            'departmentList' => Department::forTenant($tenantId)->get(),
            'memberList' => $this->currentTenant()->users()->orderBy('name')->get(['users.id', 'users.name']),
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

<div class="mx-auto flex max-w-7xl flex-col gap-5">
    <header class="flex flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="text-3xl font-extrabold tracking-tight">Tasks</h1>
            <p class="mt-1.5 text-[15px] text-ink-500 dark:text-ink-400">
                {{ collect($columns)->flatten()->count() + $blocked->count() }} in this board
                @if ($blocked->isNotEmpty())
                    · <span class="font-semibold" style="color: {{ $statusMeta['blocked']['color'] }}">{{ $blocked->count() }} blocked</span>
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="department" aria-label="Filter by department"
                    class="rounded-xl border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold dark:border-ink-700 dark:bg-ink-900">
                <option value="">All departments</option>
                @foreach ($departmentList as $dept)
                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="assignee" aria-label="Filter by assignee"
                    class="rounded-xl border border-ink-200 bg-white px-3 py-2 text-[13px] font-semibold dark:border-ink-700 dark:bg-ink-900">
                <option value="">Anyone</option>
                @foreach ($memberList as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>

            <button type="button" wire:click="create()"
                    class="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                <x-icon name="plus" class="size-4" />
                New task
            </button>
        </div>
    </header>

    {{-- Board --}}
    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($columns as $status => $items)
            <section class="panel flex flex-col p-3 shadow-sm shadow-ink-900/[0.03]" wire:key="col-{{ $status }}">
                <header class="mb-3 flex items-center gap-2 px-1">
                    <span class="size-2.5 rounded-full" style="background-color: {{ $statusMeta[$status]['color'] }}"></span>
                    <h2 class="text-[14px] font-bold tracking-tight">{{ $statusMeta[$status]['label'] }}</h2>
                    <span class="rounded-full bg-ink-100 px-2 py-0.5 text-[11px] font-bold text-ink-500 dark:bg-ink-800 dark:text-ink-300">
                        {{ $items->count() }}
                    </span>
                    <button type="button" wire:click="create('{{ $status }}')" aria-label="Add a task to {{ $statusMeta[$status]['label'] }}"
                            class="ml-auto grid size-7 place-items-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">
                        <x-icon name="plus" class="size-4" />
                    </button>
                </header>

                <div class="flex flex-col gap-2">
                    @forelse ($items as $task)
                        <article wire:key="task-{{ $task->id }}"
                                 class="rise group rounded-xl border border-ink-200/80 bg-white p-3 transition hover:border-ink-300 hover:shadow-sm dark:border-ink-800 dark:bg-ink-900">
                            <div class="flex items-start gap-2.5">
                                <button type="button" wire:click="toggleDone({{ $task->id }})"
                                        role="checkbox" aria-checked="{{ $task->isDone() ? 'true' : 'false' }}"
                                        aria-label="{{ $task->isDone() ? 'Reopen' : 'Complete' }} {{ $task->title }}"
                                        class="mt-0.5 grid size-[18px] shrink-0 place-items-center rounded-full border-2 transition"
                                        style="{{ $task->isDone()
                                            ? 'background-color: '.$statusMeta['done']['color'].'; border-color: '.$statusMeta['done']['color']
                                            : 'border-color: var(--color-ink-300)' }}">
                                    @if ($task->isDone())
                                        <svg class="size-2.5 text-white" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m2.5 6.5 2.5 2.5 4.5-5"/></svg>
                                    @endif
                                </button>

                                <button type="button" wire:click="edit({{ $task->id }})" class="min-w-0 flex-1 text-left">
                                    <span class="block text-[14px] font-semibold leading-snug {{ $task->isDone() ? 'text-ink-400 line-through' : '' }}">
                                        {{ $task->title }}
                                    </span>

                                    <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[12px]">
                                        <span class="inline-flex items-center gap-1 font-bold" style="color: {{ $priorityMeta[$task->priority]['color'] }}">
                                            <span class="size-1.5 rounded-full" style="background-color: {{ $priorityMeta[$task->priority]['color'] }}"></span>
                                            {{ $priorityMeta[$task->priority]['label'] }}
                                        </span>

                                        @if ($task->department)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-ink-100 px-2 py-0.5 font-semibold text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                                                <span class="size-1.5 rounded-full" style="background-color: {{ $task->department->color }}"></span>
                                                {{ $task->department->name }}
                                            </span>
                                        @endif

                                        @if ($task->due_date)
                                            <span class="inline-flex items-center gap-1 font-semibold {{ $task->isOverdue() ? 'text-red-600' : 'text-ink-400' }}">
                                                <x-icon name="clock" class="size-3" />
                                                {{ $task->due_date->setTimezone($timezone)->format('j M') }}
                                            </span>
                                        @endif

                                        @if ($task->subtasks_count)
                                            <span class="font-semibold text-ink-400">{{ $task->subtasks_count }} subtasks</span>
                                        @endif
                                    </span>
                                </button>

                                @if ($task->assignee)
                                    <span title="{{ $task->assignee->name }}"
                                          class="grid size-7 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[11px] font-bold text-white">
                                        {{ Str::of($task->assignee->name)->substr(0, 1)->upper() }}
                                    </span>
                                @endif
                            </div>

                            {{-- Status moves: the board's stand-in for dragging. --}}
                            <div class="mt-2.5 flex flex-wrap gap-1 border-t border-ink-200/70 pt-2 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100 dark:border-ink-800">
                                @foreach (\App\Models\Task::STATUSES as $option)
                                    @continue($option === $task->status)
                                    <button type="button" wire:click="setStatus({{ $task->id }}, '{{ $option }}')"
                                            class="rounded-lg px-2 py-1 text-[11px] font-bold transition hover:bg-ink-100 dark:hover:bg-ink-800"
                                            style="color: {{ $statusMeta[$option]['color'] }}">
                                        → {{ $statusMeta[$option]['short'] }}
                                    </button>
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-ink-200 px-3 py-6 text-center text-[13px] text-ink-400 dark:border-ink-800">
                            Nothing here.
                        </p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    {{-- Blocked lane, kept out of the flow but never hidden. --}}
    @if ($blocked->isNotEmpty())
        <section class="panel p-4 shadow-sm shadow-ink-900/[0.03]">
            <header class="mb-3 flex items-center gap-2">
                <span class="size-2.5 rounded-full" style="background-color: {{ $statusMeta['blocked']['color'] }}"></span>
                <h2 class="text-[14px] font-bold tracking-tight">Blocked</h2>
                <span class="rounded-full bg-ink-100 px-2 py-0.5 text-[11px] font-bold text-ink-500 dark:bg-ink-800 dark:text-ink-300">{{ $blocked->count() }}</span>
            </header>

            <ul class="flex flex-wrap gap-2">
                @foreach ($blocked as $task)
                    <li wire:key="blocked-{{ $task->id }}" class="flex items-center gap-2 rounded-full border border-ink-200 py-1.5 pl-3 pr-1.5 text-[13px] font-semibold dark:border-ink-700">
                        <button type="button" wire:click="edit({{ $task->id }})" class="truncate">{{ $task->title }}</button>
                        <button type="button" wire:click="setStatus({{ $task->id }}, 'todo')"
                                class="rounded-full px-2 py-0.5 text-[11px] font-bold text-ink-500 transition hover:bg-ink-100 dark:hover:bg-ink-700">
                            Unblock
                        </button>
                    </li>
                @endforeach
            </ul>
        </section>
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

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="{{ $label }}">Department</label>
                                <select wire:model="form_department_id" class="{{ $field }}">
                                    <option value="">None</option>
                                    @foreach ($departmentList as $dept)
                                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $label }}">Assignee</label>
                                <select wire:model="form_assignee_id" class="{{ $field }}">
                                    <option value="">Unassigned</option>
                                    @foreach ($memberList as $member)
                                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                                    @endforeach
                                </select>
                                @error('form_assignee_id') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
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
