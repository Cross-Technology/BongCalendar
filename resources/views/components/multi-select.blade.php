@props([
    /** @var iterable<int, array{id: int, label: string, color?: ?string}> */
    'options',
    /** Livewire property holding the selected ids, as an array. */
    'model',
    /** That property's current value, for the first paint of the trigger. */
    'selected' => [],
    /** What the trigger reads while nothing is picked. */
    'placeholder' => 'Select…',
    'searchPlaceholder' => 'Search…',
    /** Shown when there is nothing to pick from. */
    'empty' => 'Nothing to choose from',
    /** Send the change to the server when the list is folded away. */
    'commitOnClose' => false,
    /** Ids to float to the top, under their own heading. */
    'prefer' => [],
    'preferLabel' => '',
    'otherLabel' => '',
])

@php
    $all = collect($options)->map(fn ($option) => (array) $option);
    $preferred = $prefer ? $all->whereIn('id', $prefer)->values() : collect();
    $rest = $prefer ? $all->whereNotIn('id', $prefer)->values() : $all;

    // The heading only earns its place when it is actually dividing something.
    $groups = match (true) {
        $all->isEmpty() => [],
        $preferred->isNotEmpty() && $rest->isNotEmpty() => [[$preferLabel, $preferred], [$otherLabel, $rest]],
        default => [['', $all]],
    };

    $box = 'w-full rounded-xl border border-ink-200 bg-white text-left transition dark:border-ink-700 dark:bg-ink-900';

    // The same sentence Alpine writes, so the trigger reads correctly from the
    // very first paint rather than flashing the placeholder. Walked in the
    // order they were picked, which is the order the browser lists them in —
    // reading the list instead would reshuffle the names on every re-render.
    $chosen = collect($selected)
        ->map(fn ($id) => $all->firstWhere('id', (int) $id))
        ->filter()
        ->values();

    $summary = match (true) {
        $chosen->isEmpty() => $placeholder,
        $chosen->count() <= 2 => $chosen->pluck('label')->join(', '),
        default => $chosen->count().' selected',
    };
@endphp

<div x-data="multiSelect('{{ $model }}', @js((bool) $commitOnClose))"
     @keydown.escape.stop="close()"
     @click.outside="close()"
     wire:key="multi-select-{{ $model }}">

    {{-- Folded: one line, however many are picked.

         wire:ignore because everything in here is written by Alpine, and a
         re-render would hand back the server's copy — which has no idea what
         x-text put there and would blank the label mid-edit. The selection it
         reads from lives in the Livewire property, not in this markup, so
         nothing is lost by leaving the element alone. --}}
    <button type="button" wire:ignore @click="toggle()"
            :aria-expanded="open ? 'true' : 'false'"
            class="{{ $box }} flex items-center gap-2 px-3.5 py-2.5 text-[14px] outline-none
                   focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:focus:ring-brand-950"
            :class="open && 'border-brand-400'">
        <span class="min-w-0 flex-1 truncate font-semibold" x-text="summary || @js($placeholder)"
              :class="count === 0 && 'font-medium text-ink-400'">{{ $summary }}</span>

        <svg class="size-4 shrink-0 text-ink-400 transition" :class="open && 'rotate-180'"
             viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="m4 6 4 4 4-4"/></svg>
    </button>

    {{-- Unfolded in the flow of the page: both places this is used are
         scrolling columns, and a floating menu would be clipped by them. --}}
    <div x-show="open" x-cloak x-collapse
         class="mt-1 overflow-hidden rounded-xl border border-ink-200 bg-white dark:border-ink-700 dark:bg-ink-900">

        @if ($all->isNotEmpty())
            <div class="border-b border-ink-200 p-2 dark:border-ink-700">
                <input type="search" x-ref="search" x-model="search" placeholder="{{ $searchPlaceholder }}"
                       @keydown.enter.prevent
                       class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-[13px] outline-none
                              focus:border-brand-400 dark:border-ink-700 dark:bg-ink-800">
            </div>
        @endif

        <div class="max-h-56 overflow-y-auto p-1" x-ref="list">
            @forelse ($groups as [$heading, $items])
                @if ($heading)
                    <p class="px-2 pt-1.5 pb-1 text-[11px] font-bold uppercase tracking-wide text-ink-400"
                       x-show="anyMatch(@js($items->pluck('label')->all()))">{{ $heading }}</p>
                @endif

                @foreach ($items as $option)
                    <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 transition
                                  hover:bg-ink-50 dark:hover:bg-ink-800"
                           x-show="matches(@js($option['label']))">
                        <input type="checkbox" class="size-4 shrink-0 rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-ink-600"
                               data-id="{{ $option['id'] }}" data-label="{{ $option['label'] }}"
                               :checked="has({{ $option['id'] }})"
                               @change="toggleId({{ $option['id'] }})">

                        @if (! empty($option['color']))
                            <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $option['color'] }}"></span>
                        @endif

                        <span class="truncate text-[13px] font-semibold">{{ $option['label'] }}</span>
                    </label>
                @endforeach
            @empty
                <p class="px-2 py-2 text-[13px] text-ink-500 dark:text-ink-400">{{ $empty }}</p>
            @endforelse

            @if ($all->isNotEmpty())
                <p class="px-2 py-2 text-[13px] text-ink-500 dark:text-ink-400"
                   x-show="! anyMatch(@js($all->pluck('label')->all()))" x-cloak>
                    Nothing matches “<span x-text="search"></span>”.
                </p>
            @endif
        </div>

        @if ($all->isNotEmpty())
            <div class="flex items-center justify-between gap-2 border-t border-ink-200 px-3 py-2 dark:border-ink-700">
                <span class="text-[12px] text-ink-500 dark:text-ink-400"
                      x-text="count === 0 ? 'None picked' : count + ' picked'"></span>

                <span class="flex items-center gap-1">
                    <button type="button" x-show="count > 0" x-cloak @click="clear()"
                            class="rounded-lg px-2 py-1 text-[12px] font-bold text-ink-500 transition hover:bg-ink-100 dark:hover:bg-ink-800">
                        Clear
                    </button>
                    <button type="button" @click="close()"
                            class="rounded-lg px-2.5 py-1 text-[12px] font-bold text-brand-700 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-950">
                        Done
                    </button>
                </span>
            </div>
        @endif
    </div>
</div>
