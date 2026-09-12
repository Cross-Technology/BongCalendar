@props([
    'model',
    'placeholder' => '',
    'key' => 'editor',
    'minHeight' => '14rem',
])

@php
    // Quill injects its own icons only from the snow/bubble themes. We load
    // neither — their chrome would have to be undone to match the app — so the
    // buttons carry ours. Without them Quill leaves every button empty, and the
    // toolbar looks blank until something is hovered.
    $svg = 'width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
            stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
@endphp

{{--
    The one rich-text editor, used by reports and notes.

    wire:ignore is load-bearing, and it belongs on the Alpine root rather than
    on some inner wrapper. Quill rewrites this subtree as the user types, so
    letting Livewire morph it would wipe what is being written — and a morphed
    element gets re-initialised by Alpine, mounting a second Quill onto the
    same node. Two Quills sharing one DOM is what produces
    "Cannot read properties of null (reading 'offset')" on every selection.

    The key rebuilds the editor when the caller says the subject changed, so
    opening a second note never shows the first one's text.
--}}
<div wire:ignore
     wire:key="{{ $key }}"
     x-data="quillEditor(@js($model), @js($placeholder))"
     x-init="mount()"
     x-on:rich-text-insert.window="insert($event.detail?.html)"
     {{ $attributes->merge(['class' => 'rich-editor']) }}>

    <div x-show="! failed">

        {{-- Quill wires these up by their ql-* classes and keeps ql-active in step
             with the cursor; the grouping, icons and styling are ours. --}}
        <div x-ref="toolbar" class="rich-editor__toolbar">
            <span class="rich-editor__group">
                <button type="button" class="ql-bold" title="Bold" aria-label="Bold">
                    <span class="rich-editor__glyph rich-editor__glyph--bold" aria-hidden="true">B</span>
                </button>
                <button type="button" class="ql-italic" title="Italic" aria-label="Italic">
                    <span class="rich-editor__glyph rich-editor__glyph--italic" aria-hidden="true">I</span>
                </button>
                <button type="button" class="ql-strike" title="Strikethrough" aria-label="Strikethrough">
                    <span class="rich-editor__glyph rich-editor__glyph--strike" aria-hidden="true">S</span>
                </button>
            </span>

            <span class="rich-editor__group">
                <button type="button" class="ql-header" value="2" title="Heading" aria-label="Heading">
                    <svg {!! $svg !!}><path d="M5.5 5v10M14.5 5v10M5.5 10h9"/></svg>
                </button>
                <button type="button" class="ql-blockquote" title="Quote" aria-label="Quote">
                    <svg {!! $svg !!}><path d="M4.5 5.5v9M8.5 8h7M8.5 12h4.5"/></svg>
                </button>
                <button type="button" class="ql-code-block" title="Code" aria-label="Code">
                    <svg {!! $svg !!}><path d="m7.25 6.5-3.5 3.5 3.5 3.5M12.75 6.5l3.5 3.5-3.5 3.5"/></svg>
                </button>
            </span>

            <span class="rich-editor__group">
                <button type="button" class="ql-list" value="bullet" title="Bulleted list" aria-label="Bulleted list">
                    <svg {!! $svg !!}>
                        <circle cx="4.75" cy="6" r="1.05" fill="currentColor" stroke="none"/>
                        <circle cx="4.75" cy="10" r="1.05" fill="currentColor" stroke="none"/>
                        <circle cx="4.75" cy="14" r="1.05" fill="currentColor" stroke="none"/>
                        <path d="M8.75 6h6.75M8.75 10h6.75M8.75 14h6.75"/>
                    </svg>
                </button>
                <button type="button" class="ql-list" value="ordered" title="Numbered list" aria-label="Numbered list">
                    <svg {!! $svg !!}>
                        <path d="M9 6h6.5M9 10h6.5M9 14h6.5"/>
                        @foreach ([['1', 7.7], ['2', 11.7], ['3', 15.7]] as [$digit, $y])
                            <text x="2.4" y="{{ $y }}" font-size="5.4" font-weight="700"
                                  font-family="system-ui, sans-serif" fill="currentColor" stroke="none">{{ $digit }}</text>
                        @endforeach
                    </svg>
                </button>
            </span>

            <span class="rich-editor__group">
                <button type="button" class="ql-link" title="Insert link" aria-label="Insert link">
                    <svg {!! $svg !!}>
                        <path d="M8.75 11.25a2.9 2.9 0 0 0 4.1 0l2.1-2.1a2.9 2.9 0 1 0-4.1-4.1l-1 1"/>
                        <path d="M11.25 8.75a2.9 2.9 0 0 0-4.1 0l-2.1 2.1a2.9 2.9 0 1 0 4.1 4.1l1-1"/>
                    </svg>
                </button>
                <button type="button" class="ql-clean" title="Clear formatting" aria-label="Clear formatting">
                    <svg {!! $svg !!}>
                        <path d="M4.5 15.75h11"/>
                        <path d="M7 13.25 12.6 7.6a1.9 1.9 0 0 1 2.7 2.7l-5.65 5.65H7Z"/>
                        <path d="m10.4 9.8 2.7 2.7"/>
                    </svg>
                </button>
            </span>
        </div>

        <div x-ref="editor" style="min-height: {{ $minHeight }}"></div>
    </div>

    {{-- Shown only if the editor could not start, so a failure costs
         formatting rather than the ability to write at all. --}}
    <div x-show="failed" x-cloak>
        <p class="mb-1.5 text-[13px] font-medium text-amber-700 dark:text-amber-400">
            The formatting toolbar could not load — you can still write here.
        </p>
        <textarea x-on:input="$wire.set(@js($model), $event.target.value, false)"
                  placeholder="{{ $placeholder }}"
                  style="min-height: {{ $minHeight }}"
                  class="w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-[15px] outline-none transition focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950"></textarea>
    </div>
</div>
