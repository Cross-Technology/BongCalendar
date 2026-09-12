<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name') }}</title>

        {{-- Installable on phone and tablet. --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#ffffff">
        <meta name="application-name" content="{{ config('app.name') }}">
        <meta name="mobile-web-app-capable" content="yes">

        {{-- iOS ignores the manifest for installs and reads these instead. --}}
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
        <link rel="apple-touch-icon" sizes="152x152" href="/icons/apple-touch-icon-152.png">
        <link rel="apple-touch-icon" sizes="167x167" href="/icons/apple-touch-icon-167.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon-180.png">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="/icons/icon-32.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">

        <script>
            // Applied before first paint so the page never flashes the wrong theme.
            // Light is the default; dark is opt-in and remembered per browser.
            (function () {
                var dark = localStorage.getItem('theme') === 'dark';

                document.documentElement.dataset.theme = dark ? 'dark' : 'light';

                // Installed on a phone, this colours the status bar and the
                // window chrome, so it has to track the chosen theme.
                var meta = document.querySelector('meta[name="theme-color"]');

                if (meta) {
                    meta.content = dark ? '#0d1017' : '#ffffff';
                }
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="h-full bg-ink-50 font-sans text-ink-900 antialiased dark:bg-ink-950 dark:text-ink-100">
        @auth
            @php
                $user = auth()->user();
                $tenant = $user->currentTenant;
                $memberships = $user->tenants()->orderBy('name')->get();
                $tz = $user->timezone ?: 'UTC';

                $navCalendars = $tenant
                    ? \App\Models\Calendar::visibleTo($user, $tenant->id)
                        ->orderByDesc('is_default')->orderBy('name')->get()
                    : collect();

                // Event counts for the running month, so the sidebar reads like a workload glance.
                $monthStart = \Carbon\CarbonImmutable::now($tz)->startOfMonth()->utc();
                $monthEnd = \Carbon\CarbonImmutable::now($tz)->endOfMonth()->utc();

                $navCounts = $navCalendars->isEmpty()
                    ? collect()
                    : \App\Models\Event::query()
                        ->where('tenant_id', $tenant->id)
                        ->whereIn('calendar_id', $navCalendars->pluck('id'))
                        ->overlapping($monthStart, $monthEnd)
                        ->selectRaw('calendar_id, count(*) as total')
                        ->groupBy('calendar_id')
                        ->pluck('total', 'calendar_id');

                $navDepartments = $tenant
                    ? \App\Models\Department::forTenant($tenant->id)->get()
                    : collect();

                // Events this month per department, counted through calendars.
                $deptCounts = $navDepartments->isEmpty()
                    ? collect()
                    : \App\Models\Event::query()
                        ->join('calendars', 'calendars.id', '=', 'events.calendar_id')
                        ->where('events.tenant_id', $tenant->id)
                        ->whereIn('calendars.department_id', $navDepartments->pluck('id'))
                        // Only count events in calendars this user may see.
                        ->whereIn('events.calendar_id', $navCalendars->pluck('id'))
                        ->overlapping($monthStart, $monthEnd)
                        ->selectRaw('calendars.department_id as department_id, count(*) as total')
                        ->groupBy('calendars.department_id')
                        ->pluck('total', 'department_id');

                $activeDepartment = request()->integer('department');

                $openTasks = $tenant
                    ? \App\Models\Task::forTenant($tenant->id)->roots()->open()->count()
                    : 0;

                $pendingInvites = $tenant
                    ? \App\Models\EventInvitation::where('user_id', $user->id)
                        ->where('status', 'pending')
                        ->whereHas('event', fn ($q) => $q->where('tenant_id', $tenant->id))
                        ->count()
                    : 0;

                // Workspace invitations belong to the account, not a workspace,
                // so they count wherever the user happens to be standing.
                $pendingInvites += \App\Models\WorkspaceInvitation::pending()
                    ->forEmail($user->email)
                    ->count();

                $activeCalendars = (array) request()->input('selected', []);
                $role = $tenant ? $user->roleIn($tenant->id) : null;
            @endphp

            <div x-data="{ mobileNav: false }" class="min-h-full lg:flex">
                {{-- Scrim for the off-canvas drawer --}}
                <div x-show="mobileNav" x-transition.opacity x-on:click="mobileNav = false" x-cloak
                     class="fixed inset-0 z-30 bg-ink-950/40 backdrop-blur-[2px] lg:hidden"></div>

                {{-- ───────────────────────────── Sidebar ───────────────────────────── --}}
                <aside x-cloak
                       :class="mobileNav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
                       class="fixed inset-y-0 left-0 z-40 flex w-[17.5rem] flex-col border-r border-ink-200/80 bg-white transition-transform duration-300 ease-out lg:static lg:z-auto lg:h-screen lg:shrink-0 lg:translate-x-0 dark:border-ink-800 dark:bg-ink-900">

                    {{-- Workspace switcher --}}
                    <div class="px-3 pt-4 pb-2">
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" x-on:click="open = !open"
                                    class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left transition hover:bg-ink-100 dark:hover:bg-ink-800">
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand-600 text-[13px] font-bold text-white">
                                    {{ \Illuminate\Support\Str::of($tenant?->name ?? 'BC')->substr(0, 1)->upper() }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[15px] font-bold tracking-tight">{{ $tenant?->name ?? 'No workspace' }}</span>
                                </span>
                                <svg class="size-4 shrink-0 text-ink-400" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 6.5 8 3.5l3 3M5 9.5l3 3 3-3"/>
                                </svg>
                            </button>

                            <div x-show="open" x-on:click.outside="open = false" x-transition.origin.top.left x-cloak
                                 class="absolute inset-x-0 top-full z-10 mt-1 overflow-hidden rounded-xl border border-ink-200 bg-white p-1 shadow-lg shadow-ink-900/5 dark:border-ink-700 dark:bg-ink-800">
                                <p class="px-2.5 py-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-400">Workspaces</p>
                                @foreach ($memberships as $membership)
                                    <form method="POST" action="{{ route('workspaces.switch') }}">
                                        @csrf
                                        <input type="hidden" name="tenant_id" value="{{ $membership->id }}">
                                        <button class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm transition hover:bg-ink-100 dark:hover:bg-ink-700">
                                            <span class="grid size-5 shrink-0 place-items-center rounded bg-ink-200 text-[10px] font-bold text-ink-600 dark:bg-ink-600 dark:text-ink-100">
                                                {{ \Illuminate\Support\Str::of($membership->name)->substr(0, 1)->upper() }}
                                            </span>
                                            <span class="truncate">{{ $membership->name }}</span>
                                            @if ($membership->id === $tenant?->id)
                                                <svg class="ml-auto size-4 text-brand-600" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 8.5 3 3 6-7"/></svg>
                                            @endif
                                        </button>
                                    </form>
                                @endforeach
                                <a href="{{ route('workspaces.index') }}"
                                   class="mt-1 flex items-center gap-2 border-t border-ink-200 px-2.5 py-2 text-sm font-medium text-brand-600 dark:border-ink-700">
                                    <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8 3.5v9M3.5 8h9"/></svg>
                                    New workspace
                                </a>
                            </div>
                        </div>
                    </div>

                    {{-- Primary nav --}}
                    <nav class="flex-1 overflow-y-auto px-3 pb-3">
                        @php
                            $primary = [
                                ['route' => 'dashboard', 'label' => 'Calendar', 'icon' => 'calendar'],
                                ['route' => 'tasks.index', 'label' => 'Tasks', 'icon' => 'check', 'badge' => $openTasks],
                                ['route' => 'notes.index', 'label' => 'Notes', 'icon' => 'note'],
                                ['route' => 'reports.index', 'label' => 'Reports', 'icon' => 'report'],
                                ['route' => 'invitations.index', 'label' => 'Invitations', 'icon' => 'inbox', 'badge' => $pendingInvites],
                            ];
                        @endphp

                        @foreach ($primary as $item)
                            @php $isActive = request()->routeIs($item['route']); @endphp
                            <a href="{{ route($item['route']) }}"
                               class="mb-0.5 flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-[15px] font-semibold transition
                                      {{ $isActive
                                          ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-200'
                                          : 'text-ink-600 hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800' }}">
                                <x-icon :name="$item['icon']" class="size-[18px] {{ $isActive ? 'text-brand-600 dark:text-brand-300' : 'text-ink-400' }}" />
                                {{ $item['label'] }}
                                @if (($item['badge'] ?? 0) > 0)
                                    <span class="ml-auto rounded-full bg-brand-600 px-1.5 py-0.5 text-[11px] font-bold leading-none text-white">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        @endforeach

                        {{-- Departments --}}
                        <p class="px-2.5 pt-5 pb-2 text-[11px] font-bold uppercase tracking-wider text-ink-400">Departments</p>

                        @foreach ($navDepartments as $department)
                            @php $isOn = $activeDepartment === $department->id; @endphp
                            <a href="{{ route('dashboard', ['department' => $department->id]) }}"
                               class="mb-0.5 flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-[15px] transition
                                      {{ $isOn ? 'bg-ink-100 font-semibold dark:bg-ink-800' : 'text-ink-600 hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800' }}">
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $department->color }}"></span>
                                <span class="truncate">{{ $department->name }}</span>
                                @if (($deptCounts[$department->id] ?? 0) > 0)
                                    <span class="ml-auto text-[13px] font-medium text-ink-400">{{ $deptCounts[$department->id] }}</span>
                                @endif
                            </a>
                        @endforeach

                        <a href="{{ route('departments.index') }}"
                           class="mb-0.5 flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-[15px] transition
                                  {{ request()->routeIs('departments.index') ? 'bg-ink-100 font-semibold dark:bg-ink-800' : 'text-ink-600 hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800' }}">
                            <x-icon name="building" class="size-[18px] text-ink-400" />
                            All departments
                        </a>

                        {{-- Calendars --}}
                        <p class="px-2.5 pt-5 pb-2 text-[11px] font-bold uppercase tracking-wider text-ink-400">Calendars</p>

                        @forelse ($navCalendars as $calendar)
                            @php $isOn = in_array((string) $calendar->id, array_map('strval', $activeCalendars), true); @endphp
                            <a href="{{ route('dashboard', ['selected' => [$calendar->id]]) }}"
                               class="mb-0.5 flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-[15px] transition
                                      {{ $isOn ? 'bg-ink-100 font-semibold dark:bg-ink-800' : 'text-ink-600 hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800' }}">
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $calendar->color }}"></span>
                                <span class="truncate">{{ $calendar->name }}</span>
                                @if (($navCounts[$calendar->id] ?? 0) > 0)
                                    <span class="ml-auto text-[13px] font-medium text-ink-400">{{ $navCounts[$calendar->id] }}</span>
                                @endif
                            </a>
                        @empty
                            <p class="px-2.5 py-1 text-sm text-ink-400">No calendars yet.</p>
                        @endforelse

                        <a href="{{ route('calendars.index') }}"
                           class="mb-0.5 flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-[15px] transition
                                  {{ request()->routeIs('calendars.index') ? 'bg-ink-100 font-semibold dark:bg-ink-800' : 'text-ink-600 hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800' }}">
                            <x-icon name="stack" class="size-[18px] text-ink-400" />
                            All calendars
                        </a>

                        {{-- Other --}}
                        <p class="px-2.5 pt-5 pb-2 text-[11px] font-bold uppercase tracking-wider text-ink-400">Other</p>

                        @foreach ([
                            ['route' => 'workspaces.index', 'label' => 'Members & roles', 'icon' => 'users'],
                            ['route' => 'workspaces.index', 'label' => 'All workspaces', 'icon' => 'stack'],
                        ] as $item)
                            <a href="{{ route($item['route']) }}"
                               class="mb-0.5 flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-[15px] text-ink-600 transition hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800">
                                <x-icon :name="$item['icon']" class="size-[18px] text-ink-400" />
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </nav>

                    {{-- Appearance --}}
                    <div class="px-3 pb-3"
                         x-data="{
                             theme: document.documentElement.dataset.theme,
                             set(value) {
                                 this.theme = value;
                                 document.documentElement.dataset.theme = value;
                                 localStorage.setItem('theme', value);

                                 var meta = document.querySelector('meta[name=\'theme-color\']');

                                 if (meta) {
                                     meta.content = value === 'dark' ? '#0d1017' : '#ffffff';
                                 }
                             },
                         }">
                        <div class="flex gap-1 rounded-xl bg-ink-100 p-1 dark:bg-ink-800">
                            @foreach ([['light', 'Light', 'sun'], ['dark', 'Dark', 'moon']] as [$value, $label, $icon])
                                <button type="button" x-on:click="set('{{ $value }}')"
                                        :class="theme === '{{ $value }}'
                                            ? 'bg-white text-ink-900 shadow-sm dark:bg-ink-700 dark:text-white'
                                            : 'text-ink-500 hover:text-ink-700 dark:text-ink-400 dark:hover:text-ink-200'"
                                        class="flex flex-1 items-center justify-center gap-1.5 rounded-lg py-1.5 text-[13px] font-bold transition">
                                    <x-icon :name="$icon" class="size-4" />
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Account card --}}
                    <div x-data="{ open: false }" class="pwa-inset-bottom relative border-t border-ink-200/80 p-3 dark:border-ink-800">
                        <div x-show="open" x-on:click.outside="open = false" x-transition.origin.bottom.left x-cloak
                             class="absolute inset-x-3 bottom-full mb-1 overflow-hidden rounded-xl border border-ink-200 bg-white p-1 shadow-lg shadow-ink-900/5 dark:border-ink-700 dark:bg-ink-800">
                            <p class="truncate px-2.5 py-1.5 text-[13px] text-ink-400">{{ $user->email }}</p>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm font-medium text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950">
                                    <x-icon name="logout" class="size-4" />
                                    Sign out
                                </button>
                            </form>
                        </div>

                        <button type="button" x-on:click="open = !open"
                                class="flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-left transition hover:bg-ink-100 dark:hover:bg-ink-800">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[13px] font-bold text-white">
                                {{ \Illuminate\Support\Str::of($user->name)->substr(0, 1)->upper() }}
                            </span>
                            <span class="min-w-0 flex-1 leading-tight">
                                <span class="block truncate text-[14px] font-bold">{{ $user->name }}</span>
                                <span class="block truncate text-[12px] text-ink-400">
                                    {{ $tenant?->name ?? 'No workspace' }}{{ $role ? ' · '.ucfirst($role) : '' }}
                                </span>
                            </span>
                            <svg class="size-4 shrink-0 text-ink-400" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 3.5 5 4.5-5 4.5"/></svg>
                        </button>
                    </div>
                </aside>

                {{-- ───────────────────────────── Content ───────────────────────────── --}}
                <div class="app-scroll flex min-w-0 flex-1 flex-col lg:h-screen lg:overflow-y-auto">
                    {{-- Mobile bar: the sidebar is off-canvas below lg --}}
                    <div class="pwa-inset-top sticky top-0 z-20 flex items-center gap-3 border-b border-ink-200/80 bg-white/85 px-4 py-3 backdrop-blur lg:hidden dark:border-ink-800 dark:bg-ink-900/85">
                        <button type="button" x-on:click="mobileNav = true" aria-label="Open menu"
                                class="grid size-9 place-items-center rounded-xl border border-ink-200 text-ink-600 dark:border-ink-700 dark:text-ink-300">
                            <svg class="size-[18px]" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M2.5 4.5h11M2.5 8h11M2.5 11.5h11"/></svg>
                        </button>
                        <span class="truncate font-bold tracking-tight">{{ $tenant?->name ?? 'BongCalendar' }}</span>
                    </div>

                    @if (session('status') || session('error'))
                        <div class="px-4 pt-4 sm:px-8" x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)">
                            <div class="flex items-center gap-2.5 rounded-xl border px-4 py-3 text-sm font-medium
                                        {{ session('error')
                                            ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'
                                            : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' }}">
                                {{ session('error') ?? session('status') }}
                                <button type="button" x-on:click="show = false" class="ml-auto opacity-60 hover:opacity-100">✕</button>
                            </div>
                        </div>
                    @endif

                    <main class="pwa-inset-bottom flex-1 px-4 py-5 sm:px-8 sm:py-7">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        @else
            <main class="grid min-h-full place-items-center bg-ink-50 px-4 py-12 dark:bg-ink-950">
                {{ $slot }}
            </main>
        @endauth

        @livewireScripts
    </body>
</html>
