@props([
    'entries',
    'timezone' => 'UTC',
    'noun' => 'item',
    'open' => false,
])

@php
    /**
     * The audit trail for one task or report: who did what to it, and when.
     *
     * The byline — who made it, who touched it last — is the caller's, passed
     * in as the slot. It reads off the subject's own columns, which every row
     * has, so it still answers for anything written before there was a trail.
     */
    $entries = collect($entries);

    $stamp = fn ($date) => $date?->setTimezone($timezone);
@endphp

<div x-data="{ open: @js((bool) $open) }" class="border-t border-ink-200/70 pt-3 dark:border-ink-800">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-ink-400">
        <span class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-400">
            <x-icon name="clock" class="size-3.5" />
            History
        </span>

        {{ $slot }}

        @if ($entries->isNotEmpty())
            <button type="button" x-on:click="open = ! open"
                    class="ml-auto shrink-0 rounded-lg px-1.5 py-0.5 text-[12px] font-bold text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-950">
                <span x-text="open ? 'Hide' : 'Show'">Show</span>
                {{ $entries->count() }} {{ \Illuminate\Support\Str::plural('entry', $entries->count()) }}
            </button>
        @endif
    </div>

    @if ($entries->isEmpty())
        <p class="mt-2 text-[12px] text-ink-400">
            Nothing recorded for this {{ $noun }} yet.
        </p>
    @endif

    {{-- Newest first, the way the trail is read: what just happened, then back. --}}
    <ol x-show="open" x-cloak x-transition class="mt-3 flex max-h-64 flex-col gap-2.5 overflow-y-auto pr-1">
        @foreach ($entries as $entry)
            <li wire:key="activity-{{ $entry->id }}" class="flex gap-2.5">
                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[9px] font-bold text-white">
                    {{ \Illuminate\Support\Str::of($entry->actorName())->substr(0, 1)->upper() }}
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-[12px] text-ink-500 dark:text-ink-400">
                        <span class="font-semibold text-ink-700 dark:text-ink-200">{{ $entry->actorName() }}</span>
                        {{ $entry->actionLabel() }} this {{ $noun }}
                        <span class="text-ink-400"
                              title="{{ $stamp($entry->created_at)->format('l, j F Y, H:i') }}">
                            · {{ $stamp($entry->created_at)->diffForHumans(short: true) }}
                        </span>
                    </p>

                    @php $lines = $entry->changeLines(); @endphp

                    @if ($lines)
                        <ul class="mt-1 flex flex-col gap-0.5">
                            @foreach ($lines as $line)
                                <li class="text-[12px] leading-snug">
                                    <span class="font-semibold text-ink-500 dark:text-ink-400">{{ $line['label'] }}</span>

                                    @if ($line['opaque'])
                                        <span class="text-ink-400">rewritten</span>
                                    @else
                                        <span class="text-ink-400">{{ $line['from'] ?? 'empty' }}</span>
                                        <span class="text-ink-300">→</span>
                                        <span class="text-ink-600 dark:text-ink-300">{{ $line['to'] ?? 'empty' }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</div>
