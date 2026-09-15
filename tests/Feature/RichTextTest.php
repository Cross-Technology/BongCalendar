<?php

namespace Tests\Feature;

use App\Services\RichTextService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RichTextTest extends TestCase
{
    protected RichTextService $richText;

    protected function setUp(): void
    {
        parent::setUp();

        $this->richText = app(RichTextService::class);
    }

    /**
     * Every block the editor can produce has to survive the allowlist. Lists were
     * the one people noticed, but a missing tag here silently eats formatting
     * with no error anywhere.
     */
    public static function editorMarkup(): array
    {
        return [
            'bullet list' => ['<ul><li>one</li><li>two</li></ul>'],
            'numbered list' => ['<ol><li>first</li><li>second</li></ol>'],
            'nested list' => ['<ul><li>one<ul><li>deeper</li></ul></li></ul>'],
            'heading' => ['<h1>Heading</h1>'],
            'quote' => ['<blockquote>quoted</blockquote>'],
            'code' => ['<pre>code here</pre>'],
            'bold' => ['<div><strong>bold</strong></div>'],
            'italic' => ['<div><em>italic</em></div>'],
            'strikethrough' => ['<div><del>gone</del></div>'],
            'line break' => ['<div>one<br>two</div>'],
        ];
    }

    #[DataProvider('editorMarkup')]
    public function test_formatting_the_editor_produces_survives_sanitising(string $html): void
    {
        $this->assertSame($html, $this->richText->sanitize($html));
    }

    public function test_a_list_reads_as_separate_lines_in_plain_text(): void
    {
        $text = $this->richText->toText('<div>Shopping</div><ul><li>milk</li><li>bread</li></ul>');

        $this->assertSame("Shopping\nmilk\nbread", $text);
    }

    /**
     * The editor and the saved note have to share their typography.
     *
     * Tailwind's preflight resets `ol, ul, menu { list-style: none }`, and that
     * reaches inside the editor too. With the rules scoped to the rendered
     * output alone, a bulleted list showed no bullets while it was being
     * typed — correct once saved, which made it look like the editor was
     * broken. This is the guard for that.
     */
    /**
     * The toolbar markup is ours; Quill only fills the buttons with icons and
     * keeps `ql-active` in step with the cursor. These are the rules that make
     * it legible.
     */
    public function test_the_toolbar_buttons_are_sized_and_legible(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.rich-editor__toolbar button \{[^}]*width: 2rem/s', $css);

        // Scoped under .rich-editor so they outrank snow's two-class
        // selectors (.ql-toolbar.ql-snow), which would otherwise win.
        $this->assertStringContainsString('.rich-editor .rich-editor__toolbar', $css);
        $this->assertStringContainsString('.rich-editor .ql-toolbar.ql-snow', $css);

        // The applied-at-the-cursor state has to be visible.
        $this->assertStringContainsString('.rich-editor__toolbar button.ql-active', $css);
        $this->assertStringContainsString('.rich-editor .rich-editor__toolbar button', $css);
    }

    /**
     * Every toolbar button has to carry its own icon.
     *
     * Quill injects icons only from its snow/bubble themes, and we load
     * neither — so a button left empty here renders as a blank square that
     * only appears when hovered. This is the guard for that.
     */
    public function test_no_toolbar_button_is_left_empty(): void
    {
        $template = file_get_contents(resource_path('views/components/rich-text-editor.blade.php'));

        preg_match_all('/<button\b[^>]*class="ql-[^"]*"[^>]*>(.*?)<\/button>/s', $template, $matches);

        $this->assertGreaterThanOrEqual(10, count($matches[0]), 'Expected the full toolbar.');

        foreach ($matches[0] as $index => $button) {
            $contents = trim($matches[1][$index]);

            $this->assertNotSame('', $contents, "A toolbar button has no icon: {$button}");
            $this->assertMatchesRegularExpression(
                '/<svg|rich-editor__glyph/',
                $contents,
                'A toolbar button carries neither an icon nor a glyph.',
            );
        }

        // Drawn with currentColor, so hover and dark mode need no inverting.
        $this->assertStringContainsString('stroke="currentColor"', $template);
    }

    /**
     * Typography has to cover the editor as well as the saved markup, or text
     * looks one way while being typed and another once saved.
     */
    public function test_the_stylesheet_dresses_the_editor_and_the_saved_note_alike(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['a', 'blockquote', 'pre', 'strong'] as $tag) {
            $this->assertStringContainsString(
                ":is(.rich-text, .ql-editor) {$tag}",
                $css,
                "Rich-text `{$tag}` styling must cover the live editor as well as saved markup.",
            );
        }

        // Headings come out as h1 (older content) or h2 (the editor's button).
        $this->assertStringContainsString(':is(.rich-text, .ql-editor) :is(h1, h2)', $css);
    }

    /**
     * Lists are the one thing deliberately NOT shared.
     *
     * Quill draws its own markers inside the editor with `li[data-list]` and
     * sets `list-style: none`; adding native markers there shows two bullets
     * per line. The saved HTML is plain <ul>/<ol>, which Tailwind's preflight
     * strips, so that half does need dressing.
     */
    public function test_list_markers_are_dressed_for_saved_markup_only(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.rich-text ul', $css);
        $this->assertStringContainsString('list-style-type: disc', $css);
        $this->assertStringContainsString('list-style-type: decimal', $css);

        $this->assertStringNotContainsString(
            ':is(.rich-text, .ql-editor) ul',
            $css,
            'Styling list markers inside the editor doubles up with the ones Quill draws.',
        );
    }

    /**
     * Quill 2 renders *both* list types in the DOM as <ul> with the real type
     * hidden on `li[data-list]`. Saving innerHTML would turn every numbered
     * list into bullets the moment the sanitiser dropped that attribute;
     * getSemanticHTML() emits proper <ol>/<ul>.
     */
    public function test_the_editor_saves_semantic_html(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('getSemanticHTML', $js);
        $this->assertStringNotContainsString('root.innerHTML', $js);
    }

    /**
     * The change handler must be registered before the saved body is loaded.
     *
     * Loading it ends in Quill calling setSelection(), which can throw in a
     * dialog that has only just been inserted. With the handler registered
     * afterwards, that throw skipped it: the text was on screen and the
     * toolbar worked, so the editor looked healthy — but nothing ever synced,
     * and saving wrote the old body straight back and closed without
     * complaint. Creating was unaffected, because there was nothing to load.
     */
    public function test_the_editor_listens_before_it_loads(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $listens = strpos($js, "quill.on('text-change'");
        $loads = strpos($js, 'dangerouslyPasteHTML');

        $this->assertNotFalse($listens, 'The editor should listen for changes.');
        $this->assertNotFalse($loads, 'The editor should load the saved body.');

        $this->assertLessThan(
            $loads,
            $listens,
            'Registering the change handler after loading means a failed load silently stops the editor syncing.',
        );

        // And the load itself must not be able to take the editor down.
        $this->assertMatchesRegularExpression(
            '/try \{\s*\n[^}]*dangerouslyPasteHTML/s',
            $js,
            'Loading the saved body should be guarded.',
        );
    }

    /**
     * Loading the saved body must not ask Quill to move the cursor.
     *
     * dangerouslyPasteHTML() finishes with setSelection(), which reads the
     * browser's selection before a freshly opened dialog has settled and
     * throws "Cannot read properties of null (reading 'offset')".
     * convert() + setContents() loads the same markup and never touches it.
     */
    public function test_loading_the_saved_body_does_not_move_the_cursor(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('clipboard.convert(', $js);
        $this->assertStringContainsString('setContents(quill.clipboard.convert(', $js);
        $this->assertStringNotContainsString('dangerouslyPasteHTML(initial', $js);
    }

    /**
     * Two Quills on one node is what makes every selection read throw: the
     * second builds its document from markup the first already owns, so
     * scroll.find() starts returning null.
     */
    public function test_the_editor_cannot_be_mounted_twice_onto_one_element(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $template = file_get_contents(resource_path('views/components/rich-text-editor.blade.php'));

        // Belt: mount() bails if the element already holds an editor.
        // Belt: the element is claimed before the first await, so a second
        // initialisation cannot race past the guard.
        $this->assertStringContainsString("host.dataset.quillMounted = 'true';", $js);
        $this->assertStringContainsString("dataset.quillMounted === 'true'", $js);

        // Braces: wire:ignore on the Alpine root, so Livewire never morphs it
        // and Alpine never re-initialises it in the first place.
        $this->assertMatchesRegularExpression(
            '/<div wire:ignore\s+wire:key="\{\{ \$key \}\}"\s+x-data="quillEditor/',
            $template,
            'wire:ignore must sit on the same element as x-data.',
        );
    }

    /**
     * Quill routes every document selectionchange to every `.ql-container` on
     * the page. A dialog that closes leaves its editor behind for a moment,
     * and dispatching to that dead instance throws inside a forEach — which
     * stops the live editor updating too. Retiring the container on teardown
     * takes it out of that list.
     */
    public function test_a_closed_editor_is_retired(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('destroy()', $js);
        $this->assertStringContainsString("classList.remove('ql-container')", $js);
    }

    /**
     * The base theme leaves the toolbar half-wired — clicking a button calls
     * quill.focus() into a selection it cannot resolve — and never builds the
     * link dialog. Snow is the configuration the library supports.
     */
    public function test_the_editor_uses_the_supported_theme(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("theme: 'snow'", $js);
        $this->assertStringContainsString('quill/dist/quill.snow.css', $css);

        // Snow overwrites button contents with its own icons, so ours are
        // captured beforehand and put back.
        $this->assertStringContainsString('icons.set(button, button.innerHTML)', $js);
    }

    /**
     * The editor instance must not live on the Alpine component.
     *
     * Alpine makes component data deeply reactive, so assigning it there hands
     * back a Proxy. Quill compares objects by identity internally —
     * `blot.offset(scroll)` walks parents looking for the very same scroll
     * object — and a proxied scroll never matches the raw one its blots hold.
     * The lookup returns null and every selection read throws
     * "Cannot read properties of null (reading 'offset')": the editor shows its
     * text and even styles it, but takes no typing and saves nothing.
     */
    public function test_the_editor_is_kept_out_of_alpine_reactive_data(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('let quill = null;', $js);

        $this->assertStringNotContainsString(
            'this.quill',
            $js,
            'The Quill instance must live in a closure, never on the reactive component.',
        );
    }

    /**
     * The editor's format list and the server's allowlist have to agree.
     * Anything the editor offers but the server strips reads as the editor
     * losing your work.
     */
    public function test_the_editor_only_offers_formats_the_server_keeps(): void
    {
        // The key is literally "HTML.Allowed", so dot notation would split it.
        $allowed = config('purifier.settings.rich_text')['HTML.Allowed'];

        foreach (['strong', 's', 'h2', 'blockquote', 'pre', 'ul', 'ol', 'li', 'a[href]'] as $tag) {
            $this->assertStringContainsString($tag, $allowed, "The editor can write `{$tag}`, so it must survive.");
        }

        // Pictures are a note's alone. A report body has no way to make one,
        // so the profile it is cleaned with must not keep one either.
        $this->assertStringNotContainsString('img', $allowed);
        $this->assertStringContainsString('img[src', config('purifier.settings.rich_text_images')['HTML.Allowed']);

        $js = file_get_contents(resource_path('js/app.js'));

        // Formats with no home in the allowlist must not be on the toolbar.
        foreach (['video', 'color', 'background', 'font', 'size', 'table'] as $format) {
            $this->assertStringNotContainsString("'{$format}'", $js, "`{$format}` would be stripped on save.");
        }
    }

    /**
     * Only notes take pictures, and the report editor cannot make one — so an
     * <img> in a report body was not put there by a writer.
     */
    public function test_a_report_body_never_keeps_an_image(): void
    {
        $clean = app(RichTextService::class)->sanitize('<p>Done</p><img src="/attachments/12" alt="">');

        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringContainsString('Done', $clean);
    }

    /**
     * An image in a note body has to be one this app is serving.
     *
     * The allowlist gained `img` so a note can carry a picture, which would
     * otherwise be an opening for a tracking pixel: a note is read by the
     * whole workspace, so an off-site image would report who opened it and
     * when, to whoever wrote the note.
     */
    public function test_a_note_body_keeps_our_images_and_drops_everyone_else_s(): void
    {
        $richText = app(RichTextService::class);

        $ours = $richText->sanitize('<p>Before</p><img src="/attachments/12" alt="A photo"><p>After</p>', images: true);

        $this->assertStringContainsString('src="/attachments/12"', $ours);

        foreach ([
            '<img src="https://tracker.example/pixel.gif">',
            '<img src="http://tracker.example/pixel.gif">',
            '<img src="//tracker.example/pixel.gif">',
            '<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">',
        ] as $markup) {
            $this->assertStringNotContainsString(
                'tracker.example',
                $richText->sanitize("<p>Note</p>{$markup}", images: true),
                "`{$markup}` must not survive.",
            );

            $this->assertStringNotContainsString('data:', $richText->sanitize("<p>Note</p>{$markup}", images: true));
        }
    }

    /** An external link is not an embedded resource, and must still work. */
    public function test_external_links_are_untouched_by_the_image_rule(): void
    {
        $clean = app(RichTextService::class)->sanitize('<p><a href="https://example.com/spec">the spec</a></p>', images: true);

        $this->assertStringContainsString('https://example.com/spec', $clean);
    }

    /**
     * A note that is one pasted screenshot has no words in it at all, and
     * judging a body on its text alone would refuse to save the very thing
     * the writer just put there.
     */
    public function test_a_body_that_is_only_an_image_is_not_blank(): void
    {
        $richText = app(RichTextService::class);

        $this->assertFalse($richText->isBlank('<p><br></p><img src="/attachments/4" alt=""><p><br></p>', images: true));
        $this->assertTrue($richText->isBlank('<p><br></p>', images: true));
        // Stripped before it counts: an image the sanitiser refuses is not in
        // the note, so it cannot be what makes the note non-empty.
        $this->assertTrue($richText->isBlank('<p><br></p><img src="https://tracker.example/pixel.gif">', images: true));
    }

    /** The ids a saved body references — what save() ties files to the note by. */
    public function test_the_attachment_ids_a_body_draws_are_read_back_out_of_it(): void
    {
        $richText = app(RichTextService::class);

        $ids = $richText->embeddedAttachmentIds(
            '<img src="/attachments/12" alt=""><p>text</p><img src="/attachments/3?v=2">'
            .'<img src="/attachments/12"><img src="https://elsewhere.test/attachments/9">'
        );

        // 12 once despite appearing twice, and the off-site one is not a
        // reference to attachment 9.
        $this->assertSame([12, 3], $ids);
    }
}
