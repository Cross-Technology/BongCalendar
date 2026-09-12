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
     * Every block Trix can produce has to survive the allowlist. Lists were
     * the one people noticed, but a missing tag here silently eats formatting
     * with no error anywhere.
     */
    public static function trixMarkup(): array
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

    #[DataProvider('trixMarkup')]
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
     * reaches inside <trix-editor> too. With the rules scoped to the rendered
     * output alone, a bulleted list showed no bullets while it was being
     * typed — correct once saved, which made it look like the editor was
     * broken. This is the guard for that.
     */
    /**
     * Trix's stock toolbar is a row of floated text-height buttons with icons
     * at 0.6 opacity, and it clamps them with `max-width: calc(0.8em + 4vw)` —
     * about 26px on a phone, shrinking the targets on the screens where they
     * are hardest to hit. These are the overrides that make it a ribbon.
     */
    public function test_the_toolbar_buttons_are_sized_and_legible(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Trix's responsive clamp has to be lifted, or the size below is moot.
        $this->assertStringContainsString('max-width: none', $css);
        $this->assertMatchesRegularExpression('/trix-button--icon \{[^}]*width: 2rem/s', $css);

        // Black SVG icons are baked into background images and cannot inherit
        // a colour, so a dark bar needs them inverted or they vanish.
        $this->assertStringContainsString(
            "[data-theme='dark'] trix-toolbar .trix-button--icon::before",
            $css,
        );

        // Attachments are refused in JS, so the paperclip must not be offered.
        $this->assertStringContainsString('trix-button-group--file-tools', $css);
        $this->assertStringContainsString(
            'trix-file-accept',
            file_get_contents(resource_path('js/app.js')),
        );
    }

    public function test_the_stylesheet_dresses_the_editor_and_the_saved_note_alike(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['ul', 'ol', 'h1', 'a', 'blockquote', 'pre'] as $tag) {
            $this->assertStringContainsString(
                ":is(.rich-text, trix-editor) {$tag}",
                $css,
                "Rich-text `{$tag}` styling must cover the live editor as well as saved markup.",
            );
        }

        // Spelled as longhands so a minifier cannot quietly rewrite the
        // shorthand into something that reads as a different rule.
        $this->assertStringContainsString('list-style-type: disc', $css);
        $this->assertStringContainsString('list-style-type: decimal', $css);
    }
}
