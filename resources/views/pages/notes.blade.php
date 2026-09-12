<?php

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Attachment;
use App\Models\Note;
use App\Services\AttachmentService;
use App\Services\RichTextService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Title('Notes — BongCalendar')]
class extends Component
{
    use InteractsWithTenant, WithFileUploads, WithPagination;

    /** Search and filters live in the URL so a view can be shared or reloaded. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** all | mine | pinned */
    #[Url(except: 'all')]
    public string $filter = 'all';

    /** The note open in the read dialog, if any. */
    public ?int $viewingId = null;

    /* The inline composer at the top of the board — the fast path. */
    public string $quick_body = '';

    public string $quick_color = '#f59e0b';

    public string $quick_visibility = 'tenant';

    /* The full editor, for titles, pinning, and every edit. */
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $form_title = '';

    public string $form_body = '';

    public string $form_color = '#f59e0b';

    public string $form_visibility = 'tenant';

    public bool $form_pinned = false;

    /** Files chosen in the editor but not yet saved against the note. */
    public array $uploads = [];

    /**
     * Bumped every time the editor is opened, and folded into its wire:key.
     *
     * The editor sits behind wire:ignore, so Livewire leaves its DOM alone. If
     * the key repeats, the morph reuses the element already there — Alpine
     * never re-runs x-init and Quill keeps the previous note's text.
     */
    public int $editorSession = 0;

    public function mount(): void
    {
        $this->requireTenant();
    }

    /* ----------------------------------------------------------------- data */

    /** @return LengthAwarePaginator<int, Note> */
    public function notes(): LengthAwarePaginator
    {
        $user = $this->currentUser();

        return Note::visibleTo($user, $this->requireTenant()->id)
            ->search($this->search)
            ->when($this->filter === 'mine', fn ($q) => $q->where('author_id', $user->id))
            ->when($this->filter === 'pinned', fn ($q) => $q->where('is_pinned', true))
            ->with('author')
            ->withCount('attachments')
            ->boardOrder()
            ->paginate(12);
    }

