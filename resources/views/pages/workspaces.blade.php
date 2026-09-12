<?php

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Workspaces — BongCalendar')]
class extends Component
{
    use InteractsWithTenant;

    public bool $showCreate = false;

    public string $form_name = '';

    public string $form_timezone = 'UTC';

    /** Workspace whose member panel is open. */
    public ?int $managingId = null;

    public string $member_email = '';

    public string $member_role = 'member';

    /** Join-a-workspace box. */
    public string $join_code = '';

    public function mount(): void
    {
        $this->form_timezone = $this->userTimezone();
    }

    /** @return Collection<int, Tenant> */
    public function workspaces(): Collection
    {
        return $this->currentUser()->tenants()
            ->withCount(['users', 'calendars'])
            ->orderBy('name')
            ->get();
    }

    public function createWorkspace(WorkspaceService $workspaces): void
    {
        $data = $this->validate([
            'form_name' => ['required', 'string', 'max:255'],
            'form_timezone' => ['required', 'timezone'],
        ], attributes: ['form_name' => 'name', 'form_timezone' => 'timezone']);

        $workspaces->create($this->currentUser(), $data['form_name'], $data['form_timezone']);

        $this->showCreate = false;
        $this->form_name = '';

        session()->flash('status', 'Workspace created.');
    }

    public function switchTo(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $this->authorize('view', $tenant);

        $this->currentUser()->forceFill(['current_tenant_id' => $tenant->id])->save();

        session()->flash('status', "Switched to {$tenant->name}.");
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function manage(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $this->authorize('view', $tenant);

        $this->resetValidation();
        $this->managingId = $tenant->id;
        $this->member_email = '';
        $this->member_role = 'member';
    }

    /**
     * Sends an invitation. Membership is granted when the invitee accepts, not
     * here — being added to someone's workspace without a say is not an invite.
     */
    public function inviteMember(): void
    {
        $tenant = Tenant::findOrFail($this->managingId);
        $this->authorize('manageMembers', $tenant);

        $data = $this->validate([
            'member_email' => ['required', 'email', 'max:255'],
            'member_role' => ['required', 'in:admin,member'],
        ], attributes: ['member_email' => 'email', 'member_role' => 'role']);

        $email = strtolower(trim($data['member_email']));

        if ($tenant->users()->where('email', $email)->exists()) {
            $this->addError('member_email', 'They are already a member of this workspace.');

            return;
        }

        // Re-inviting refreshes the open invitation rather than stacking a
        // second one the invitee would have to answer twice.
        $invitation = $tenant->invitations()->pending()->forEmail($email)->first()
            ?? new WorkspaceInvitation(['tenant_id' => $tenant->id, 'email' => $email]);

        $invitation->fill([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'role' => $data['member_role'],
            'invited_by' => $this->currentUser()->id,
            'status' => 'pending',
            'expires_at' => now()->addDays(14),
        ])->save();

        $this->member_email = '';
        session()->flash('status', "Invitation sent to {$email}. They will see it in their notifications.");
    }

    public function revokeInvitation(int $invitationId): void
    {
        $invitation = WorkspaceInvitation::findOrFail($invitationId);
        $this->authorize('manageMembers', $invitation->tenant);

        $invitation->forceFill(['status' => 'revoked', 'responded_at' => now()])->save();

        session()->flash('status', 'Invitation revoked.');
    }

    public function regenerateCode(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $this->authorize('manageMembers', $tenant);

        $tenant->forceFill(['invite_code' => Tenant::generateInviteCode()])->save();

        session()->flash('status', 'New code generated. The old one no longer works.');
    }

    /** Redeems a code someone sent you. */
    public function joinByCode(WorkspaceService $workspaces): void
    {
        $data = $this->validate(
            ['join_code' => ['required', 'string', 'max:12']],
            attributes: ['join_code' => 'code']
        );

        $tenant = Tenant::where('invite_code', strtoupper(trim($data['join_code'])))->first();

        if (! $tenant) {
            $this->addError('join_code', 'That code does not match any workspace.');

            return;
        }

        if ($this->currentUser()->belongsToTenant($tenant->id)) {
            $this->addError('join_code', "You are already in {$tenant->name}.");

            return;
        }

        $workspaces->addMember($tenant, $this->currentUser(), 'member');

        $this->join_code = '';
        session()->flash('status', "You joined {$tenant->name}.");
    }

    /**
     * Promote a member to admin, or put an admin back to member. Owner-only:
     * an admin who could appoint admins could promote themselves past the
     * owner, so TenantPolicy::manageRoles keeps this with the owner alone.
     */
    public function changeRole(int $tenantId, int $userId, string $role, WorkspaceService $workspaces): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $this->authorize('manageRoles', $tenant);

        try {
            $workspaces->changeRole($tenant, User::findOrFail($userId), $role);
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', 'Role updated.');
    }

    public function removeMember(int $tenantId, int $userId, WorkspaceService $workspaces): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $this->authorize('manageMembers', $tenant);

        if ($tenant->owner_id === $userId) {
            session()->flash('error', 'The workspace owner cannot be removed.');

            return;
        }

        $workspaces->removeMember($tenant, User::findOrFail($userId));

        session()->flash('status', 'Member removed.');
    }

