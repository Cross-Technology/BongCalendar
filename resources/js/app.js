/*
 * Rich-text editing for reports and notes.
 *
 * Quill is ~45KB gzipped and only a few dialogs use it, so it is a dynamic
 * import rather than part of the main bundle — /login should not pay for an
 * editor it never shows. The promise is memoised, so a second editor on the
 * page reuses the first load.
 */
const loadQuill = (() => {
    let pending;

    return () => (pending ??= import('quill').then((module) => module.default));
})();

/**
 * Alpine has no teardown for Quill, and Quill 2 ships no destroy().
 *
 * That matters because Quill routes every document `selectionchange` to every
 * `.ql-container` on the page. A dialog that closes leaves its editor behind
 * for a moment, and events dispatched to that dead instance throw
 * "Cannot read properties of null (reading 'offset')" — inside a forEach, so
 * the live editor stops being updated too. Dropping the class takes the dead
 * one out of that list.
 */
const retireEditor = (container) => {
    container?.classList.remove('ql-container');

    if (container) {
        delete container.dataset.quillMounted;
    }
};

/*
 * What the editor is allowed to produce.
 *
 * Deliberately the same shortlist the server allows (config/purifier.php):
 * anything else — colours, fonts, tables — would be typed happily and then
 * stripped on save, which reads as the editor losing your work. Capping it
 * here means what you see is what is stored.
 *
 * `image` is added only where the caller passes an upload config, because only
 * notes accept pictures; in a report the button is absent and so is the format.
 */
const FORMATS = ['bold', 'italic', 'strike', 'header', 'blockquote', 'code-block', 'list', 'link'];

/*
 * Where an image in a body is allowed to come from.
 *
 * The same rule the sanitiser applies (URI.DisableExternalResources in
 * config/purifier.php), enforced again in the editor so the two agree: an
 * image pasted in from another site would be shown while typing and then
 * silently dropped on save, which looks exactly like losing your work.
 */
const isLocalImage = (src) => typeof src === 'string' && src.startsWith('/attachments/');

/** The CSRF token, for the one upload that does not go through Livewire. */
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/**
 * Turns a failed upload into something worth showing.
 *
 * A rejected file is a 422 carrying the validation message — "The image must
 * not be greater than 5120 kilobytes" — which is the useful thing to say. A
 * body too big for PHP never reaches Laravel and comes back as HTML, so the
 * parse is allowed to fail and a plain sentence stands in.
 */
const uploadFailureMessage = async (response) => {
    try {
        const body = await response.json();

        return body?.errors?.file?.[0] ?? body?.message ?? null;
    } catch {
        return null;
    }
};

