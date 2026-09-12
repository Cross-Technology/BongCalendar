<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Title('Sign in — BongCalendar')]
class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';


    public function login()
    {
        $this->validate();

        /*
         * Always "remember".
         *
         * The session cookie alone dies with the session; the remember-me
         * cookie is what brings someone back afterwards, and Auth::logout()
         * cycles the token behind it — so signing out is the one thing that
         * ends a session, which is the whole point.
         */
        $remember = true;

        if (! Auth::attempt(['email' => strtolower($this->email), 'password' => $this->password], $remember)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
};
?>

<div class="w-full max-w-sm">
    <div class="mb-8 text-center">
        <span class="mx-auto mb-3 grid size-11 place-items-center rounded-xl bg-brand-600 font-bold text-white">BC</span>
        <h1 class="text-2xl font-semibold tracking-tight">Welcome back</h1>
        <p class="mt-1 text-sm text-ink-500">Sign in to your BongCalendar workspace.</p>
    </div>

    <form wire:submit="login" class="space-y-4 rounded-2xl border border-ink-200 bg-white p-6 shadow-sm dark:border-ink-800 dark:bg-ink-900">
        <div>
            <label for="email" class="mb-1.5 block text-sm font-medium">Email</label>
            <input id="email" type="email" wire:model="email" autocomplete="email" autofocus
                   class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-ink-700 dark:bg-ink-800">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="mb-1.5 block text-sm font-medium">Password</label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password"
                   class="w-full rounded-lg border border-ink-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-ink-700 dark:bg-ink-800">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <p class="text-[13px] text-ink-500 dark:text-ink-400">
            You'll stay signed in on this device until you sign out.
        </p>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
                wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-ink-500">
        No account yet?
        <a href="{{ route('register') }}" class="font-medium text-brand-600 hover:underline">Create one</a>
    </p>
</div>
