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
 * anything else — colours, fonts, images, tables — would be typed happily and
 * then stripped on save, which reads as the editor losing your work. Capping
 * it here means what you see is what is stored.
 */
const FORMATS = ['bold', 'italic', 'strike', 'header', 'blockquote', 'code-block', 'list', 'link'];

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
    window.Alpine.data('quillEditor', (model, placeholder = '') => {
        let quill = null;
        let lastRange = null;
        let queued = [];
        let syncQueued = false;

        return {
            failed: false,

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

                quill = new Quill(host, {
                    theme: 'snow',
                    placeholder,
                    formats: FORMATS,
                    modules: { toolbar: this.$refs.toolbar },
                });

                icons.forEach((html, button) => {
                    button.innerHTML = html;
                });

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

                // An untouched editor still holds "<p><br></p>", which is markup
                // but not a report.
                return quill.getText().trim() === '' ? '' : quill.getSemanticHTML().trim();
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
