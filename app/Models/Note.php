<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A note someone takes inside a workspace — a decision, a phone number, the
 * thing they must not forget before Monday.
 *
 * Notes are shared by default (`tenant` visibility, the same word calendars
 * use) so the workspace reads them as a noticeboard; the author can keep one
 * to themselves with `private`.
 */
#[Fillable(['tenant_id', 'author_id', 'title', 'body', 'color', 'visibility', 'is_pinned'])]
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

    /** Free-text search across the title and the body. */
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
            ->orWhere('body', 'like', $like));
    }

    /* -------------------------------------------------------------- helpers */

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    /** Heading for a note that was saved without a title. */
    public function displayTitle(): string
    {
        return $this->title ?: (Str::limit(strtok((string) $this->body, "\n"), 60) ?: 'Untitled note');
    }

    public function excerpt(int $length = 180): string
    {
        return Str::limit((string) $this->body, $length);
    }
}
