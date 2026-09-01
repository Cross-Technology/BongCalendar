<?php

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Calendar;
use App\Models\CalendarShare;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Calendars — BongCalendar')]
class extends Component
{
    use InteractsWithTenant;

    public bool $showModal = false;

    public ?int $editingId = null;

    public string $form_name = '';

    public string $form_description = '';

    public string $form_color = '#2563eb';

    public string $form_visibility = 'private';

    /** Calendar whose sharing panel is open. */
    public ?int $sharingId = null;

    public string $share_email = '';

    public string $share_permission = 'view';

    public function mount(): void
    {
        $this->requireTenant();
    }

    /** @return Collection<int, Calendar> */
    public function calendars(): Collection
    {
        return Calendar::visibleTo($this->currentUser(), $this->requireTenant()->id)
            ->withCount('events')
            ->with(['owner:id,name,email', 'shares.user:id,name,email'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /* -------------------------------------------------------------- create */

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form_name = '';
        $this->form_description = '';
        $this->form_color = '#2563eb';
        $this->form_visibility = 'private';
        $this->showModal = true;
    }

    public function edit(int $calendarId): void
    {
        $calendar = Calendar::findOrFail($calendarId);
        $this->authorize('update', $calendar);

        $this->resetValidation();
        $this->editingId = $calendar->id;
        $this->form_name = $calendar->name;
        $this->form_description = (string) $calendar->description;
        $this->form_color = $calendar->color;
        $this->form_visibility = $calendar->visibility;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'form_name' => ['required', 'string', 'max:255'],
            'form_description' => ['nullable', 'string', 'max:2000'],
            'form_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form_visibility' => ['required', 'in:private,tenant,public'],
        ], attributes: ['form_name' => 'name', 'form_color' => 'color', 'form_visibility' => 'visibility']);

        $attributes = [
            'name' => $data['form_name'],
            'description' => $data['form_description'] ?: null,
            'color' => $data['form_color'],
            'visibility' => $data['form_visibility'],
        ];

        if ($this->editingId) {
            $calendar = Calendar::findOrFail($this->editingId);
            $this->authorize('update', $calendar);
            $calendar->update($attributes);
            session()->flash('status', 'Calendar updated.');
        } else {
            $tenant = $this->requireTenant();
            Calendar::create($attributes + [
                'tenant_id' => $tenant->id,
                'owner_id' => $this->currentUser()->id,
                'timezone' => $tenant->timezone,
            ]);
            session()->flash('status', 'Calendar created.');
        }

        $this->showModal = false;
    }

    public function delete(int $calendarId): void
    {
        $calendar = Calendar::findOrFail($calendarId);
        $this->authorize('delete', $calendar);

        $calendar->delete();

        session()->flash('status', 'Calendar deleted.');
    }

    /* --------------------------------------------------------------- share */

    public function openSharing(int $calendarId): void
    {
        $calendar = Calendar::findOrFail($calendarId);
        $this->authorize('share', $calendar);

        $this->resetValidation();
        $this->sharingId = $calendar->id;
        $this->share_email = '';
        $this->share_permission = 'view';
    }

    public function share(): void
    {
        $calendar = Calendar::findOrFail($this->sharingId);
        $this->authorize('share', $calendar);

        $data = $this->validate([
            'share_email' => ['required', 'email', 'exists:users,email'],
            'share_permission' => ['required', 'in:view,edit,manage'],
        ], attributes: ['share_email' => 'email', 'share_permission' => 'permission']);

        $user = User::where('email', strtolower($data['share_email']))->firstOrFail();

        if ($user->id === $calendar->owner_id) {
            $this->addError('share_email', 'The owner already has full access.');

            return;
        }

        if (! $user->belongsToTenant($calendar->tenant_id)) {
            $this->addError('share_email', 'That user is not a member of this workspace.');

            return;
        }

        CalendarShare::updateOrCreate(
            ['calendar_id' => $calendar->id, 'user_id' => $user->id],
            ['permission' => $data['share_permission'], 'invited_by' => $this->currentUser()->id],
        );

        $this->share_email = '';
        session()->flash('status', "Shared with {$user->name}.");
    }

    public function revoke(int $calendarId, int $userId): void
    {
        $calendar = Calendar::findOrFail($calendarId);
        $this->authorize('share', $calendar);

        $calendar->shares()->where('user_id', $userId)->delete();

        session()->flash('status', 'Access revoked.');
    }