    public function with(): array
    {
        $managing = $this->managingId
            ? Tenant::with('users')->find($this->managingId)
            : null;

        return [
            'workspaceList' => $this->workspaces(),
            'managing' => $managing,
            'canManageRoles' => $managing && $this->currentUser()->can('manageRoles', $managing),
            'pendingInvitations' => $managing
                ? $managing->invitations()->pending()->with('inviter:id,name')->latest()->get()
                : collect(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Workspaces</h1>
            <p class="text-sm text-ink-500">Each workspace keeps its calendars, events and members separate.</p>
        </div>
        <button wire:click="$set('showCreate', true)"
                class="ml-auto rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
            + New workspace
        </button>
    </div>

    {{-- Redeeming a code someone sent you --}}
    <form wire:submit="joinByCode" class="flex flex-wrap items-end gap-2 rounded-2xl border border-ink-200 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
        <div class="min-w-0 flex-1">
            <label for="join-code" class="block text-sm font-medium">Have a join code?</label>
            <input id="join-code" type="text" wire:model="join_code" placeholder="ABCD2345" maxlength="12"
                   class="mt-1.5 w-full max-w-xs rounded-lg border border-ink-300 px-3 py-2 font-mono text-sm uppercase tracking-[0.2em] dark:border-ink-700 dark:bg-ink-800">
            @error('join_code') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
            Join workspace
        </button>
    </form>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($workspaceList as $workspace)
            @php $isCurrent = $workspace->id === auth()->user()->current_tenant_id; @endphp
            <article class="flex flex-col rounded-xl border bg-white p-5 dark:bg-ink-900
                            {{ $isCurrent ? 'border-brand-500 ring-1 ring-brand-500/20' : 'border-ink-200 dark:border-ink-800' }}">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-semibold">{{ $workspace->name }}</h2>
                        <p class="text-xs text-ink-500">{{ $workspace->slug }} · {{ $workspace->timezone }}</p>
                    </div>
                    @if ($isCurrent)
                        <span class="rounded bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300">ACTIVE</span>
                    @endif
                </div>

                <div class="mt-3 flex gap-1.5 text-[11px]">
                    <span class="rounded-full bg-ink-100 px-2 py-0.5 text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                        {{ $workspace->users_count }} {{ Str::plural('member', $workspace->users_count) }}
                    </span>
                    <span class="rounded-full bg-ink-100 px-2 py-0.5 text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                        {{ $workspace->calendars_count }} {{ Str::plural('calendar', $workspace->calendars_count) }}
                    </span>
                    <span class="rounded-full bg-ink-100 px-2 py-0.5 text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                        Your role: {{ $workspace->pivot->role }}
                    </span>
                </div>

                <div class="mt-auto flex flex-wrap gap-2 pt-4">
                    @unless ($isCurrent)
                        <button wire:click="switchTo({{ $workspace->id }})"
                                class="rounded-lg border border-ink-300 px-3 py-1.5 text-sm font-medium hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                            Switch to
                        </button>
                    @endunless
                    <button wire:click="manage({{ $workspace->id }})"
                            class="rounded-lg border border-ink-300 px-3 py-1.5 text-sm font-medium hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                        Members
                    </button>
                </div>
            </article>
        @endforeach
    </div>

    {{-- Create workspace --}}
    @if ($showCreate)
        <div class="fixed inset-0 z-50 grid place-items-center bg-ink-900/50 p-4" wire:keydown.escape="$set('showCreate', false)">
            <form wire:submit="createWorkspace" class="w-full max-w-md rounded-2xl bg-white shadow-xl dark:bg-ink-900">
                <header class="flex items-center justify-between border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 class="text-base font-semibold">New workspace</h2>
                    <button type="button" wire:click="$set('showCreate', false)" class="text-ink-400 hover:text-ink-600">✕</button>
                </header>

                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium">Name</label>
                        <input type="text" wire:model="form_name" autofocus
                               class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                        @error('form_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium">Timezone</label>
                        <select wire:model="form_timezone"
                                class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                            @foreach ($timezones as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </select>
                        @error('form_timezone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <footer class="flex justify-end gap-2 border-t border-ink-200 px-5 py-4 dark:border-ink-800">
                    <button type="button" wire:click="$set('showCreate', false)"
                            class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-medium dark:border-ink-700">Cancel</button>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create</button>
                </footer>
            </form>
        </div>
    @endif

    {{-- Members panel --}}
    @if ($managing)
        <div class="fixed inset-0 z-50 grid place-items-center bg-ink-900/50 p-4" wire:keydown.escape="$set('managingId', null)">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl dark:bg-ink-900">
                <header class="flex items-center justify-between border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 class="text-base font-semibold">{{ $managing->name }} — members</h2>
                    <button type="button" wire:click="$set('managingId', null)" class="text-ink-400 hover:text-ink-600">✕</button>
                </header>

                <ul class="max-h-64 divide-y divide-ink-100 overflow-y-auto px-5 dark:divide-ink-800">
                    @foreach ($managing->users as $member)
                        <li class="flex items-center gap-3 py-3" wire:key="member-{{ $member->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ $member->name }}
                                    @if ($member->id === $this->currentUser()->id)
                                        <span class="text-xs font-normal text-ink-400">(you)</span>
                                    @endif
                                </p>
                                <p class="truncate text-xs text-ink-500">{{ $member->email }}</p>
                            </div>

                            @if ($managing->owner_id === $member->id)
                                {{-- The owner's role is not a setting: every
                                     workspace has exactly one, and handing it
                                     over is a separate decision. --}}
                                <span class="rounded bg-brand-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-700 dark:bg-brand-950 dark:text-brand-200">
                                    Owner
                                </span>
                            @elseif ($canManageRoles)
                                <label class="sr-only" for="role-{{ $member->id }}">Role for {{ $member->name }}</label>
                                <select id="role-{{ $member->id }}"
                                        wire:change="changeRole({{ $managing->id }}, {{ $member->id }}, $event.target.value)"
                                        class="rounded-lg border border-ink-300 px-2 py-1 text-xs font-medium dark:border-ink-700 dark:bg-ink-800">
                                    <option value="member" @selected($member->pivot->role === 'member')>Member</option>
                                    <option value="admin" @selected($member->pivot->role === 'admin')>Admin</option>
                                </select>
                            @else
                                <span class="rounded bg-ink-100 px-2 py-0.5 text-[10px] font-medium text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                                    {{ ucfirst($member->pivot->role) }}
                                </span>
                            @endif

                            @can('manageMembers', $managing)
                                @if ($managing->owner_id !== $member->id)
                                    <button wire:click="removeMember({{ $managing->id }}, {{ $member->id }})"
                                            wire:confirm="Remove {{ $member->name }} from {{ $managing->name }}?"
                                            class="text-xs text-red-600 hover:underline">Remove</button>
                                @endif
                            @endcan
                        </li>
                    @endforeach
                </ul>

                {{-- Invitations awaiting an answer --}}
                @if ($pendingInvitations->isNotEmpty())
                    <div class="border-t border-ink-200 px-5 py-4 dark:border-ink-800">
                        <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-400">Awaiting reply</h3>
                        <ul class="space-y-1.5">
                            @foreach ($pendingInvitations as $invitation)
                                <li wire:key="inv-{{ $invitation->id }}" class="flex items-center gap-3">
                                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-amber-100 text-[11px] font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300">⏳</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium">{{ $invitation->email }}</span>
                                        <span class="text-xs text-ink-500">
                                            invited as {{ $invitation->role }}@if ($invitation->expires_at) · expires {{ $invitation->expires_at->diffForHumans() }}@endif
                                        </span>
                                    </span>
                                    @can('manageMembers', $managing)
                                        <button wire:click="revokeInvitation({{ $invitation->id }})"
                                                class="text-xs font-semibold text-red-600 hover:underline">Revoke</button>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @can('manageMembers', $managing)
                    <form wire:submit="inviteMember" class="space-y-3 border-t border-ink-200 px-5 py-4 dark:border-ink-800">
                        <label class="block text-sm font-medium">Invite someone by email</label>
                        <div class="flex flex-wrap gap-2">
                            <input type="email" wire:model="member_email" placeholder="teammate@example.com"
                                   class="min-w-0 flex-1 rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                            <select wire:model="member_role"
                                    class="rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                                <option value="member">Member</option>
                                <option value="admin">Admin</option>
                            </select>
                            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Invite</button>
                        </div>
                        <p class="text-xs text-ink-500">They join once they accept it in their notifications.</p>
                        @error('member_email') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </form>

                    {{-- The shareable code, for anyone you cannot invite by email --}}
                    <div class="border-t border-ink-200 px-5 py-4 dark:border-ink-800"
                         x-data="{ copied: false,
                                   copy() {
                                       navigator.clipboard?.writeText('{{ $managing->invite_code }}')
                                           .then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000) });
                                   } }">
                        <label class="block text-sm font-medium">Or share the join code</label>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <code class="select-all rounded-lg border border-dashed border-ink-300 px-3 py-2 font-mono text-base font-bold tracking-[0.2em] dark:border-ink-600">{{ $managing->invite_code }}</code>
                            <button type="button" x-on:click="copy()"
                                    class="rounded-lg border border-ink-300 px-3 py-2 text-sm font-semibold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                                <span x-show="!copied">Copy</span>
                                <span x-show="copied" x-cloak class="text-emerald-600">Copied</span>
                            </button>
                            <button type="button" wire:click="regenerateCode({{ $managing->id }})"
                                    wire:confirm="Generate a new code? The current one stops working."
                                    class="text-xs font-semibold text-ink-500 hover:underline">Regenerate</button>
                        </div>
                        <p class="mt-2 text-xs text-ink-500">Anyone with this code can join as a member.</p>
                    </div>
                @endcan
            </div>
        </div>
    @endif
</div>
