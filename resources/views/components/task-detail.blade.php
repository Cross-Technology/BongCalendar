@props(['task', 'statusMeta', 'priorityMeta', 'timezone', 'departments', 'members', 'subtasks', 'checklist', 'activity' => null])

@php
    $status = $statusMeta[$task->status];
    $priority = $priorityMeta[$task->priority];
    $overdue = $task->isOverdue();

    $tile = 'flex flex-col gap-1 rounded-xl border border-ink-200 px-3 py-2.5 text-left transition hover:border-ink-300 dark:border-ink-700 dark:hover:border-ink-600';
    $tileLabel = 'text-[11px] font-bold uppercase tracking-wider text-ink-400';
    $field = 'w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-[14px] outline-none transition placeholder:text-ink-300 focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950';
@endphp

{{-- Backdrop, drawer-only: on xl the panel is a column, not an overlay. --}}
<div wire:click="closeTask" class="fixed inset-0 z-30 bg-ink-950/40 backdrop-blur-[2px] xl:hidden"></div>

<aside wire:key="task-detail-{{ $task->id }}"
       aria-label="Task details"
       class="rise fixed inset-y-0 right-0 z-40 flex w-full max-w-md flex-col overflow-y-auto border-l border-ink-200 bg-white
              xl:static xl:z-auto xl:max-h-[calc(100vh-3.5rem)] xl:w-[23rem] xl:max-w-none xl:shrink-0 xl:rounded-2xl xl:border
              dark:border-ink-800 dark:bg-ink-900">

    <header class="sticky top-0 z-10 flex items-center gap-2 border-b border-ink-200/80 bg-white/90 px-4 py-3 backdrop-blur dark:border-ink-800 dark:bg-ink-900/90">
        <button type="button" wire:click="toggleTask({{ $task->id }})"
                role="checkbox" aria-checked="{{ $task->isDone() ? 'true' : 'false' }}"
                aria-label="{{ $task->isDone() ? 'Reopen' : 'Complete' }} {{ $task->title }}"
                class="grid size-5 shrink-0 place-items-center rounded-full border-2 transition"
                style="{{ $task->isDone()
                    ? 'background-color: '.$statusMeta['done']['color'].'; border-color: '.$statusMeta['done']['color']
                    : 'border-color: '.$status['color'] }}">
            @if ($task->isDone())
                <svg class="size-3 text-white" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m2.5 6.5 2.5 2.5 4.5-5"/></svg>
            @endif
        </button>

        <span class="text-[13px] font-bold text-ink-400">Task</span>

        <button type="button" wire:click="deleteTask({{ $task->id }})" wire:confirm="Delete this task?"
                class="ml-auto rounded-lg px-2 py-1 text-[12px] font-bold text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950">
            Delete
        </button>
        <button type="button" wire:click="closeTask" aria-label="Close details"
                class="grid size-8 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">✕</button>
    </header>

    <div class="flex flex-col gap-5 px-4 py-4">
        {{-- Title: edits commit on blur, as they do on mobile. --}}
        <textarea wire:model.blur="detail_title" rows="2" aria-label="Task title"
                  class="w-full resize-none border-0 bg-transparent p-0 text-[20px] font-extrabold leading-tight tracking-tight outline-none
                         {{ $task->isDone() ? 'text-ink-400 line-through' : '' }}"></textarea>
        @error('detail_title') <p class="-mt-3 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror

        {{-- Status · Priority · Due, the three tiles from the mobile detail --}}
        <div class="grid grid-cols-2 gap-2">
            <div x-data="{ open: false }" class="relative">
                <button type="button" x-on:click="open = !open" class="{{ $tile }} w-full">
                    <span class="{{ $tileLabel }}">Status</span>
                    <span class="flex items-center gap-1.5 text-[14px] font-bold" style="color: {{ $status['color'] }}">
                        <span class="size-2 rounded-full" style="background-color: {{ $status['color'] }}"></span>
                        {{ $status['label'] }}
                    </span>
                </button>

                <div x-show="open" x-on:click.outside="open = false" x-transition x-cloak
                     class="absolute left-0 right-0 top-full z-20 mt-1 flex flex-col rounded-xl border border-ink-200 bg-white p-1 shadow-lg dark:border-ink-700 dark:bg-ink-800">
                    @foreach ($statusMeta as $value => $meta)
                        <button type="button" x-on:click="open = false" wire:click="setTaskStatus({{ $task->id }}, '{{ $value }}')"
                                class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px] font-semibold transition hover:bg-ink-100 dark:hover:bg-ink-700">
                            <span class="size-2 rounded-full" style="background-color: {{ $meta['color'] }}"></span>
                            {{ $meta['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div x-data="{ open: false }" class="relative">
                <button type="button" x-on:click="open = !open" class="{{ $tile }} w-full">
                    <span class="{{ $tileLabel }}">Priority</span>
                    <span class="flex items-center gap-1.5 text-[14px] font-bold" style="color: {{ $priority['color'] }}">
                        <span class="size-2 rounded-full" style="background-color: {{ $priority['color'] }}"></span>
                        {{ $priority['label'] }}
                    </span>
                </button>

                <div x-show="open" x-on:click.outside="open = false" x-transition x-cloak
                     class="absolute left-0 right-0 top-full z-20 mt-1 flex flex-col rounded-xl border border-ink-200 bg-white p-1 shadow-lg dark:border-ink-700 dark:bg-ink-800">
                    @foreach ($priorityMeta as $value => $meta)
                        <button type="button" x-on:click="open = false" wire:click="setTaskPriority({{ $task->id }}, '{{ $value }}')"
                                class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px] font-semibold transition hover:bg-ink-100 dark:hover:bg-ink-700">
                            <span class="size-2 rounded-full" style="background-color: {{ $meta['color'] }}"></span>
                            {{ $meta['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Start: the day the work is scheduled for, which is where the
                 calendar puts it. Distinct from the deadline below. --}}
            <label class="{{ $tile }} col-span-2 cursor-pointer">
                <span class="{{ $tileLabel }}">Start date</span>
                <span class="flex items-center gap-2">
                    <input type="datetime-local" wire:model.blur="detail_start" aria-label="Start date"
                           class="w-full border-0 bg-transparent p-0 text-[14px] font-bold outline-none">
                    @if ($task->start_date)
                        <button type="button" wire:click="clearTaskStart({{ $task->id }})" aria-label="Clear start date"
                                class="shrink-0 text-[12px] font-bold text-ink-400 hover:text-ink-700">Clear</button>
                    @endif
                </span>
            </label>

            <label class="{{ $tile }} col-span-2 cursor-pointer">
                <span class="{{ $tileLabel }}">Due date</span>
                <span class="flex items-center gap-2">
                    <input type="datetime-local" wire:model.blur="detail_due" aria-label="Due date"
                           class="w-full border-0 bg-transparent p-0 text-[14px] font-bold outline-none {{ $overdue ? 'text-red-600' : '' }}">
                    @if ($task->due_date)
                        <button type="button" wire:click="clearTaskDue({{ $task->id }})" aria-label="Clear due date"
                                class="shrink-0 text-[12px] font-bold text-ink-400 hover:text-ink-700">Clear</button>
                    @endif
                </span>
                @if ($overdue)
                    <span class="text-[12px] font-bold text-red-600">Overdue</span>
                @endif
            </label>
        </div>

        {{-- Departments · Assignees: as many of each as apply. Shared work is
             one task on both boards, not a copy for each team. --}}
        <div class="grid gap-2">
            <span class="{{ $tileLabel }}">Departments</span>
            <x-multi-select
                model="detail_department_ids"
                :selected="$task->departments->pluck('id')"
                commit-on-close
                :options="$departments->map(fn ($department) => ['id' => $department->id, 'label' => $department->name, 'color' => $department->color])"
                placeholder="No department"
                search-placeholder="Search departments…"
                empty="No departments yet" />

            <span class="{{ $tileLabel }} mt-1">Assignees</span>
            <x-multi-select
                model="detail_assignee_ids"
                :selected="$task->assignees->pluck('id')"
                commit-on-close
                :options="$members->map(fn ($member) => ['id' => $member->id, 'label' => $member->name])"
                placeholder="Unassigned"
                search-placeholder="Search people…"
                empty="No members yet" />
        </div>

        <div>
            <h3 class="{{ $tileLabel }} mb-1.5">Description</h3>
            <textarea wire:model.blur="detail_description" rows="4"
                      placeholder="Add more detail."
                      class="{{ $field }} min-h-24 leading-relaxed"></textarea>
        </div>

        {{-- The note is deliberately unlike Description — tinted, accent edge —
             so the two never read as one field split in half. --}}
        <div>
            <h3 class="{{ $tileLabel }} mb-1.5">Note</h3>
            <div class="overflow-hidden rounded-xl border-l-[3px] border-brand-500 bg-brand-50/60 dark:bg-brand-950/40">
                <textarea wire:model.blur="detail_note" rows="3"
                          placeholder="Jot down anything you need to remember about this task."
                          class="w-full resize-none border-0 bg-transparent p-3.5 text-[14px] leading-relaxed outline-none placeholder:text-ink-400"></textarea>
            </div>
        </div>

        {{-- Checklist: the small stuff inside one task, ticked off in place. --}}
        <div>
            <h3 class="{{ $tileLabel }} mb-1.5">
                Checklist
                @if ($checklist->isNotEmpty())
                    <span class="text-ink-400">· {{ $checklist->where('completed', true)->count() }} of {{ $checklist->count() }} completed</span>
                @endif
            </h3>

            <div class="rounded-xl border border-ink-200 px-3 py-1 dark:border-ink-700">
                @foreach ($checklist as $index => $item)
                    <div wire:key="check-{{ $item->id }}" class="group flex items-center gap-2.5 py-1.5">
                        <button type="button" wire:click="toggleChecklistItem({{ $item->id }})"
                                role="checkbox" aria-checked="{{ $item->completed ? 'true' : 'false' }}"
                                aria-label="{{ $item->title }}"
                                class="grid size-[18px] shrink-0 place-items-center rounded-full border-2 transition"
                                style="{{ $item->completed
                                    ? 'background-color: '.$statusMeta['done']['color'].'; border-color: '.$statusMeta['done']['color']
                                    : 'border-color: var(--color-ink-300)' }}">
                            @if ($item->completed)
                                <svg class="size-2.5 text-white" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m2.5 6.5 2.5 2.5 4.5-5"/></svg>
                            @endif
                        </button>

                        <span class="min-w-0 flex-1 text-[14px] {{ $item->completed ? 'text-ink-400 line-through' : '' }}">
                            {{ $item->title }}
                        </span>

                        {{-- Reorder controls, as on mobile: dragging is never the only way. --}}
                        <button type="button" wire:click="moveChecklistItem({{ $item->id }}, -1)"
                                aria-label="Move {{ $item->title }} up" @disabled($loop->first)
                                class="grid size-6 shrink-0 place-items-center rounded text-ink-400 transition hover:bg-ink-100 disabled:opacity-25 dark:hover:bg-ink-700">
                            <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m4 10 4-4 4 4"/></svg>
                        </button>
                        <button type="button" wire:click="moveChecklistItem({{ $item->id }}, 1)"
                                aria-label="Move {{ $item->title }} down" @disabled($loop->last)
                                class="grid size-6 shrink-0 place-items-center rounded text-ink-400 transition hover:bg-ink-100 disabled:opacity-25 dark:hover:bg-ink-700">
                            <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m4 6 4 4 4-4"/></svg>
                        </button>
                        <button type="button" wire:click="deleteChecklistItem({{ $item->id }})"
                                aria-label="Delete {{ $item->title }}"
                                class="grid size-6 shrink-0 place-items-center rounded text-ink-300 transition hover:text-red-600">✕</button>
                    </div>
                @endforeach

                <form wire:submit="addChecklistItem" class="flex items-center gap-2.5 py-1.5">
                    <x-icon name="plus" class="size-4 shrink-0 text-ink-400" />
                    <input type="text" wire:model="newChecklistTitle" placeholder="Add item"
                           aria-label="Add checklist item"
                           class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[14px] outline-none placeholder:text-ink-400">
                </form>
            </div>
            @error('newChecklistTitle') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Subtasks are one level deep, so a subtask shows none. --}}
        @if ($task->parent_task_id === null)
            <div>
                <h3 class="{{ $tileLabel }} mb-1.5">
                    Subtasks
                    @if ($subtasks->isNotEmpty())
                        <span class="text-ink-400">· {{ $subtasks->where('status', 'done')->count() }}/{{ $subtasks->count() }}</span>
                    @endif
                </h3>

                <ul class="mb-2 space-y-1">
                    @foreach ($subtasks as $subtask)
                        <li wire:key="subtask-{{ $subtask->id }}" class="flex items-center gap-2.5 rounded-lg px-1.5 py-1.5 transition hover:bg-ink-50 dark:hover:bg-ink-800">
                            <button type="button" wire:click="toggleTask({{ $subtask->id }})"
                                    role="checkbox" aria-checked="{{ $subtask->isDone() ? 'true' : 'false' }}"
                                    aria-label="{{ $subtask->isDone() ? 'Reopen' : 'Complete' }} {{ $subtask->title }}"
                                    class="grid size-4 shrink-0 place-items-center rounded-full border-2 transition"
                                    style="{{ $subtask->isDone()
                                        ? 'background-color: '.$statusMeta['done']['color'].'; border-color: '.$statusMeta['done']['color']
                                        : 'border-color: var(--color-ink-300)' }}">
                                @if ($subtask->isDone())
                                    <svg class="size-2 text-white" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m2.5 6.5 2.5 2.5 4.5-5"/></svg>
                                @endif
                            </button>
                            <span class="min-w-0 flex-1 truncate text-[14px] {{ $subtask->isDone() ? 'text-ink-400 line-through' : '' }}">
                                {{ $subtask->title }}
                            </span>
                            <button type="button" wire:click="deleteTask({{ $subtask->id }})" aria-label="Delete {{ $subtask->title }}"
                                    class="shrink-0 text-[12px] text-ink-300 transition hover:text-red-600">✕</button>
                        </li>
                    @endforeach
                </ul>

                <form wire:submit="addSubtask" class="flex items-center gap-2">
                    <input type="text" wire:model="newSubtaskTitle" placeholder="Add a subtask…" class="{{ $field }} flex-1">
                    <button type="submit" class="shrink-0 rounded-xl border border-ink-200 px-3 py-2.5 text-[13px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                        Add
                    </button>
                </form>
                @error('newSubtaskTitle') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
            </div>
        @else
            <p class="flex items-center gap-2 text-[13px] text-ink-400">
                <x-icon name="stack" class="size-4" />
                This is a subtask.
            </p>
        @endif

        {{-- Who made this and who has touched it since. The byline is read off
             the task's own columns so it answers for tasks written before the
             trail existed; the entries behind it are the trail itself. --}}
        <x-activity-log :entries="$activity ?? $task->activities()->with('user')->get()"
                        :timezone="$timezone"
                        noun="task">
            <span class="flex items-center gap-1.5">
                <span class="grid size-5 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[9px] font-bold text-white">
                    {{ \Illuminate\Support\Str::of($task->creator?->name ?? '?')->substr(0, 1)->upper() }}
                </span>
                Created by
                <span class="font-semibold text-ink-600 dark:text-ink-300">{{ $task->creator?->name ?? 'Unknown' }}</span>
            </span>
            <span title="{{ $task->created_at->setTimezone($timezone)->format('l, j F Y, H:i') }}">
                · {{ $task->created_at->setTimezone($timezone)->format('j M Y, H:i') }}
            </span>
            @if ($task->completed_at)
                <span>· Completed {{ $task->completed_at->setTimezone($timezone)->format('j M Y, H:i') }}</span>
            @endif
        </x-activity-log>
    </div>
</aside>
