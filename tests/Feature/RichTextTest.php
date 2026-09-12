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

        // The applied-at-the-cursor state has to be visible.
        $this->assertStringContainsString('.rich-editor__toolbar button.ql-active', $css);
        $this->assertStringContainsString("[data-theme='dark'] .rich-editor__toolbar button", $css);
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

        $js = file_get_contents(resource_path('js/app.js'));

        // Formats with no home in the allowlist must not be on the toolbar.
        foreach (['image', 'video', 'color', 'background', 'font', 'size', 'table'] as $format) {
            $this->assertStringNotContainsString("'{$format}'", $js, "`{$format}` would be stripped on save.");
        }
    }
}