document.addEventListener('alpine:init', () => {
    /*
     * The Quill instance lives in a closure, NOT on the Alpine component.
     *
     * Alpine makes component data deeply reactive, so assigning the editor onto
     * the component hands back a Proxy. Quill compares objects by identity internally —
     * `blot.offset(this.scroll)` walks parents looking for the very same scroll
     * object — and a proxied scroll never matches the raw one its blots hold.
     * The lookup returns null and every selection read throws
     * "Cannot read properties of null (reading 'offset')": the editor shows its
     * text and styles it, but takes no typing and saves nothing.
     *
     * Only `failed` stays on the component, because x-show has to react to it.
     */
    window.Alpine.data('quillEditor', (model, placeholder = '', imageUpload = null) => {
        let quill = null;
        let lastRange = null;
        let queued = [];
        let syncQueued = false;

        return {
            failed: false,

            // On the component rather than in the closure, because the
            // "uploading" line and the error message are bound to them.
            uploading: false,
            uploadError: '',

            async mount() {
                const host = this.$refs.editor;

                // Claim the element before the first await, so a second
                // initialisation cannot race past this guard and build a
                // second editor on the same node.
                if (!host || host.dataset.quillMounted === 'true') {
                    return;
                }

                host.dataset.quillMounted = 'true';

                let Quill;

                try {
                    Quill = await loadQuill();
                } catch {
                    // The chunk did not arrive. Fall back to a plain textarea
                    // rather than leaving a dead box nobody can type into.
                    this.failed = true;

                    return;
                }

                /*
                 * Snow is the theme Quill actually supports; the base one
                 * leaves the toolbar half-wired. It overwrites button contents
                 * with its own icons, so ours are put back afterwards.
                 */
                const icons = new Map();

                this.$refs.toolbar.querySelectorAll('button').forEach((button) => {
                    icons.set(button, button.innerHTML);
                });

                const modules = {
                    toolbar: {
                        container: this.$refs.toolbar,
                        // Quill's own image handler inlines the file as a
                        // base64 data URL. That would be stripped on save —
                        // data: is not an allowed scheme — and a note's markup
                        // is not the place to carry a megabyte of image, so
                        // the picker and the upload are ours.
                        handlers: imageUpload ? { image: () => this.pickImage() } : {},
                    },
                };

                if (imageUpload) {
                    /*
                     * Pasting a screenshot and dragging a file in are the two
                     * ways people actually put a picture in a note, and Quill
                     * routes both through its uploader. Same upload as the
                     * button; left alone, its default handler writes base64.
                     *
                     * Added rather than set to undefined, because the key
                     * being present at all is what Quill reads as a
                     * configured module.
                     */
                    modules.uploader = {
                        mimetypes: ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
                        handler: (range, files) => this.uploadImages(files, range?.index),
                    };
                }

                quill = new Quill(host, {
                    theme: 'snow',
                    placeholder,
                    formats: imageUpload ? [...FORMATS, 'image'] : FORMATS,
                    modules,
                });

                icons.forEach((html, button) => {
                    button.innerHTML = html;
                });

                if (imageUpload) {
                    /*
                     * Paste a block of text from a web page and its pictures
                     * come along, pointing at that site. The sanitiser refuses
                     * them, so keeping them here would show the writer an image
                     * that vanishes the moment they save. Dropping them on the
                     * way in makes the editor tell the truth — and it leaves
                     * our own `/attachments/…` images alone, so copying a
                     * paragraph from one note into another still carries them.
                     */
                    const Delta = Quill.import('delta');

                    quill.clipboard.addMatcher('IMG', (node, delta) => (
                        isLocalImage(node.getAttribute('src')) ? delta : new Delta()
                    ));
                }

                // Handlers before the content is loaded: if loading ever throws,
                // the editor must still sync what gets typed afterwards.
                quill.on('text-change', () => this.scheduleSync());

                quill.on('selection-change', (range, previous) => {
                    if (range) {
                        lastRange = range;
                    } else if (previous) {
                        // Losing focus is a second chance to push the latest text.
                        this.scheduleSync();
                    }
                });

                try {
                    // Read the body from Livewire rather than from an attribute
                    // rendered earlier, so a reused element cannot show stale text.
                    const initial = this.$wire.get(model) ?? '';

                    if (initial) {
                        quill.setContents(quill.clipboard.convert({ html: initial, text: '' }));
                    }
                } catch (error) {
                    console.error('Could not load the saved content into the editor.', error);
                }

                // Anything clicked while the chunk was still loading.
                queued.splice(0).forEach((html) => this.insert(html));
            },

            destroy() {
                const host = this.$refs.editor;

                // Only retire an editor that is genuinely gone: Alpine also
                // calls destroy when re-initialising a live element, and
                // releasing the claim there would allow a second Quill.
                if (host && !host.isConnected) {
                    retireEditor(host);
                    quill = null;
                }
            },

            /** Hands the current markup to Livewire without a round trip. */
            sync() {
                this.$wire.set(model, this.html(), false);
            },

            /** Queues a sync for after Quill has finished its own update. */
            scheduleSync() {
                if (syncQueued) {
                    return;
                }

                syncQueued = true;

                queueMicrotask(() => {
                    syncQueued = false;

                    if (quill) {
                        this.sync();
                    }
                });
            },

            /**
             * Quill 2 renders *both* list types in the DOM as <ul> with the real
             * type hidden on `li[data-list]`. Saving innerHTML would turn every
             * numbered list into bullets once the sanitiser dropped that
             * attribute; getSemanticHTML() emits proper <ol>/<ul>.
             */
            html() {
                if (!quill) {
                    return '';
                }

                // getText() counts characters, and an embed is not one — so a
                // note that is nothing but a pasted screenshot reads as empty
                // and would be thrown away on save. Ask the document instead.
                const hasEmbed = quill.getContents().ops.some((op) => typeof op.insert === 'object');

                // An untouched editor still holds "<p><br></p>", which is markup
                // but not a report.
                return quill.getText().trim() === '' && !hasEmbed ? '' : quill.getSemanticHTML().trim();
            },

            /* ------------------------------------------------------- images */

            /**
             * The cursor, or the end of the note if there is not one.
             *
             * Asked of Quill rather than read off `lastRange`: the remembered
             * range is only as fresh as the last selection-change, and typing
             * does not always produce one, so it can still say 0 after a
             * paragraph has been written.
             */
            caret() {
                return quill.getSelection()?.index
                    ?? lastRange?.index
                    ?? Math.max(quill.getLength() - 1, 0);
            },

            /**
             * The toolbar button. A hidden input rather than a styled one: it
             * exists for a single click and is thrown away, so nothing has to
             * be kept in the DOM or reset between pictures.
             */
            pickImage() {
                if (!imageUpload || !quill) {
                    return;
                }

                /*
                 * Where the cursor is *now*, read while the editor still has
                 * it. Opening the file dialog takes the focus away, and by the
                 * time a file comes back the selection is long gone — which is
                 * how a picture ends up at the top of a note that was already
                 * half written.
                 */
                const at = this.caret();

                const input = document.createElement('input');

                input.type = 'file';
                input.accept = imageUpload.accept ?? 'image/*';
                input.multiple = true;

                input.addEventListener('change', () => {
                    this.uploadImages(input.files, at);
                });

                input.click();
            },

            /**
             * Uploads each picture and draws it where the cursor is.
             *
             * One at a time, awaited: two uploads racing would insert in
             * whichever order the network happened to finish, which is not the
             * order they were picked in.
             */
            async uploadImages(files, index = null) {
                const images = Array.from(files ?? []).filter((file) => file.type?.startsWith('image/'));

                if (images.length === 0 || !imageUpload) {
                    return;
                }

                this.uploadError = '';
                this.uploading = true;

                // Where the first one lands. After that each insert moves the
                // cursor on, so the rest follow it.
                let at = index ?? this.caret();

                try {
                    for (const file of images) {
                        at = await this.uploadImage(file, at);
                    }
                } finally {
                    this.uploading = false;
                }
            },

            /** @returns the index the next image should go at. */
            async uploadImage(file, at) {
                /*
                 * Checked here as well as on the server, because a file over
                 * PHP's own post limit is thrown away before any of our
                 * validation runs — the request comes back as a bare 413 with
                 * nothing to say why. The server is still the authority.
                 */
                if (imageUpload.maxBytes && file.size > imageUpload.maxBytes) {
                    this.uploadError = `${file.name} is too large — images can be up to `
                        + `${Math.round(imageUpload.maxBytes / 1048576)}MB.`;

                    return at;
                }

                const body = new FormData();

                body.append('file', file);

                if (imageUpload.noteId) {
                    body.append('note_id', imageUpload.noteId);
                }

                try {
                    const response = await fetch(imageUpload.url, {
                        method: 'POST',
                        body,
                        credentials: 'same-origin',
                        headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                    });

                    if (!response.ok) {
                        this.uploadError = await uploadFailureMessage(response)
                            ?? `${file.name} could not be uploaded.`;

                        return at;
                    }

                    const { url } = await response.json();

                    quill.insertEmbed(at, 'image', url, 'user');

                    // Past the image, so the next one — or the next thing
                    // typed — goes after it rather than in front of it.
                    const next = at + 1;

                    quill.setSelection(next, 0, 'silent');
                    lastRange = { index: next, length: 0 };

                    this.sync();

                    return next;
                } catch {
                    // Offline, or the request never landed.
                    this.uploadError = `${file.name} could not be uploaded — check your connection.`;

                    return at;
                }
            },

            /** Writes markup in at the cursor — a task pulled into a report. */
            insert(html) {
                if (!html) {
                    return;
                }

                if (!quill) {
                    queued.push(html);

                    return;
                }

                const index = lastRange?.index ?? Math.max(quill.getLength() - 1, 0);

                try {
                    quill.clipboard.dangerouslyPasteHTML(index, html, 'user');
                    lastRange = quill.getSelection() ?? { index: quill.getLength() - 1, length: 0 };
                    quill.focus();
                } catch (error) {
                    console.error('Could not write that into the editor.', error);
                }

                // Whatever happened to the cursor, what is on screen is the truth.
                this.sync();
            },
        };
    });

    /*
     * The picker behind <x-multi-select>: a one-line trigger that expands a
     * searchable list of checkboxes.
     *
     * It expands in the flow of the page rather than floating over it. Both
     * places it is used — the task dialog and the detail drawer — are
     * `overflow-y-auto` columns, and an absolutely positioned menu is clipped
     * at their edge: pick the last department in a long list and the options
     * would be cut off by the bottom of the dialog.
     *
     * The selection itself lives in the Livewire property, read through $wire
     * so the trigger always says what the server holds. Ticking a box writes
     * back without a round trip; `commitOnClose` sends one request when the
     * list is folded away, so picking five people costs one request rather
     * than five.
     */
    window.Alpine.data('multiSelect', (model, commitOnClose = false) => ({
        open: false,
        search: '',
        dirty: false,
        pending: null,

        get selected() {
            return this.$wire.get(model) ?? [];
        },

        get count() {
            return this.selected.length;
        },

        has(id) {
            return this.selected.some((value) => Number(value) === Number(id));
        },

        toggleId(id) {
            const next = this.has(id)
                ? this.selected.filter((value) => Number(value) !== Number(id))
                : [...this.selected, id];

            // `false`: hold it on the client. The list stays put while the
            // user ticks along, and one request carries the lot on close.
            this.$wire.$set(model, next, false);
            this.touched();
        },

        clear() {
            this.$wire.$set(model, [], false);
            this.touched();
        },

        /*
         * A held change is sent when the list is folded away. The timer is the
         * backstop: a panel that saves as you go must not lose a pick just
         * because the list was left open, and a second's pause after the last
         * tick still costs one request rather than one per name.
         */
        touched() {
            this.dirty = true;

            if (! commitOnClose) {
                return;
            }

            clearTimeout(this.pending);
            this.pending = setTimeout(() => this.commit(), 1200);
        },

        commit() {
            clearTimeout(this.pending);

            if (! this.dirty) {
                return;
            }

            this.dirty = false;

            // Queued as a real update this time, so the component's own
            // updated() hook runs and the change is saved.
            this.$wire.$set(model, this.selected, true);
        },

        /** Matches on the label, so typing "sal" finds Sales. */
        matches(label) {
            return this.search.trim() === ''
                || label.toLowerCase().includes(this.search.trim().toLowerCase());
        },

        anyMatch(labels) {
            return labels.some((label) => this.matches(label));
        },

        /** What the trigger reads when the list is folded away. */
        get summary() {
            const names = this.selected.map((id) => this.labelFor(id)).filter(Boolean);

            if (names.length === 0) {
                return '';
            }

            // Two names fit; past that the count says more than a truncated list.
            return names.length <= 2 ? names.join(', ') : `${names.length} selected`;
        },

        /*
         * Read off the rendered list rather than a map captured at mount, so a
         * rename or a newly added department shows straight after a re-render.
         */
        labelFor(id) {
            return this.$refs.list?.querySelector(`[data-id="${id}"]`)?.dataset.label ?? '';
        },

        toggle() {
            this.open ? this.close() : this.expand();
        },

        expand() {
            this.open = true;
            this.$nextTick(() => this.$refs.search?.focus());
        },

        close() {
            if (! this.open) {
                return;
            }

            this.open = false;
            this.search = '';

            if (commitOnClose) {
                this.commit();
            }
        },

        destroy() {
            clearTimeout(this.pending);
        },
    }));
});

/*
 * Service worker registration.
 *
 * The PWA is a pure enhancement: if any of this fails the app carries on
 * exactly as before, so nothing here is allowed to throw.
 *
 * There is no update-and-reload dance on purpose. The worker only caches
 * content-hashed build output and never caches HTML, so fresh markup always
 * references filenames that miss the cache and get fetched — a deploy cannot
 * leave a tab running yesterday's bundle.
 */
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        // `updateViaCache: 'none'` keeps the worker script itself out of the
        // HTTP cache. Without it a browser may go on serving a cached /sw.js
        // for up to a day, so a deployed change to the caching rules would sit
        // unapplied on devices that already had the app installed.
        navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).catch(() => {
            // Blocked by the browser, private mode, or an insecure origin.
        });
    });
}
