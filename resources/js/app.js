/*
 * Rich-text editing for daily reports.
 *
 * Trix is ~50KB gzipped and only one page uses it, so it is a dynamic import
 * rather than part of the main bundle — /login should not pay for an editor
 * it never shows. Custom elements upgrade whenever their definition lands, so
 * loading after the <trix-editor> is in the DOM is fine.
 */
const loadTrix = (() => {
    let pending;

    return () => (pending ??= import('trix'));
})();

/*
 * The editor arrives inside a Livewire modal, long after first paint, so the
 * page is watched until one shows up. The observer disconnects on the first
 * hit; the module is cached from then on.
 */
const watchForEditor = () => {
    if (document.querySelector('trix-editor')) {
        loadTrix();

        return;
    }

    new MutationObserver((_records, observer) => {
        if (document.querySelector('trix-editor')) {
            observer.disconnect();
            loadTrix();
        }
    }).observe(document.body, { childList: true, subtree: true });
};

document.addEventListener('DOMContentLoaded', watchForEditor);
document.addEventListener('livewire:navigated', watchForEditor);

/*
 * Trix offers file attachments by default, but there is no upload endpoint
 * behind them: a dropped file would be embedded as a data: URL that the
 * sanitiser then strips, losing it silently. Refusing up front is honest
 * about what a report or note can hold. The toolbar's paperclip is hidden in
 * CSS for the same reason.
 */
addEventListener('trix-file-accept', (event) => event.preventDefault());

/*
 * Writing a task into the open editor.
 *
 * The report page dispatches this rather than setting the Livewire property,
 * because the editor sits behind wire:ignore — anything written server-side
 * would not appear until the dialog was reopened. Inserting through Trix's own
 * API also means the change fires trix-change, so the property stays in step
 * without anything special.
 */
addEventListener('report-insert', (event) => {
    const element = document.querySelector('trix-editor');
    const html = event.detail?.html ?? event.detail?.[0]?.html;

    if (!element?.editor || !html) {
        return;
    }

    element.focus();
    element.editor.insertHTML(html);
});

/*
 * Word-style tooltips.
 *
 * Trix labels its buttons "Bullets", "Numbers", "Increase Level" — accurate,
 * but not what a word processor calls them, and with no hint that ⌘B works.
 * Naming them the way people already expect, and spelling out the shortcut,
 * is most of what makes a toolbar readable.
 */
const BUTTON_TITLES = {
    bold: 'Bold',
    italic: 'Italic',
    strike: 'Strikethrough',
    href: 'Insert link',
    heading1: 'Heading',
    quote: 'Quote',
    code: 'Code block',
    bullet: 'Bulleted list',
    number: 'Numbered list',
    decreaseNestingLevel: 'Decrease indent',
    increaseNestingLevel: 'Increase indent',
    undo: 'Undo',
    redo: 'Redo',
};

/** ⌘ on a Mac, Ctrl everywhere else — the same convention Word follows. */
const modifierKey = () =>
    /mac|iphone|ipad|ipod/i.test(navigator.platform || navigator.userAgent) ? '⌘' : 'Ctrl+';

const shortcutHint = (key) => {
    if (!key) {
        return '';
    }

    const parts = key.split('+');
    const letter = parts.pop().toUpperCase();
    const shift = parts.includes('shift') ? 'Shift+' : '';

    return ` (${modifierKey()}${shift}${letter})`;
};

addEventListener('trix-initialize', (event) => {
    const toolbar = event.target.toolbarElement;

    if (!toolbar) {
        return;
    }

    toolbar.querySelectorAll('button[data-trix-attribute], button[data-trix-action]').forEach((button) => {
        const name = button.dataset.trixAttribute || button.dataset.trixAction;
        const label = BUTTON_TITLES[name];

        if (!label) {
            return;
        }

        button.setAttribute('title', label + shortcutHint(button.dataset.trixKey));
        // The button's text is its accessible name; the icon covers it visually.
        button.setAttribute('aria-label', label);
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
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Blocked by the browser, private mode, or an insecure origin.
        });
    });
}
