<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A note someone takes inside a workspace — a decision, a phone number, the
 * thing they must not forget before Monday.
 *
 * Notes are shared by default (`tenant` visibility, the same word calendars
 * use) so the workspace reads them as a noticeboard; the author can keep one
 * to themselves with `private`.
 *
 * `body` is sanitised HTML from the editor and `body_text` its plain-text
 * rendering — RichTextService owns both, and nothing else should write them.
 */
#[Fillable(['tenant_id', 'author_id', 'title', 'body', 'body_text', 'color', 'visibility', 'is_pinned'])]
class Note extends Model
{
    use HasFactory, SoftDeletes;

    public const VISIBILITIES = ['private', 'tenant'];

    /** Sticky-note hues offered in the composer. */
    public const COLORS = ['#f59e0b', '#6f5cf0', '#2563eb', '#0ea5e9', '#10b981', '#ef4444', '#db2777', '#64748b'];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------ relations */

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Everything hanging off the note — the files listed beneath it and the
     * images drawn inside it. Mostly of interest to cleanup and policies;
     * anything that renders a list wants `files()`.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }

    /**
     * The attachments shown as a file list. Embedded images are excluded: they
     * are already on screen inside the body, and repeating them underneath
     * reads as the note having two copies of the same picture.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function files(): MorphMany
    {
        return $this->attachments()->where('is_embedded', false);
    }

    /** The images drawn inside the body. @return MorphMany<Attachment, $this> */
    public function inlineImages(): MorphMany
    {
        return $this->attachments()->where('is_embedded', true);
    }

    /**
     * The first picture in the note, for the board card.
     *
     * A note whose body is one pasted screenshot has no text to preview, and
     * without this its card is blank — the one thing it contains being the one
     * thing the board would not show.
     *
     * @return MorphOne<Attachment, $this>
     */
    public function coverImage(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('is_embedded', true)
            ->oldest('id');
    }

    /* --------------------------------------------------------------- scopes */

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Notes the user may read: everything shared with the workspace, plus
     * their own private ones. Always scoped to a single tenant, so a note can
     * never surface outside the workspace it was written in.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->where(fn (Builder $q) => $q
                ->where('visibility', 'tenant')
                ->orWhere('author_id', $user->id));
    }

    /** Board order: pinned notes first, then most recently touched. */
    public function scopeBoardOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('updated_at')->orderByDesc('id');
    }

    /**
     * Free-text search across the title and the note's text. Deliberately not
     * the markup: searching that matches tag names and misses any phrase a
     * bold tag happens to split.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // Escape the LIKE wildcards so a search for "50%" is not a prefix match.
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('body_text', 'like', $like));
    }

    /* -------------------------------------------------------------- helpers */

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    /** Heading for a note that was saved without a title. */
    public function displayTitle(): string
    {
        return $this->title ?: (Str::limit(strtok((string) $this->body_text, "\n"), 60) ?: 'Untitled note');
    }

    public function excerpt(int $length = 180): string
    {
        return Str::limit((string) $this->body_text, $length);
    }
}
