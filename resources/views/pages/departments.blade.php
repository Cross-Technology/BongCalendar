<?php

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Calendar;
use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Departments — BongCalendar')]
class extends Component
{
    use InteractsWithTenant;

    public bool $showModal = false;

    public ?int $editingId = null;

    public string $form_name = '';

    public string $form_description = '';

    public string $form_color = '#6f5cf0';

    /** Palette offered in the modal — the same hues the sidebar dots use. */
    public array $palette = ['#6f5cf0', '#2563eb', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#db2777', '#64748b'];

    /** Pending "add someone" selection, keyed by department id. */
    public array $newMember = [];

    public function mount(): void
    {
        $this->requireTenant();
    }

    /* ----------------------------------------------------------------- data */

    /**
     * Departments with only the calendars this user is allowed to see — a
     * department must never leak the names of someone else's private calendars.
     *
     * @return Collection<int, Department>
     */
    public function departments(): Collection
    {
        $tenantId = $this->requireTenant()->id;
        $user = $this->currentUser();
        $visibleIds = Calendar::visibleTo($user, $tenantId)->pluck('id');

        return Department::forTenant($tenantId)
            ->withCount([
                'calendars' => fn ($q) => $q->whereIn('calendars.id', $visibleIds),
                'events' => fn ($q) => $q->whereIn('events.calendar_id', $visibleIds),
            ])
            ->with([
                'calendars' => fn ($q) => $q->whereIn('calendars.id', $visibleIds)->orderBy('name'),
                'members' => fn ($q) => $q->orderBy('name'),
            ])
            ->get();
    }

    /** Calendars the user can see that sit outside every department. */
    public function ungrouped(): Collection
    {
        return Calendar::visibleTo($this->currentUser(), $this->requireTenant()->id)
            ->whereNull('department_id')
            ->orderBy('name')
            ->get();
    }

    public function canManage(): bool
    {
        return $this->currentUser()->isTenantAdmin($this->requireTenant()->id);
    }

    /* ----------------------------------------------------------------- form */

    public function create(): void
    {
        $this->authorize('create', [Department::class, $this->requireTenant()->id]);

        $this->resetValidation();
        $this->editingId = null;
        $this->form_name = '';
        $this->form_description = '';
        $this->form_color = $this->palette[0];
        $this->showModal = true;
    }

    public function edit(int $departmentId): void
    {
        $department = Department::findOrFail($departmentId);
        $this->authorize('update', $department);

        $this->resetValidation();
        $this->editingId = $department->id;
        $this->form_name = $department->name;
        $this->form_description = (string) $department->description;
        $this->form_color = $department->color;
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenantId = $this->requireTenant()->id;

        $data = $this->validate([
            'form_name' => [
                'required', 'string', 'max:255',
                Rule::unique('departments', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($this->editingId),
            ],
            'form_description' => ['nullable', 'string', 'max:2000'],
            'form_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], attributes: [
            'form_name' => 'name',
            'form_color' => 'color',
        ]);

        $payload = [
            'name' => $data['form_name'],
            'description' => $data['form_description'] ?: null,
            'color' => $data['form_color'],
        ];

        if ($this->editingId) {
            $department = Department::findOrFail($this->editingId);
            $this->authorize('update', $department);
            $department->update($payload);
            session()->flash('status', 'Department updated.');
        } else {
            $this->authorize('create', [Department::class, $tenantId]);
            Department::create($payload + ['tenant_id' => $tenantId]);
            session()->flash('status', 'Department created.');
        }

        $this->showModal = false;
    }

    /** Calendars survive: they simply become ungrouped again. */
    public function delete(int $departmentId): void
    {
        $department = Department::findOrFail($departmentId);
        $this->authorize('delete', $department);

        $department->calendars()->update(['department_id' => null]);
        $department->delete();

        session()->flash('status', 'Department deleted. Its calendars are now ungrouped.');
    }

    /* ------------------------------------------------------------- people */

    public function addMember(int $departmentId, DepartmentService $departments): void
    {
        $department = Department::forTenant($this->requireTenant()->id)->findOrFail($departmentId);

        $this->authorize('manageMembers', $department);

        $userId = (int) ($this->newMember[$departmentId] ?? 0);

        if (! $userId) {
            return;
        }

        try {
            $departments->addMember($department, User::findOrFail($userId));
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->newMember[$departmentId] = '';
    }

    public function setMemberRole(int $departmentId, int $userId, string $role, DepartmentService $departments): void
    {
        $department = Department::forTenant($this->requireTenant()->id)->findOrFail($departmentId);

        $this->authorize('manageMembers', $department);

        try {
            $departments->addMember($department, User::findOrFail($userId), $role);
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function removeMember(int $departmentId, int $userId, DepartmentService $departments): void
    {
        $department = Department::forTenant($this->requireTenant()->id)->findOrFail($departmentId);

        $this->authorize('manageMembers', $department);

        $departments->removeMember($department, User::findOrFail($userId));
    }

    public function moveCalendar(int $calendarId, ?int $departmentId): void
    {
        $tenantId = $this->requireTenant()->id;

        $calendar = Calendar::where('tenant_id', $tenantId)->findOrFail($calendarId);
        $this->authorize('update', $calendar);

        if ($departmentId) {
            $department = Department::where('tenant_id', $tenantId)->findOrFail($departmentId);
            $this->authorize('update', $department);
        }

        $calendar->update(['department_id' => $departmentId]);

        session()->flash('status', "Moved {$calendar->name}.");
    }

    public function with(): array
    {
        return [
            'departmentList' => $this->departments(),
            'ungroupedCalendars' => $this->ungrouped(),
            'canManage' => $this->canManage(),
            'workspaceMembers' => $this->currentTenant()->users()->orderBy('name')->get(['users.id', 'users.name']),
        ];
    }
};
?>

<div class="mx-auto flex max-w-6xl flex-col gap-5">
    <header class="flex flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="text-3xl font-extrabold tracking-tight">Departments</h1>
            <p class="mt-1.5 text-[15px] text-ink-500 dark:text-ink-400">
                Group the calendars in {{ $this->currentTenant()->name }} so the sidebar reads like your org.
            </p>
        </div>

        @if ($canManage)
            <button type="button" wire:click="create"
                    class="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                <x-icon name="plus" class="size-4" />
                New department
            </button>
        @endif
    </header>

    @forelse ($departmentList as $department)
        <section class="panel p-4 shadow-sm shadow-ink-900/[0.03] sm:p-5" wire:key="dept-{{ $department->id }}">
            <header class="flex flex-wrap items-center gap-3">
                <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $department->color }}"></span>

                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-[17px] font-bold tracking-tight">{{ $department->name }}</h2>
                    <p class="text-[13px] text-ink-400">
                        {{ $department->calendars_count }} {{ Str::plural('calendar', $department->calendars_count) }}
                        · {{ $department->events_count }} {{ Str::plural('event', $department->events_count) }}
                        @if ($department->description) · {{ $department->description }} @endif
                    </p>
                </div>

                <a href="{{ route('dashboard', ['department' => $department->id]) }}"
                   class="rounded-xl border border-ink-200 px-3 py-1.5 text-[13px] font-bold text-ink-700 transition hover:border-brand-300 hover:text-brand-700 dark:border-ink-700 dark:text-ink-200">
                    View calendar
                </a>

                @if ($canManage)
                    <button type="button" wire:click="edit({{ $department->id }})"
                            class="rounded-xl border border-ink-200 px-3 py-1.5 text-[13px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                        Edit
                    </button>
                    <button type="button" wire:click="delete({{ $department->id }})"
                            wire:confirm="Delete this department? Its calendars stay, but become ungrouped."
                            class="rounded-xl px-3 py-1.5 text-[13px] font-bold text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950">
                        Delete
                    </button>
                @endif
            </header>

            {{-- Who works here. Scopes work rather than gating it: their tasks
                 default to this department and the Reports page leads with it,
                 but nothing is hidden from the rest of the workspace. --}}
            <div class="mt-3 border-t border-ink-200/70 pt-3 dark:border-ink-800">
                <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-400">
                    People
                    @if ($department->members->isNotEmpty())
                        <span class="font-medium normal-case tracking-normal">· {{ $department->members->count() }}</span>
                    @endif
                </h3>

                @if ($department->members->isNotEmpty())
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($department->members as $member)
                            <li class="flex items-center gap-2 rounded-full border border-ink-200 py-1 pl-1.5 pr-1.5 text-[13px] dark:border-ink-700"
                                wire:key="dept-{{ $department->id }}-member-{{ $member->id }}">
                                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[10px] font-bold text-white">
                                    {{ \Illuminate\Support\Str::of($member->name)->substr(0, 1)->upper() }}
                                </span>
                                <span class="font-semibold">{{ $member->name }}</span>

                                @if ($canManage)
                                    <select wire:change="setMemberRole({{ $department->id }}, {{ $member->id }}, $event.target.value)"
                                            aria-label="Role for {{ $member->name }} in {{ $department->name }}"
                                            class="rounded-full border-0 bg-ink-100 px-2 py-0.5 text-[11px] font-bold dark:bg-ink-800">
                                        <option value="member" @selected($member->pivot->role === 'member')>Member</option>
                                        <option value="lead" @selected($member->pivot->role === 'lead')>Lead</option>
                                    </select>
                                    <button type="button" wire:click="removeMember({{ $department->id }}, {{ $member->id }})"
                                            aria-label="Remove {{ $member->name }} from {{ $department->name }}"
                                            class="grid size-5 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-red-600 dark:hover:bg-ink-700">✕</button>
                                @elseif ($member->pivot->role === 'lead')
                                    <span class="rounded-full bg-ink-100 px-2 py-0.5 text-[11px] font-bold text-ink-500 dark:bg-ink-800 dark:text-ink-300">Lead</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-[13px] text-ink-400">Nobody in this department yet.</p>
                @endif

                @if ($canManage)
                    @php $available = $workspaceMembers->whereNotIn('id', $department->members->pluck('id')); @endphp

                    @if ($available->isNotEmpty())
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <label class="sr-only" for="add-member-{{ $department->id }}">Add someone to {{ $department->name }}</label>
                            <select id="add-member-{{ $department->id }}" wire:model="newMember.{{ $department->id }}"
                                    class="rounded-xl border border-ink-200 bg-white px-3 py-1.5 text-[13px] dark:border-ink-700 dark:bg-ink-800">
                                <option value="">Add someone…</option>
                                @foreach ($available as $candidate)
                                    <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="addMember({{ $department->id }})"
                                    class="rounded-xl bg-brand-600 px-3 py-1.5 text-[13px] font-bold text-white transition hover:bg-brand-700">
                                Add
                            </button>
                        </div>
                    @endif
                @endif
            </div>

            @if ($department->calendars->isNotEmpty())
                <ul class="mt-3 flex flex-wrap gap-2 border-t border-ink-200/70 pt-3 dark:border-ink-800">
                    @foreach ($department->calendars as $calendar)
                        <li class="flex items-center gap-2 rounded-full border border-ink-200 py-1.5 pl-3 pr-1.5 text-[13px] font-semibold dark:border-ink-700"
                            wire:key="dept-{{ $department->id }}-cal-{{ $calendar->id }}">
                            <span class="size-2 rounded-full" style="background-color: {{ $calendar->color }}"></span>
                            {{ $calendar->name }}
                            @if ($canManage)
                                <button type="button" wire:click="moveCalendar({{ $calendar->id }}, null)"
                                        aria-label="Remove {{ $calendar->name }} from {{ $department->name }}"
                                        class="grid size-5 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-700">✕</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 border-t border-ink-200/70 pt-3 text-[13px] text-ink-400 dark:border-ink-800">
                    No calendars in this department yet.
                </p>
            @endif
        </section>
    @empty
        <section class="panel flex flex-col items-center px-4 py-14 text-center shadow-sm shadow-ink-900/[0.03]">
            <span class="grid size-12 place-items-center rounded-2xl bg-ink-100 text-ink-400 dark:bg-ink-800">
                <x-icon name="building" class="size-6" />
            </span>
            <p class="mt-4 text-[15px] font-bold">No departments yet</p>
            <p class="mt-1 max-w-sm text-[13px] text-ink-400">
                Departments group calendars — Sales, Ops, Errands — and give the sidebar its shape.
            </p>
            @if ($canManage)
                <button type="button" wire:click="create"
                        class="mt-5 rounded-xl bg-brand-600 px-5 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                    Create the first one
                </button>
            @else
                <p class="mt-4 text-[13px] text-ink-400">Ask a workspace admin to create one.</p>
            @endif
        </section>
    @endforelse

    {{-- Ungrouped calendars, with a one-click home for each. --}}
    @if ($ungroupedCalendars->isNotEmpty())
        <section class="panel p-4 shadow-sm shadow-ink-900/[0.03] sm:p-5">
            <h2 class="text-[15px] font-bold tracking-tight">Ungrouped calendars</h2>
            <p class="text-[13px] text-ink-400">These sit outside every department.</p>

            <ul class="mt-3 space-y-1.5 border-t border-ink-200/70 pt-3 dark:border-ink-800">
                @foreach ($ungroupedCalendars as $calendar)
                    <li class="flex flex-wrap items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-ink-50 dark:hover:bg-ink-800"
                        wire:key="ungrouped-{{ $calendar->id }}">
                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $calendar->color }}"></span>
                        <span class="min-w-0 flex-1 truncate text-[15px] font-semibold">{{ $calendar->name }}</span>