    /** Searching or switching filters must not strand the user on page 4. */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'mine', 'pinned'], true) ? $filter : 'all';
        $this->resetPage();
    }

    /* ------------------------------------------------------- quick composer */

    /**
     * Write a note without opening anything. Body only — a title is the
     * exception, not the rule, so it lives in the full editor.
     */
    public function quickSave(): void
    {
        $tenantId = $this->requireTenant()->id;

        $this->authorize('create', [Note::class, $tenantId]);

        $data = $this->validate([
            'quick_body' => ['required', 'string', 'max:20000'],
            'quick_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'quick_visibility' => ['required', Rule::in(Note::VISIBILITIES)],
        ], attributes: [
            'quick_body' => 'note',
            'quick_color' => 'colour',
            'quick_visibility' => 'visibility',
        ]);

        // The quick box is plain text by design — it is the fast path. It is
        // escaped into markup here so every note body is the same shape, and
        // so text that happens to contain tags stays inert.
        $richText = app(RichTextService::class);
        $body = $richText->fromPlainText($data['quick_body']);

        Note::create([
            'tenant_id' => $tenantId,
            'author_id' => $this->currentUser()->id,
            'title' => null,
            'body' => $body,
            'body_text' => $richText->toText($body),
            'color' => $data['quick_color'],
            'visibility' => $data['quick_visibility'],
            'is_pinned' => false,
        ]);

        $this->quick_body = '';
        $this->resetPage();

        // Collapses the composer without a second round trip.
        $this->dispatch('note-saved');
    }

    /** Carry a half-written quick note into the full editor. */
    public function expandDraft(): void
    {
        $this->authorize('create', [Note::class, $this->requireTenant()->id]);

        $this->resetValidation();
        $this->editingId = null;
        $this->form_title = '';
        $this->form_body = app(RichTextService::class)->fromPlainText($this->quick_body);
        $this->uploads = [];
        $this->form_color = $this->quick_color;
        $this->form_visibility = $this->quick_visibility;
        $this->form_pinned = false;
        $this->editorSession++;
        $this->showModal = true;
    }

    /*
     * ------------------------------------------------------------ read view
     *
     * Named openNote/closeNote rather than view/close: `view()` is already
     * Livewire\Component::view(), and redeclaring it is a fatal error.
     */

    public function openNote(int $noteId): void
    {
        $note = Note::findOrFail($noteId);

        $this->authorize('view', $note);

        $this->viewingId = $note->id;
    }

    public function closeNote(): void
    {
        $this->viewingId = null;
    }

    /**
     * The note the read dialog is showing. Resolved on every render rather
     * than held in the component, so a note deleted or made private while the
     * dialog sits open closes it instead of throwing at whoever is looking.
     */
    public function viewingNote(): ?Note
    {
        if (! $this->viewingId) {
            return null;
        }

        $note = Note::with(['author', 'attachments.uploader'])->find($this->viewingId);

        if (! $note || $this->currentUser()->cannot('view', $note)) {
            $this->viewingId = null;

            return null;
        }

        return $note;
    }

    /* --------------------------------------------------------- full editor */

    public function create(): void
    {
        $this->authorize('create', [Note::class, $this->requireTenant()->id]);

        $this->resetValidation();
        $this->editingId = null;
        $this->form_title = '';
        $this->form_body = '';
        $this->uploads = [];
        $this->form_color = Note::COLORS[0];
        $this->form_visibility = 'tenant';
        $this->form_pinned = false;
        $this->editorSession++;
        $this->showModal = true;
    }

    public function edit(int $noteId): void
    {
        $note = Note::findOrFail($noteId);
        $this->authorize('update', $note);

        $this->resetValidation();
        // One dialog at a time: editing takes over from reading.
        $this->viewingId = null;
        $this->editingId = $note->id;
        $this->form_title = (string) $note->title;
        $this->form_body = $note->body;
        $this->form_color = $note->color;
        $this->form_visibility = $note->visibility;
        $this->form_pinned = $note->is_pinned;
        $this->uploads = [];
        $this->editorSession++;
        $this->showModal = true;
    }

    public function save(RichTextService $richText, AttachmentService $attachments): void
    {
        $tenantId = $this->requireTenant()->id;

        $data = $this->validate([
            'form_title' => ['nullable', 'string', 'max:255'],
            'form_body' => ['required', 'string', 'max:200000'],
            'form_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form_visibility' => ['required', Rule::in(Note::VISIBILITIES)],
            'form_pinned' => ['boolean'],
        ], attributes: [
            'form_title' => 'title',
            'form_body' => 'note',
            'form_color' => 'colour',
            'form_visibility' => 'visibility',
        ]);

        // An editor nobody typed in still posts "<div><br></div>", which is
        // markup but not a note.
        if ($richText->isBlank($data['form_body'])) {
            $this->addError('form_body', 'Write something before saving the note.');

            return;
        }

        $body = $richText->sanitize($data['form_body']);

        $payload = [
            'title' => $data['form_title'] ?: null,
            'body' => $body,
            'body_text' => $richText->toText($body),
            'color' => $data['form_color'],
            'is_pinned' => $data['form_pinned'],
        ];

        if ($this->editingId) {
            $note = Note::findOrFail($this->editingId);
            $this->authorize('update', $note);

            // Only the author decides whether their note stays private; an
            // admin tidying a shared note cannot publish someone else's.
            if ($note->author_id === $this->currentUser()->id) {
                $payload['visibility'] = $data['form_visibility'];
            }

            $note->update($payload);
            session()->flash('status', 'Note updated.');
        } else {
            $this->authorize('create', [Note::class, $tenantId]);

            $note = Note::create($payload + [
                'tenant_id' => $tenantId,
                'author_id' => $this->currentUser()->id,
                'visibility' => $data['form_visibility'],
            ]);

            // The draft has become a note; clear the composer behind the modal.
            $this->quick_body = '';
            $this->resetPage();
        }

        $this->attachPending($note, $attachments);

        $this->showModal = false;
    }

    /* ---------------------------------------------------------- attachments */

    /**
     * Files are only moved onto the note once the note exists — a new note has
     * no id to hang them off while the modal is still open.
     */
    protected function attachPending(Note $note, AttachmentService $attachments): void
    {
        foreach ($this->uploads as $file) {
            $attachments->store($file, $note, $this->currentUser(), $note->tenant_id);
        }

        $this->uploads = [];
    }

    /**
     * Checked as each file is chosen rather than at save, so a 6MB pick is
     * refused immediately instead of after the note is typed. Valid files are
     * kept; only the offending ones are dropped.
     */
    public function updatedUploads(): void
    {
        $kept = [];

        foreach ($this->uploads as $file) {
            $validator = Validator::make(
                ['file' => $file],
                ['file' => Attachment::uploadRules()],
                [],
                ['file' => $file->getClientOriginalName()],
            );

            if ($validator->fails()) {
                $this->addError('uploads', $validator->errors()->first('file'));

                continue;
            }

            $kept[] = $file;
        }

        $this->uploads = $kept;
    }

    /** Drops a file chosen in this session, before it reaches the note. */
    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);

        $this->uploads = array_values($this->uploads);
    }

    public function removeAttachment(int $attachmentId, AttachmentService $attachments): void
    {
        $attachment = Attachment::findOrFail($attachmentId);

        $this->authorize('delete', $attachment);

        $attachments->remove($attachment);
    }

    public function togglePin(int $noteId): void
    {
        $note = Note::findOrFail($noteId);
        $this->authorize('update', $note);

        $note->update(['is_pinned' => ! $note->is_pinned]);
    }

    public function delete(int $noteId): void
    {
        $note = Note::findOrFail($noteId);
        $this->authorize('delete', $note);

        $note->delete();

        if ($this->viewingId === $noteId) {
            $this->viewingId = null;
        }

        session()->flash('status', 'Note deleted.');
    }

    public function with(): array
    {
        return [
            'noteList' => $this->notes(),
            'viewingNote' => $this->viewingNote(),
            'editingNote' => $this->editingId
                ? Note::with('attachments.uploader')->find($this->editingId)
                : null,
        ];
    }
};
?>

