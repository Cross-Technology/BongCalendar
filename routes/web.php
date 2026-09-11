<?php

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/register', 'pages::auth.register')->name('register');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('/calendars', 'pages::calendars')->name('calendars.index');
    Route::livewire('/departments', 'pages::departments')->name('departments.index');
    Route::livewire('/tasks', 'pages::tasks')->name('tasks.index');
    Route::livewire('/notes', 'pages::notes')->name('notes.index');
    Route::livewire('/invitations', 'pages::invitations')->name('invitations.index');
    Route::livewire('/workspaces', 'pages::workspaces')->name('workspaces.index');

    // Workspace switcher in the header posts here.
    Route::post('/workspaces/switch', function (Request $request) {
        $tenant = Tenant::findOrFail($request->integer('tenant_id'));

        abort_unless($request->user()->belongsToTenant($tenant->id), 403);

        $request->user()->forceFill(['current_tenant_id' => $tenant->id])->save();

        return back()->with('status', "Switched to {$tenant->name}.");
    })->name('workspaces.switch');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