                        @if ($canManage && $departmentList->isNotEmpty())
                            <select wire:change="moveCalendar({{ $calendar->id }}, $event.target.value)"
                                    aria-label="Move {{ $calendar->name }} into a department"
                                    class="rounded-xl border border-ink-200 bg-white px-3 py-1.5 text-[13px] font-semibold dark:border-ink-700 dark:bg-ink-800">
                                <option value="">Move to…</option>
                                @foreach ($departmentList as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Modal --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 grid place-items-end bg-ink-950/50 p-0 backdrop-blur-[2px] sm:place-items-center sm:p-4"
             wire:keydown.escape="$set('showModal', false)">
            <div class="w-full max-w-md overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl dark:bg-ink-900">
                <form wire:submit="save">
                    <header class="flex items-center justify-between px-6 pt-5 pb-4">
                        <h2 class="text-lg font-bold tracking-tight">{{ $editingId ? 'Edit department' : 'New department' }}</h2>
                        <button type="button" wire:click="$set('showModal', false)" aria-label="Close"
                                class="grid size-8 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">✕</button>
                    </header>

                    @php
                        $field = 'w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-ink-300 focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950';
                        $label = 'mb-1.5 block text-[13px] font-bold text-ink-600 dark:text-ink-300';
                    @endphp

                    <div class="space-y-4 px-6 pb-2">
                        <div>
                            <label class="{{ $label }}">Name</label>
                            <input type="text" wire:model="form_name" autofocus placeholder="Errands" class="{{ $field }}">
                            @error('form_name') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $label }}">Colour</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($palette as $swatch)
                                    <button type="button" wire:click="$set('form_color', '{{ $swatch }}')"
                                            aria-label="Use {{ $swatch }}"
                                            class="size-8 rounded-full transition {{ $form_color === $swatch ? 'ring-2 ring-ink-900 ring-offset-2 dark:ring-white dark:ring-offset-ink-900' : 'hover:scale-110' }}"
                                            style="background-color: {{ $swatch }}"></button>
                                @endforeach
                            </div>
                            @error('form_color') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $label }}">Description <span class="font-medium text-ink-400">(optional)</span></label>
                            <textarea wire:model="form_description" rows="2" class="{{ $field }}"></textarea>
                        </div>
                    </div>

                    <footer class="mt-2 flex items-center gap-2 border-t border-ink-200/80 px-6 py-4 dark:border-ink-800">
                        <div class="ml-auto flex gap-2">
                            <button type="button" wire:click="$set('showModal', false)"
                                    class="rounded-xl border border-ink-200 px-4 py-2.5 text-[14px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="rounded-xl bg-brand-600 px-5 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                                {{ $editingId ? 'Save changes' : 'Create department' }}
                            </button>
                        </div>
                    </footer>
                </form>
            </div>
        </div>
    @endif
</div>
