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
    window.Alpine.data('quillEditor', (model, value = '', placeholder = '') => ({
        quill: null,

        /** Where the cursor was, so inserts land there and not at the top. */
        lastRange: null,

        /** Anything asked for before Quill finished loading. */
        queued: [],

        async mount() {
            const Quill = await loadQuill();

            this.quill = new Quill(this.$refs.editor, {
                placeholder,
                formats: FORMATS,
                modules: { toolbar: this.$refs.toolbar },
            });

            if (value) {
                // 'silent' so restoring the saved body is not itself an edit.
                this.quill.clipboard.dangerouslyPasteHTML(value, 'silent');
            }

            this.quill.on('text-change', () => {
                // Third argument false: store the value without a round trip.
                // Re-rendering on every keystroke would be wasteful and, behind
                // wire:ignore, out of step with what is on screen.
                this.$wire.set(model, this.html(), false);
            });

            // Clicking a button outside the editor blurs it and clears Quill's
            // selection, so the last real cursor position is remembered here.
            this.quill.on('selection-change', (range) => {
                if (range) {
                    this.lastRange = range;
                }
            });

            // Quill loads asynchronously; anything clicked in the meantime was
            // parked rather than dropped.
            this.queued.splice(0).forEach((html) => this.insert(html));
        },

        /**
         * Quill 2 renders *both* list types in the DOM as <ul> with the real
         * type hidden on `li[data-list]`. Saving innerHTML would therefore
         * turn every numbered list into bullets once the sanitiser dropped
         * that attribute. getSemanticHTML() emits proper <ol>/<ul> instead.
         */
        html() {
            if (!this.quill) {
                return '';
            }

            // An untouched editor still holds "<p><br></p>", which is markup
            // but not a report.
            return this.quill.getText().trim() === '' ? '' : this.quill.getSemanticHTML().trim();
        },

        /**
         * Writes markup in at the cursor — a task pulled into a report, or a
         * self-filling field dropped into a header.
         */
        insert(html) {
            if (!html) {
                return;
            }

            if (!this.quill) {
                this.queued.push(html);

                return;
            }

            // End of the document when nothing has been clicked yet. getLength()
            // counts Quill's trailing newline, hence the -1.
            const index = this.lastRange?.index ?? Math.max(this.quill.getLength() - 1, 0);

            this.quill.clipboard.dangerouslyPasteHTML(index, html, 'user');

            // dangerouslyPasteHTML moves the cursor silently, so selection-change
            // does not fire and lastRange would otherwise go stale.
            this.lastRange = this.quill.getSelection() ?? { index: this.quill.getLength() - 1, length: 0 };
            this.quill.focus();
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
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Blocked by the browser, private mode, or an insecure origin.
        });
    });
}
