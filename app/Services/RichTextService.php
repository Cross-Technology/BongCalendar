<?php

namespace App\Services;

use Mews\Purifier\Facades\Purifier;

/**
 * Everything that turns editor HTML into something safe to store.
 *
 * Rich-text bodies — reports, notes — are user input that gets rendered back
 * as markup to a whole workspace, which is the textbook stored-XSS setup. One
 * service and one allowlist means there is a single place to be sure about,
 * and no chance of two copies of the rules drifting apart.
 */
class RichTextService
{
    /** The allowlist in config/purifier.php — exactly what the editor emits. */
    public const PURIFIER_PROFILE = 'rich_text';

    public function sanitize(string $html): string
    {
        return trim(Purifier::clean($html, self::PURIFIER_PROFILE));
    }

    /**
     * Plain-text rendering, stored alongside the markup for search and
     * previews.
     */
    public function toText(string $html): string
    {
        // Block boundaries become newlines, so "one</div><div>two" reads as two
        // lines rather than "onetwo". They are kept rather than flattened to
        // spaces because callers still care where the first line ends — an
        // untitled note takes its heading from it.
        $broken = preg_replace('#<(br|/p|/div|/li|/h1|/h2|/blockquote|/pre)\s*/?>#i', "\n", $html);

        $text = html_entity_decode(strip_tags((string) $broken), ENT_QUOTES | ENT_HTML5);

        // Collapse runs of horizontal space only, then tidy the line breaks.
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", (string) $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", (string) $text);

        return trim((string) $text);
    }

    /** True when the editor sent markup but no actual words. */
    public function isBlank(string $html): bool
    {
        return $this->toText($this->sanitize($html)) === '';
    }

    /**
     * Wrap plain text as the editor would have. Used by the quick note
     * composer and when migrating text that predates rich editing — escaping
     * first, so a note that happened to contain markup stays inert text
     * rather than becoming live HTML.
     */
    public function fromPlainText(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];

        $blocks = array_map(
            fn (string $line) => '<div>'.($line === '' ? '<br>' : e($line)).'</div>',
            $lines,
        );

        return implode('', $blocks);
    }
}