    public function with(): array
    {
        return ['calendarList' => $this->calendars()];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Calendars</h1>
            <p class="text-sm text-ink-500">In {{ $this->currentTenant()?->name }}</p>
        </div>
        <button wire:click="create"
                class="ml-auto rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
            + New calendar
        </button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($calendarList as $calendar)
            @php $permission = $calendar->permissionFor(auth()->user()); @endphp
            <article class="flex flex-col rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-900">
                <div class="flex items-start gap-3">
                    <span class="mt-1 size-3.5 shrink-0 rounded" style="background-color: {{ $calendar->color }}"></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-semibold">{{ $calendar->name }}</h2>
                        <p class="text-xs text-ink-500">
                            {{ $calendar->owner_id === auth()->id() ? 'Owned by you' : "Owned by {$calendar->owner->name}" }}
                            · {{ $calendar->events_count }} {{ Str::plural('event', $calendar->events_count) }}
                        </p>
                    </div>
                    @if ($calendar->is_default)
                        <span class="rounded bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300">DEFAULT</span>
                    @endif
                </div>

                @if ($calendar->description)
                    <p class="mt-3 text-sm text-ink-600 dark:text-ink-400">{{ $calendar->description }}</p>
                @endif

                <div class="mt-3 flex flex-wrap gap-1.5 text-[11px]">
                    <span class="rounded-full bg-ink-100 px-2 py-0.5 text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                        {{ ucfirst($calendar->visibility) }}
                    </span>
                    <span class="rounded-full bg-ink-100 px-2 py-0.5 text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                        Your access: {{ $permission }}
                    </span>
                </div>

                @if ($calendar->shares->isNotEmpty())
                    <ul class="mt-4 space-y-1.5 border-t border-ink-100 pt-3 text-sm dark:border-ink-800">
                        @foreach ($calendar->shares as $share)
                            <li class="flex items-center gap-2">
                                <span class="truncate">{{ $share->user->name }}</span>
                                <span class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] text-ink-500 dark:bg-ink-800">{{ $share->permission }}</span>
                                @can('share', $calendar)
                                    <button wire:click="revoke({{ $calendar->id }}, {{ $share->user_id }})"
                                            wire:confirm="Revoke access for {{ $share->user->name }}?"
                                            class="ml-auto text-xs text-red-600 hover:underline">Revoke</button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-auto flex flex-wrap gap-2 pt-4">
                    @can('update', $calendar)
                        <button wire:click="edit({{ $calendar->id }})"
                                class="rounded-lg border border-ink-300 px-3 py-1.5 text-sm font-medium hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">Edit</button>
                    @endcan
                    @can('share', $calendar)
                        <button wire:click="openSharing({{ $calendar->id }})"
                                class="rounded-lg border border-ink-300 px-3 py-1.5 text-sm font-medium hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">Share</button>
                    @endcan
                    @can('delete', $calendar)
                        <button wire:click="delete({{ $calendar->id }})" wire:confirm="Delete {{ $calendar->name }} and all of its events?"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950">Delete</button>
                    @endcan
                </div>
            </article>
        @endforeach
    </div>

    {{-- Create / edit modal --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 grid place-items-center bg-ink-900/50 p-4" wire:keydown.escape="$set('showModal', false)">
            <form wire:submit="save" class="w-full max-w-md rounded-2xl bg-white shadow-xl dark:bg-ink-900">
                <header class="flex items-center justify-between border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 class="text-base font-semibold">{{ $editingId ? 'Edit calendar' : 'New calendar' }}</h2>
                    <button type="button" wire:click="$set('showModal', false)" class="text-ink-400 hover:text-ink-600">✕</button>
                </header>

                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium">Name</label>
                        <input type="text" wire:model="form_name" autofocus
                               class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                        @error('form_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium">Description</label>
                        <textarea wire:model="form_description" rows="2"
                                  class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800"></textarea>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium">Colour</label>
                            <input type="color" wire:model="form_color"
                                   class="h-10 w-full rounded-lg border border-ink-300 dark:border-ink-700">
                            @error('form_color') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium">Visibility</label>
                            <select wire:model="form_visibility"
                                    class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                                <option value="private">Private — only shared people</option>
                                <option value="tenant">Workspace — all members can view</option>
                                <option value="public">Public</option>
                            </select>
                        </div>
                    </div>
                </div>

                <footer class="flex justify-end gap-2 border-t border-ink-200 px-5 py-4 dark:border-ink-800">
                    <button type="button" wire:click="$set('showModal', false)"
                            class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-medium dark:border-ink-700">Cancel</button>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        {{ $editingId ? 'Save changes' : 'Create calendar' }}
                    </button>
                </footer>
            </form>
        </div>
    @endif

    {{-- Sharing panel --}}
    @if ($sharingId)
        <div class="fixed inset-0 z-50 grid place-items-center bg-ink-900/50 p-4" wire:keydown.escape="$set('sharingId', null)">
            <form wire:submit="share" class="w-full max-w-md rounded-2xl bg-white shadow-xl dark:bg-ink-900">
                <header class="flex items-center justify-between border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 class="text-base font-semibold">Share calendar</h2>
                    <button type="button" wire:click="$set('sharingId', null)" class="text-ink-400 hover:text-ink-600">✕</button>
                </header>

                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium">Workspace member's email</label>
                        <input type="email" wire:model="share_email" autofocus
                               class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                        @error('share_email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium">Permission</label>
                        <select wire:model="share_permission"
                                class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                            <option value="view">View — read events</option>
                            <option value="edit">Edit — add and change events</option>
                            <option value="manage">Manage — edit calendar and sharing</option>
                        </select>
                    </div>
                </div>

                <footer class="flex justify-end gap-2 border-t border-ink-200 px-5 py-4 dark:border-ink-800">
                    <button type="button" wire:click="$set('sharingId', null)"
                            class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-medium dark:border-ink-700">Done</button>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Share</button>
                </footer>
            </form>
        </div>
    @endif
</div>
