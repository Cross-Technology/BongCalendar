<?php

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\EventInvitation;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Invitations — BongCalendar')]
class extends Component
{
    use InteractsWithTenant;

    public string $filter = 'pending';

    public function mount(): void
    {
        $this->requireTenant();
    }

    /** @return Collection<int, EventInvitation> */
    public function invitations(): Collection
    {
        return EventInvitation::query()
            ->where('user_id', $this->currentUser()->id)
            ->whereHas('event', fn ($q) => $q->where('tenant_id', $this->requireTenant()->id))
            ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
            ->with(['event.calendar:id,name,color', 'event.creator:id,name'])
            ->get()
            ->sortBy(fn (EventInvitation $invitation) => $invitation->event->starts_at)
            ->values();
    }

    /**
     * Workspace invitations addressed to this account. They are deliberately
     * not scoped to the active workspace: the whole point is that you are not
     * in that workspace yet.
     *
     * @return Collection<int, WorkspaceInvitation>
     */
    public function workspaceInvitations(): Collection
    {
        return WorkspaceInvitation::pending()
            ->forEmail($this->currentUser()->email)
            ->with(['tenant:id,name,slug', 'inviter:id,name,email'])
            ->latest()
            ->get();
    }

    public function acceptWorkspace(int $invitationId, WorkspaceService $workspaces): void
    {
        $invitation = $this->openWorkspaceInvitation($invitationId);

        if (! $invitation) {
            return;
        }

        $invitation->accept($this->currentUser(), $workspaces);

        session()->flash('status', "You joined {$invitation->tenant->name}.");
    }

    public function declineWorkspace(int $invitationId): void
    {
        $invitation = $this->openWorkspaceInvitation($invitationId);

        if (! $invitation) {
            return;
        }

        $invitation->decline();

        session()->flash('status', "Declined the invitation to {$invitation->tenant->name}.");
    }

    /** Loads an invitation only if it is this user's and still answerable. */
    protected function openWorkspaceInvitation(int $invitationId): ?WorkspaceInvitation
    {
        $invitation = WorkspaceInvitation::with('tenant')->findOrFail($invitationId);

        abort_unless(
            strcasecmp($invitation->email, $this->currentUser()->email) === 0,
            403,
            'This invitation is for someone else.'
        );

        if (! $invitation->isOpen()) {
            session()->flash('error', $invitation->isExpired()
                ? 'That invitation has expired. Ask for a new one.'
                : 'That invitation is no longer open.');

            return null;
        }

        return $invitation;
    }

    public function respond(int $invitationId, string $status): void
    {
        abort_unless(in_array($status, ['accepted', 'declined', 'tentative'], true), 422);

        $invitation = EventInvitation::where('user_id', $this->currentUser()->id)->findOrFail($invitationId);

        $invitation->update(['status' => $status, 'responded_at' => now()]);

        session()->flash('status', 'Response saved.');
    }

    public function with(): array
    {
        return [
            'invitationList' => $this->invitations(),
            'workspaceInvites' => $this->workspaceInvitations(),
        ];
    }
};
?>

<div class="space-y-6">
    @if ($workspaceInvites->isNotEmpty())
        <section class="rounded-2xl border border-brand-200 bg-brand-50/60 p-4 dark:border-brand-900 dark:bg-brand-950/40">
            <h2 class="text-sm font-bold tracking-tight">Workspace invitations</h2>
            <p class="mt-0.5 text-xs text-ink-500 dark:text-ink-400">You join only once you accept.</p>

            <ul class="mt-3 space-y-2">
                @foreach ($workspaceInvites as $invite)
                    <li wire:key="ws-invite-{{ $invite->id }}"
                        class="flex flex-wrap items-center gap-3 rounded-xl border border-ink-200 bg-white p-3 dark:border-ink-800 dark:bg-ink-900">
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-brand-600 text-sm font-bold text-white">
                            {{ \Illuminate\Support\Str::of($invite->tenant->name)->substr(0, 1)->upper() }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-bold">{{ $invite->tenant->name }}</span>
                            <span class="block truncate text-xs text-ink-500">
                                {{ $invite->inviter?->name ?? 'Someone' }} invited you as {{ $invite->role }}@if ($invite->expires_at) · expires {{ $invite->expires_at->diffForHumans() }}@endif
                            </span>
                        </span>

                        <span class="flex gap-2">
                            <button wire:click="declineWorkspace({{ $invite->id }})"
                                    class="rounded-lg border border-ink-200 px-3 py-1.5 text-xs font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                                Decline
                            </button>
                            <button wire:click="acceptWorkspace({{ $invite->id }})"
                                    class="rounded-lg bg-brand-600 px-3.5 py-1.5 text-xs font-bold text-white transition hover:bg-brand-700">
                                Accept
                            </button>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Invitations</h1>
            <p class="text-sm text-ink-500">Events you've been invited to in {{ $this->currentTenant()?->name }}.</p>
        </div>

        <div class="ml-auto flex gap-1 rounded-lg border border-ink-200 p-1 dark:border-ink-800">
            @foreach (['pending' => 'Pending', 'accepted' => 'Accepted', 'declined' => 'Declined', 'all' => 'All'] as $value => $label)
                <button wire:click="$set('filter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition
                               {{ $filter === $value ? 'bg-ink-900 text-white dark:bg-white dark:text-ink-900' : 'text-ink-600 hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @forelse ($invitationList as $invitation)
        @php $event = $invitation->event; @endphp
        <article class="flex flex-wrap items-center gap-4 rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-900">
            <span class="h-10 w-1 rounded-full" style="background-color: {{ $event->displayColor() }}"></span>

            <div class="min-w-0 flex-1">
                <h2 class="truncate font-semibold">{{ $event->title }}</h2>
                <p class="text-sm text-ink-500">
                    {{ $event->starts_at->setTimezone($this->userTimezone())->format('D, j M Y · H:i') }}
                    – {{ $event->ends_at->setTimezone($this->userTimezone())->format('H:i') }}
                    · {{ $event->calendar->name }}
                    @if ($event->location) · {{ $event->location }} @endif
                </p>
                <p class="mt-0.5 text-xs text-ink-400">Invited by {{ $event->creator->name }}</p>
            </div>

            <div class="flex items-center gap-2">
                <span class="rounded-full px-2.5 py-1 text-xs font-medium
                             @class([
                                 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $invitation->status === 'pending',
                                 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $invitation->status === 'accepted',
                                 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300' => $invitation->status === 'declined',
                                 'bg-ink-100 text-ink-600 dark:bg-ink-800 dark:text-ink-300' => $invitation->status === 'tentative',
                             ])">
                    {{ ucfirst($invitation->status) }}
                </span>

                @if ($invitation->status !== 'accepted')
                    <button wire:click="respond({{ $invitation->id }}, 'accepted')"
                            class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Accept</button>
                @endif
                @if ($invitation->status !== 'tentative')
                    <button wire:click="respond({{ $invitation->id }}, 'tentative')"
                            class="rounded-lg border border-ink-300 px-3 py-1.5 text-sm font-medium hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">Maybe</button>
                @endif
                @if ($invitation->status !== 'declined')
                    <button wire:click="respond({{ $invitation->id }}, 'declined')"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950">Decline</button>
                @endif
            </div>
        </article>
    @empty
        <div class="rounded-xl border border-dashed border-ink-300 p-12 text-center dark:border-ink-700">
            <p class="text-sm text-ink-500">Nothing here.</p>
        </div>
    @endforelse
</div>
