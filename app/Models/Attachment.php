<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Number;

/**
 * A file hanging off something — a note today. The row records what the
 * uploader called it; the bytes live under a generated path, so the original
 * name is only ever shown, never used to address the file.
 */
#[Fillable(['tenant_id', 'attachable_type', 'attachable_id', 'is_embedded', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class Attachment extends Model
{
    use HasFactory;

    /** 5 MB, as kilobytes — the unit Laravel's `max` rule speaks. */
    public const MAX_KILOBYTES = 5120;

    public const MAX_BYTES = self::MAX_KILOBYTES * 1024;

    /**
     * Documents, spreadsheets and ordinary images. SVG is deliberately absent:
     * it is a script-carrying document that browsers render, so it would undo
     * the sanitising everywhere else.
     */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** The subset that can be drawn inside a note body. */
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** Types safe to show in the browser rather than push straight to disk. */
    public const INLINE_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    protected function casts(): array
    {
        return [
            'is_embedded' => 'boolean',
        ];
    }

    /**
     * What PHP itself will accept, in kilobytes.
     *
     * A file larger than `upload_max_filesize` is discarded by PHP before
     * Laravel sees the request, so validation never runs and the user gets
     * "the file failed to upload" with no hint why. Reading the real ceiling
     * lets the UI quote a limit that is actually true.
     */
    public static function phpMaxKilobytes(): int
    {
        $limits = array_filter([
            static::iniKilobytes('upload_max_filesize'),
            static::iniKilobytes('post_max_size'),
        ]);

        return $limits === [] ? self::MAX_KILOBYTES : (int) min($limits);
    }

    /** The smaller of what this app allows and what PHP will carry. */
    public static function effectiveMaxKilobytes(): int
    {
        return (int) min(self::MAX_KILOBYTES, static::phpMaxKilobytes());
    }

    /** Parses PHP's "8M" / "512K" / "1G" shorthand into kilobytes. */
    protected static function iniKilobytes(string $directive): ?int
    {
        $value = trim((string) ini_get($directive));

        if ($value === '' || $value === '-1' || $value === '0') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024,
            'm' => $number * 1024,
            'k' => $number,
            default => $number / 1024,
        };
    }

    /**
     * Validation for an uploaded file. `mimes` checks the actual contents, not
     * the extension, so an HTML page renamed to .png is refused.
     *
     * @return array<int, string>
     */
    public static function uploadRules(): array
    {
        return ['file', 'max:'.self::MAX_KILOBYTES, 'mimes:'.implode(',', self::ALLOWED_EXTENSIONS)];
    }

    /**
     * Validation for an image going *inside* a note.
     *
     * Narrower than uploadRules on purpose: this file is rendered as markup to
     * everyone who can read the note, so only the picture formats a browser
     * draws are accepted — never a PDF, and never SVG, which is a document
     * that can carry script.
     *
     * @return array<int, string>
     */
    public static function imageRules(): array
    {
        return ['file', 'image', 'max:'.self::MAX_KILOBYTES, 'mimes:'.implode(',', self::IMAGE_EXTENSIONS)];
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isInline(): bool
    {
        return in_array($this->mime_type, self::INLINE_TYPES, true);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    public function humanSize(): string
    {
        return Number::fileSize($this->size, precision: $this->size >= 1048576 ? 1 : 0);
    }

    /** True while an embedded image is still waiting for its note to be saved. */
    public function isOrphan(): bool
    {
        return $this->attachable_id === null;
    }

    public function downloadUrl(): string
    {
        return route('attachments.download', $this);
    }

    /**
     * The `src` written into a note body — deliberately root-relative.
     *
     * The sanitiser refuses any embedded URL that carries a host
     * (config/purifier.php), which is what stops a crafted note from pulling
     * in an off-site tracking pixel. A relative path has no host, so ours
     * survive that rule wherever the app is served from — behind a tunnel, on
     * a staging domain, or on localhost with a different port than APP_URL.
     */
    public function inlineSrc(): string
    {
        return route('attachments.download', $this, absolute: false);
    }
}