@php
    $swatch = 'size-5 rounded-full transition hover:scale-110';
    $field = 'w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-ink-300 focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950';
    $labelClass = 'mb-1.5 block text-[13px] font-bold text-ink-600 dark:text-ink-300';
@endphp

<div class="mx-auto flex max-w-6xl flex-col gap-5">
    <header class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h1 class="text-3xl font-extrabold tracking-tight">Notes</h1>
        <p class="text-[15px] text-ink-500 dark:text-ink-400">
            {{ $this->currentTenant()->name }} · {{ $noteList->total() }} {{ \Illuminate\Support\Str::plural('note', $noteList->total()) }}
        </p>
    </header>

    {{--
        The composer is the page's centre of gravity: a member should be able to
        land here and type, with no modal in the way. It stays one line until
        focused, then unfolds its options.
    --}}
    <form wire:submit="quickSave"
          x-data="{ open: false }"
          x-on:note-saved.window="open = false"
          x-on:click.outside="if (! $refs.quickBody.value.trim()) open = false"
          style="--note-color: {{ $quick_color }}"
          class="panel note-card p-3 shadow-sm shadow-ink-900/[0.03] transition sm:p-4">
        <label class="sr-only" for="quick-note">Write a note</label>
        <textarea id="quick-note" wire:model="quick_body" x-ref="quickBody"
                  x-on:focus="open = true"
                  x-on:keydown.meta.enter.prevent="$wire.quickSave()"
                  x-on:keydown.ctrl.enter.prevent="$wire.quickSave()"
                  :rows="open ? 4 : 1"
                  rows="1"
                  placeholder="Write a note…"
                  class="w-full resize-none bg-transparent px-1 text-[15px] leading-relaxed outline-none placeholder:text-ink-400"></textarea>

        @error('quick_body') <p class="px-1 pt-1 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror

        <div x-show="open" x-collapse x-cloak
             class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-ink-200/70 pt-3 dark:border-ink-800">
            <div class="flex items-center gap-1.5" role="group" aria-label="Note colour">
                @foreach (\App\Models\Note::COLORS as $hex)
                    <button type="button" wire:click="$set('quick_color', '{{ $hex }}')"
                            aria-label="Use {{ $hex }}" aria-pressed="{{ $quick_color === $hex ? 'true' : 'false' }}"
                            class="{{ $swatch }} {{ $quick_color === $hex ? 'ring-2 ring-ink-900 ring-offset-2 dark:ring-white dark:ring-offset-ink-900' : '' }}"
                            style="background-color: {{ $hex }}"></button>
                @endforeach
            </div>

            <div class="flex gap-1 rounded-lg bg-ink-100 p-0.5 dark:bg-ink-800" role="group" aria-label="Who can read it">
                @foreach ([['tenant', 'Workspace'], ['private', 'Only me']] as [$value, $text])
                    <button type="button" wire:click="$set('quick_visibility', '{{ $value }}')"
                            aria-pressed="{{ $quick_visibility === $value ? 'true' : 'false' }}"
                            class="rounded-md px-2.5 py-1 text-[12px] font-bold transition
                                   {{ $quick_visibility === $value
                                       ? 'bg-white text-ink-900 shadow-sm dark:bg-ink-700 dark:text-white'
                                       : 'text-ink-500 hover:text-ink-700 dark:text-ink-400 dark:hover:text-ink-200' }}">
                        {{ $text }}
                    </button>
                @endforeach
            </div>

            <div class="ml-auto flex items-center gap-2">
                <button type="button" wire:click="expandDraft"
                        class="rounded-lg px-2.5 py-1.5 text-[13px] font-bold text-ink-500 transition hover:bg-ink-100 hover:text-ink-800 dark:hover:bg-ink-800 dark:hover:text-ink-100">
                    Add title…
                </button>
                <button type="submit"
                        class="rounded-xl bg-brand-600 px-4 py-2 text-[13px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                    Save note
                </button>
            </div>
        </div>
    </form>

    {{-- Search + filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <label class="relative min-w-0 flex-1 sm:max-w-xs">
            <span class="sr-only">Search notes</span>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search notes…"
                   class="w-full rounded-xl border border-ink-200 bg-white py-2.5 pl-9 pr-3.5 text-[15px] outline-none transition placeholder:text-ink-300 focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950">
        </label>

        <div class="flex gap-1 rounded-xl bg-ink-100 p-1 dark:bg-ink-800">
            @foreach ([['all', 'All'], ['mine', 'Mine'], ['pinned', 'Pinned']] as [$value, $label])
                <button type="button" wire:click="setFilter('{{ $value }}')"
                        aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                        class="rounded-lg px-3 py-1.5 text-[13px] font-bold transition
                               {{ $filter === $value
                                   ? 'bg-white text-ink-900 shadow-sm dark:bg-ink-700 dark:text-white'
                                   : 'text-ink-500 hover:text-ink-700 dark:text-ink-400 dark:hover:text-ink-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($noteList->isNotEmpty())
        {{-- `items-start` lets each card keep its own height: a one-line note
             should not be stretched to match the essay beside it. --}}
        <div class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($noteList as $note)
                <article style="--note-color: {{ $note->color }}"
                         wire:key="note-{{ $note->id }}"
                         class="panel note-card relative flex flex-col gap-2.5 p-4 shadow-sm shadow-ink-900/[0.03] transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:shadow-ink-900/[0.07]
                                {{ $note->is_pinned ? 'ring-1 ring-brand-300/70 dark:ring-brand-800' : '' }}">
                    <header class="flex items-start gap-2">
                        {{-- The title is the card's single control: `after`
                             stretches its hit area over the whole card, so a
                             tap anywhere opens the note while the keyboard
                             still sees one button rather than a dozen. --}}
                        <h2 class="min-w-0 flex-1 text-[15px] font-bold leading-snug tracking-tight">
                            <button type="button" wire:click="openNote({{ $note->id }})"
                                    class="block w-full break-words text-left after:absolute after:inset-0 after:content-[''] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                                {{ $note->displayTitle() }}
                            </button>
                        </h2>

                        @can('update', $note)
                            <button type="button" wire:click="togglePin({{ $note->id }})"
                                    aria-label="{{ $note->is_pinned ? 'Unpin this note' : 'Pin this note' }}"
                                    aria-pressed="{{ $note->is_pinned ? 'true' : 'false' }}"
                                    class="relative z-10 -mr-1 grid size-7 shrink-0 place-items-center rounded-lg transition
                                           {{ $note->is_pinned
                                               ? 'text-brand-600 dark:text-brand-300'
                                               : 'text-ink-300 hover:bg-ink-100 hover:text-ink-600 dark:text-ink-600 dark:hover:bg-ink-800 dark:hover:text-ink-300' }}">
                                <x-icon name="pin" class="size-4" />
                            </button>
                        @else
                            @if ($note->is_pinned)
                                <x-icon name="pin" class="size-4 shrink-0 text-brand-600 dark:text-brand-300" />
                            @endif
                        @endcan
                    </header>

                    {{-- A preview only, and taken from the plain-text
                         rendering: dropping a clamped slice of HTML into the
                         card would cut a tag in half. Opening the card shows
                         the note in full. --}}
                    <p class="line-clamp-6 whitespace-pre-line break-words text-[14px] leading-relaxed text-ink-600 dark:text-ink-300">{{ $note->body_text }}</p>

                    @if ($note->attachments_count > 0)
                        <p class="flex items-center gap-1.5 text-[12px] font-semibold text-ink-400">
                            <x-icon name="paperclip" class="size-3.5" />
                            {{ $note->attachments_count }} {{ \Illuminate\Support\Str::plural('file', $note->attachments_count) }}
                        </p>
                    @endif

                    <footer class="flex flex-wrap items-center gap-x-2 gap-y-1.5 border-t border-ink-200/70 pt-2.5 text-[12px] text-ink-400 dark:border-ink-800">
                        <span class="grid size-5 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[9px] font-bold text-white">
                            {{ \Illuminate\Support\Str::of($note->author->name)->substr(0, 1)->upper() }}
                        </span>
                        <span class="min-w-0 max-w-[8rem] truncate font-semibold text-ink-500 dark:text-ink-400">{{ $note->author->name }}</span>
                        <span class="shrink-0">{{ $note->updated_at->diffForHumans(short: true) }}</span>

                        @if ($note->isPrivate())
                            <span class="rounded-full bg-ink-100 px-2 py-0.5 font-bold text-ink-500 dark:bg-ink-800 dark:text-ink-300">Private</span>
                        @endif

                        @can('update', $note)
                            {{-- Icon buttons stay visible rather than appearing
                                 on hover, which touch devices never trigger. --}}
                            <span class="relative z-10 ml-auto flex shrink-0 gap-0.5">
                                <button type="button" wire:click="edit({{ $note->id }})"
                                        aria-label="Edit this note"
                                        class="grid size-7 place-items-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800 dark:hover:text-ink-200">
                                    <x-icon name="pencil" class="size-[15px]" />
                                </button>
                                <button type="button" wire:click="delete({{ $note->id }})"
                                        wire:confirm="Delete this note? This cannot be undone."
                                        aria-label="Delete this note"
                                        class="grid size-7 place-items-center rounded-lg text-ink-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950">
                                    <x-icon name="trash" class="size-[15px]" />
                                </button>
                            </span>
                        @endcan
                    </footer>
                </article>
            @endforeach
        </div>

        @if ($noteList->hasPages())
            <div>{{ $noteList->links() }}</div>
        @endif
    @else
        <section class="panel flex flex-col items-center px-4 py-12 text-center shadow-sm shadow-ink-900/[0.03]">
            <span class="grid size-12 place-items-center rounded-2xl bg-ink-100 text-ink-400 dark:bg-ink-800">
                <x-icon name="note" class="size-6" />
            </span>
            <p class="mt-4 text-[15px] font-bold">
                {{ $search !== '' || $filter !== 'all' ? 'No notes match' : 'Nothing on the board yet' }}
            </p>
            <p class="mt-1 max-w-xs text-[13px] text-ink-400">
                @if ($search !== '' || $filter !== 'all')
                    Try a different search, or clear the filter.
                @else
                    Notes are the workspace's shared memory — start typing in the box above.
                @endif
            </p>
        </section>
    @endif

    {{-- Read view: the note in full, however long it runs --}}
    @if ($viewingNote)
        <div data-modal
             class="fixed inset-0 z-50 grid h-dvh place-items-end overflow-hidden bg-ink-950/50 p-0 backdrop-blur-[2px] sm:place-items-center sm:p-4"
             x-on:keydown.escape.window="$wire.closeNote()"
             wire:click.self="closeNote">
            {{-- Split into three, with only the note body scrolling, so the
                 heading and the actions stay reachable on a short screen.

                 The height cap is `max-h-full` against an `h-dvh` overlay, not
                 a dvh fraction of its own: on a phone the browser chrome eats
                 into the viewport, and a panel sized from `vh` — or from `dvh`
                 while its parent is sized from the large viewport — hangs off
                 the bottom of the screen with its buttons out of reach. --}}
            <div style="--note-color: {{ $viewingNote->color }}"
                 class="note-card flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl dark:bg-ink-900">
                <header class="flex shrink-0 items-start gap-3 px-6 pt-5 pb-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="break-words text-xl font-extrabold leading-tight tracking-tight">
                            {{ $viewingNote->displayTitle() }}
                        </h2>

                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-ink-400">
                            <span class="grid size-5 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[9px] font-bold text-white">
                                {{ \Illuminate\Support\Str::of($viewingNote->author->name)->substr(0, 1)->upper() }}
                            </span>
                            <span class="font-semibold text-ink-500 dark:text-ink-400">{{ $viewingNote->author->name }}</span>
                            <span>· {{ $viewingNote->updated_at->diffForHumans() }}</span>

                            @if ($viewingNote->isPrivate())
                                <span class="rounded-full bg-ink-100 px-2 py-0.5 font-bold text-ink-500 dark:bg-ink-800 dark:text-ink-300">Private</span>
                            @endif

                            @if ($viewingNote->is_pinned)
                                <span class="inline-flex items-center gap-1 font-bold text-brand-600 dark:text-brand-300">
                                    <x-icon name="pin" class="size-3" /> Pinned
                                </span>
                            @endif
                        </div>
                    </div>

                    <button type="button" wire:click="closeNote" aria-label="Close"
                            class="grid size-8 shrink-0 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">✕</button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-3">
                    {{-- Safe to render unescaped: RichTextService sanitises
                         every body before it is stored, and nothing else
                         writes this column. --}}
                    <div class="rich-text text-ink-700 dark:text-ink-200">{!! $viewingNote->body !!}</div>

                    @if ($viewingNote->attachments->isNotEmpty())
                        <div class="mt-5 border-t border-ink-200/70 pt-4 dark:border-ink-800">
                            <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-400">
                                {{ $viewingNote->attachments->count() }} {{ \Illuminate\Support\Str::plural('file', $viewingNote->attachments->count()) }}
                            </h3>

                            <ul class="flex flex-col gap-1.5">
                                @foreach ($viewingNote->attachments as $attachment)
                                    <li wire:key="att-{{ $attachment->id }}">
                                        <a href="{{ $attachment->downloadUrl() }}"
                                           class="group flex items-center gap-2.5 rounded-xl border border-ink-200 px-3 py-2 transition hover:border-brand-300 hover:bg-brand-50/40 dark:border-ink-700 dark:hover:border-brand-700 dark:hover:bg-brand-950/40">
                                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-ink-100 text-[10px] font-bold uppercase text-ink-500 dark:bg-ink-800 dark:text-ink-300">
                                                {{ $attachment->extension() ?: 'file' }}
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-[14px] font-semibold group-hover:text-brand-700 dark:group-hover:text-brand-200">{{ $attachment->original_name }}</span>
                                                <span class="block text-[12px] text-ink-400">{{ $attachment->humanSize() }}</span>
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <footer class="pwa-inset-bottom flex shrink-0 flex-wrap items-center gap-2 border-t border-ink-200/80 px-6 py-4 dark:border-ink-800">
                    @can('update', $viewingNote)
                        <button type="button" wire:click="togglePin({{ $viewingNote->id }})"
                                class="rounded-xl border border-ink-200 px-3 py-2 text-[13px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                            {{ $viewingNote->is_pinned ? 'Unpin' : 'Pin' }}
                        </button>
                        <button type="button" wire:click="delete({{ $viewingNote->id }})"
                                wire:confirm="Delete this note? This cannot be undone."
                                class="rounded-xl px-3 py-2 text-[13px] font-bold text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950">
                            Delete
                        </button>
                    @endcan

                    <div class="ml-auto flex gap-2">
                        <button type="button" wire:click="closeNote"
                                class="rounded-xl border border-ink-200 px-4 py-2 text-[13px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                            Close
                        </button>

                        @can('update', $viewingNote)
                            <button type="button" wire:click="edit({{ $viewingNote->id }})"
                                    class="rounded-xl bg-brand-600 px-4 py-2 text-[13px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                                Edit note
                            </button>
                        @endcan
                    </div>
                </footer>
            </div>
        </div>
    @endif

    {{-- Full editor: titles, pinning, and every edit --}}
    @if ($showModal)
        <div data-modal
             class="fixed inset-0 z-50 grid h-dvh place-items-end overflow-hidden bg-ink-950/50 p-0 backdrop-blur-[2px] sm:place-items-center sm:p-4"
             wire:key="note-dialog-{{ $editingId ?? 'new' }}-{{ $editorSession }}"
             x-on:keydown.escape.window="$wire.set('showModal', false)"
             wire:click.self="$set('showModal', false)">
            {{-- Capped at the overlay, which is the visible viewport, with only
                 the fields scrolling. Without this the form runs past the
                 bottom of a short screen and Save becomes unreachable. --}}
            <div style="--note-color: {{ $form_color }}"
                 class="note-card flex max-h-full w-full max-w-lg flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl dark:bg-ink-900">
                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <header class="flex shrink-0 items-center justify-between px-6 pt-5 pb-4">
                        <h2 class="text-lg font-bold tracking-tight">{{ $editingId ? 'Edit note' : 'New note' }}</h2>
                        <button type="button" wire:click="$set('showModal', false)" aria-label="Close"
                                class="grid size-8 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">✕</button>
                    </header>

                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 pb-2">
                        <div>
                            <label class="{{ $labelClass }}" for="note-title">Title <span class="font-medium text-ink-400">(optional)</span></label>
                            <input id="note-title" type="text" wire:model="form_title" autofocus placeholder="Supplier phone number" class="{{ $field }}">
                            @error('form_title') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <span class="{{ $labelClass }}">Note</span>
                            <x-rich-text-editor
                                model="form_body"
                                placeholder="Write it down…"
                                key="note-editor-{{ $editingId ?? 'new' }}-{{ $editorSession }}" />

                            @error('form_body') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="note-files">
                                Files
                                <span class="font-medium text-ink-400">
                                    (PDF, Word, Excel or an image · up to
                                    {{ \Illuminate\Support\Number::fileSize(\App\Models\Attachment::effectiveMaxKilobytes() * 1024, precision: 0) }}
                                    each)
                                </span>
                            </label>

                            <input id="note-files" type="file" wire:model="uploads" multiple
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp"
                                   class="w-full cursor-pointer rounded-xl border border-dashed border-ink-300 px-3.5 py-2.5 text-[13px] text-ink-500 transition file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-[13px] file:font-bold file:text-ink-700 hover:border-brand-400 dark:border-ink-600 dark:text-ink-400 dark:file:bg-ink-700 dark:file:text-ink-100">

                            @error('uploads') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror

                            <div wire:loading wire:target="uploads" class="mt-1.5 text-[12px] font-semibold text-ink-400">Uploading…</div>

                            {{-- Chosen in this session, not yet on the note --}}
                            @if (count($uploads) > 0)
                                <ul class="mt-2 flex flex-col gap-1.5">
                                    @foreach ($uploads as $index => $upload)
                                        <li class="flex items-center gap-2.5 rounded-xl border border-brand-200 bg-brand-50/50 px-3 py-2 dark:border-brand-800 dark:bg-brand-950/40"
                                            wire:key="pending-{{ $index }}-{{ $upload->getFilename() }}">
                                            <span class="min-w-0 flex-1 truncate text-[13px] font-semibold">{{ $upload->getClientOriginalName() }}</span>
                                            <button type="button" wire:click="removeUpload({{ $index }})"
                                                    aria-label="Remove {{ $upload->getClientOriginalName() }}"
                                                    class="grid size-6 shrink-0 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-red-600 dark:hover:bg-ink-800">✕</button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Already saved against the note --}}
                            @if ($editingNote && $editingNote->attachments->isNotEmpty())
                                <ul class="mt-2 flex flex-col gap-1.5">
                                    @foreach ($editingNote->attachments as $attachment)
                                        <li class="flex items-center gap-2.5 rounded-xl border border-ink-200 px-3 py-2 dark:border-ink-700"
                                            wire:key="saved-{{ $attachment->id }}">
                                            <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-ink-100 text-[9px] font-bold uppercase text-ink-500 dark:bg-ink-800 dark:text-ink-300">
                                                {{ $attachment->extension() ?: 'file' }}
                                            </span>
                                            <a href="{{ $attachment->downloadUrl() }}" class="min-w-0 flex-1 truncate text-[13px] font-semibold hover:text-brand-700 dark:hover:text-brand-200">
                                                {{ $attachment->original_name }}
                                            </a>
                                            <span class="shrink-0 text-[12px] text-ink-400">{{ $attachment->humanSize() }}</span>
                                            @can('delete', $attachment)
                                                <button type="button" wire:click="removeAttachment({{ $attachment->id }})"
                                                        wire:confirm="Remove {{ $attachment->original_name }} from this note?"
                                                        aria-label="Remove {{ $attachment->original_name }}"
                                                        class="grid size-6 shrink-0 place-items-center rounded-full text-ink-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950">✕</button>
                                            @endcan
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div>
                            <span class="{{ $labelClass }}">Colour</span>
                            <div class="flex flex-wrap gap-2" role="group" aria-label="Note colour">
                                @foreach (\App\Models\Note::COLORS as $hex)
                                    <button type="button" wire:click="$set('form_color', '{{ $hex }}')"
                                            aria-label="Use {{ $hex }}" aria-pressed="{{ $form_color === $hex ? 'true' : 'false' }}"
                                            class="size-8 rounded-full transition {{ $form_color === $hex ? 'ring-2 ring-ink-900 ring-offset-2 dark:ring-white dark:ring-offset-ink-900' : 'hover:scale-110' }}"
                                            style="background-color: {{ $hex }}"></button>
                                @endforeach
                            </div>
                            @error('form_color') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <span class="{{ $labelClass }}">Who can read it</span>
                            <div class="flex gap-1 rounded-xl bg-ink-100 p-1 dark:bg-ink-800" role="group" aria-label="Who can read it">
                                @foreach ([['tenant', 'Whole workspace'], ['private', 'Only me']] as [$value, $text])
                                    <button type="button" wire:click="$set('form_visibility', '{{ $value }}')"
                                            aria-pressed="{{ $form_visibility === $value ? 'true' : 'false' }}"
                                            class="flex-1 rounded-lg px-3 py-1.5 text-[13px] font-bold transition
                                                   {{ $form_visibility === $value
                                                       ? 'bg-white text-ink-900 shadow-sm dark:bg-ink-700 dark:text-white'
                                                       : 'text-ink-500 hover:text-ink-700 dark:text-ink-400 dark:hover:text-ink-200' }}">
                                        {{ $text }}
                                    </button>
                                @endforeach
                            </div>
                            @error('form_visibility') <p class="mt-1.5 text-[13px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <label class="flex items-center gap-2.5 text-[14px] font-semibold">
                            <input type="checkbox" wire:model="form_pinned"
                                   class="size-4 rounded border-ink-300 text-brand-600 focus:ring-brand-400 dark:border-ink-600 dark:bg-ink-800">
                            Pin to the top of the board
                        </label>
                    </div>

                    <footer class="pwa-inset-bottom mt-2 flex shrink-0 items-center gap-2 border-t border-ink-200/80 px-6 py-4 dark:border-ink-800">
                        <div class="ml-auto flex gap-2">
                            <button type="button" wire:click="$set('showModal', false)"
                                    class="rounded-xl border border-ink-200 px-4 py-2.5 text-[14px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="rounded-xl bg-brand-600 px-5 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                                {{ $editingId ? 'Save changes' : 'Save note' }}
                            </button>
                        </div>
                    </footer>
                </form>
            </div>
        </div>
    @endif
</div>
