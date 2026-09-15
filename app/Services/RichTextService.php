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

    /**
     * The same allowlist plus `img`, for the one place that can produce one.
     *
     * Kept separate rather than merged into the profile above: the report
     * editor has no way to insert a picture, so an image in a report body was
     * put there by something other than a writer, and the narrower profile is
     * what says so.
     */
    public const PURIFIER_PROFILE_WITH_IMAGES = 'rich_text_images';

    /**
     * @param  bool  $images  True only for notes, the one body that may carry
     *                        a picture.
     */
    public function sanitize(string $html, bool $images = false): string
    {
        return trim(Purifier::clean($html, $this->profile($images)));
    }

    protected function profile(bool $images): string
    {
        return $images ? self::PURIFIER_PROFILE_WITH_IMAGES : self::PURIFIER_PROFILE;
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

    /**
     * True when the editor sent markup but nothing in it.
     *
     * An image counts as something. A note that is one pasted screenshot and
     * no words has no text at all, and judging it on words alone would refuse
     * to save the very thing the writer just put there.
     */
    public function isBlank(string $html, bool $images = false): bool
    {
        $clean = $this->sanitize($html, $images);

        if (stripos($clean, '<img') !== false) {
            return false;
        }

        return $this->toText($clean) === '';
    }

    /**
     * The attachment ids of the images a body draws.
     *
     * Read back out of the saved markup rather than tracked as the writer
     * types: the body is what the note actually shows, and a draft that was
     * undone, re-pasted, or edited in two tabs makes any running tally wrong.
     * Run this *after* sanitising — anything the sanitiser threw out is not in
     * the note, and must not keep a file alive.
     *
     * @return array<int, int>
     */
    public function embeddedAttachmentIds(string $html): array
    {
        // `~` delimits, because the pattern itself has to match a `#` — the
        // fragment on a src that carries one. The path is anchored rather than
        // searched for: `https://elsewhere.test/attachments/9` must not count
        // as a reference to attachment 9.
        preg_match_all('~<img[^>]+src="/attachments/(\d+)(?:[?#][^"]*)?"~i', $html, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
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
