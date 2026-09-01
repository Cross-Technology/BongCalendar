<?php

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Create account — BongCalendar')]
class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $workspace_name = '';

    public string $timezone = 'UTC';

    public function register(WorkspaceService $workspaces)
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'workspace_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
        ]);

        $user = DB::transaction(function () use ($data, $workspaces) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'timezone' => $data['timezone'],
            ]);

            $workspaces->create($user, $data['workspace_name'] ?: "{$user->name}'s Workspace", $data['timezone']);

            return $user->fresh();
        });

        Auth::login($user);
        session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function with(): array
    {
        return ['timezones' => \DateTimeZone::listIdentifiers()];
    }
};
?>

<div class="w-full max-w-md">
    <div class="mb-8 text-center">
        <span class="mx-auto mb-3 grid size-11 place-items-center rounded-xl bg-brand-600 font-bold text-white">BC</span>
        <h1 class="text-2xl font-semibold tracking-tight">Create your account</h1>
        <p class="mt-1 text-sm text-ink-500">We'll set up your first workspace and calendar.</p>
    </div>

    <form wire:submit="register" class="space-y-4 rounded-2xl border border-ink-200 bg-white p-6 shadow-sm dark:border-ink-800 dark:bg-ink-900">
        <div>
            <label for="name" class="mb-1.5 block text-sm font-medium">Name</label>
            <input id="name" type="text" wire:model="name" autofocus
                   class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-ink-700 dark:bg-ink-800">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="reg-email" class="mb-1.5 block text-sm font-medium">Email</label>
            <input id="reg-email" type="email" wire:model="email" autocomplete="email"
                   class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-ink-700 dark:bg-ink-800">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="reg-password" class="mb-1.5 block text-sm font-medium">Password</label>
                <input id="reg-password" type="password" wire:model="password" autocomplete="new-password"
                       class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-ink-700 dark:bg-ink-800">
                @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="reg-password-confirm" class="mb-1.5 block text-sm font-medium">Confirm</label>
                <input id="reg-password-confirm" type="password" wire:model="password_confirmation" autocomplete="new-password"
                       class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-ink-700 dark:bg-ink-800">
            </div>
        </div>

        <div>
            <label for="workspace" class="mb-1.5 block text-sm font-medium">
                Workspace name <span class="font-normal text-ink-400">(optional)</span>
            </label>
            <input id="workspace" type="text" wire:model="workspace_name" placeholder="e.g. Acme Team"
                   class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-ink-700 dark:bg-ink-800">
        </div>

        <div>
            <label for="tz" class="mb-1.5 block text-sm font-medium">Timezone</label>
            <select id="tz" wire:model="timezone"
                    class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-800">
                @foreach ($timezones as $tz)
                    <option value="{{ $tz }}">{{ $tz }}</option>
                @endforeach
            </select>
            @error('timezone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
                wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="register">Create account</span>
            <span wire:loading wire:target="register">Creating…</span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-ink-500">
        Already registered?
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline">Sign in</a>
    </p>
</div>
