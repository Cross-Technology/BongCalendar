<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A department's report for one day. There is at most one per department per
 * day and the whole workspace may write it, so it reads as the department's
 * record of the day rather than any one person's.
 *
 * `body` is sanitised HTML — see ReportService, which is the only thing that
 * should ever write it.
 */
#[Fillable(['tenant_id', 'department_id', 'author_id', 'last_editor_id', 'report_date', 'body', 'body_text'])]
class Report extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
        ];
    }

    /* ------------------------------------------------------------ relations */

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_editor_id');
    }

    /**
     * Read receipts, newest reader last, so the list reads in the order the
     * report reached people.
     *
     * @return HasMany<ReportView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(ReportView::class)->oldest('first_seen_at');
    }

    /**
     * The same thing as people, for when only the names are wanted.
     *
     * @return BelongsToMany<User, $this>
     */
    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'report_views')
            ->withPivot(['first_seen_at', 'last_seen_at', 'views'])
            ->orderByPivot('first_seen_at');
    }

    /* --------------------------------------------------------------- scopes */

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOnDate(Builder $query, \DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('report_date', $date);
    }

    public function scopeBetween(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): Builder
    {
        return $query->whereBetween('report_date', [$from, $to]);
    }

    /** Newest day first, and within a day by department. */
    public function scopeTimeline(Builder $query): Builder
    {
        return $query->orderByDesc('report_date')->orderBy('department_id');
    }

    /** Free-text search over the plain-text rendering, never the markup. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where('body_text', 'like', $like);
    }

    /* -------------------------------------------------------------- helpers */

    public function excerpt(int $length = 180): string
    {
        return Str::limit((string) $this->body_text, $length);
    }

    /** Whether this person has opened the report. */
    public function seenBy(User $user): bool
    {
        return $this->views->contains(fn (ReportView $view) => $view->user_id === $user->id);
    }

    /**
     * Readers other than whoever is looking right now.
     *
     * The reader's own receipt is always there — opening the dialog is what
     * writes it — so including it would put "seen by you" on every report and
     * tell nobody anything.
     *
     * @return Collection<int, ReportView>
     */
    public function viewsExcept(User $user): Collection
    {
        return $this->views->reject(fn (ReportView $view) => $view->user_id === $user->id)->values();
    }

    /** Whether anyone has changed it since it was first written. */
    public function wasEdited(): bool
    {
        return $this->last_editor_id !== null && $this->updated_at->gt($this->created_at->addMinute());
    }
}
