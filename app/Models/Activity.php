<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the audit trail — see {@see RecordsActivity}, which is the only
 * thing that should write these.
 *
 * Entries are a record of a moment and are never edited: `change_set` holds the
 * values as they read at the time, so renaming a department later does not
 * rewrite history.
 */
#[Fillable(['tenant_id', 'subject_type', 'subject_id', 'user_id', 'action', 'change_set'])]
class Activity extends Model
{
    protected $table = 'activity_logs';

    /** Written once. There is no updated_at column to maintain. */
    public const UPDATED_AT = null;

    /** Verb per action, in the past tense the trail is read in. */
    public const ACTION_LABELS = [
        'created' => 'created',
        'updated' => 'edited',
        'deleted' => 'deleted',
        'restored' => 'restored',
    ];

    protected function casts(): array
    {
        return [
            'change_set' => 'array',
        ];
    }

    /* ------------------------------------------------------------ relations */

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Whoever did it, or null when nothing was signed in — a seeder, a console
     * command or the recurrence job.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* --------------------------------------------------------------- scopes */

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Newest first — the trail is read from what just happened backwards. */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->latest('created_at')->latest('id');
    }

    /* -------------------------------------------------------------- helpers */

    /** Who did it, for display. */
    public function actorName(): string
    {
        return $this->user?->name ?? 'System';
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /**
     * The fields this entry touched, as lines to show under it.
     *
     * @return array<int, array{label: string, from: ?string, to: ?string, opaque: bool}>
     */
    public function changeLines(): array
    {
        return array_values(array_map(fn (array $change) => [
            'label' => $change['label'] ?? '',
            'from' => $change['from'] ?? null,
            'to' => $change['to'] ?? null,
            'opaque' => (bool) ($change['opaque'] ?? false),
        ], $this->change_set ?? []));
    }

    /** "Status, Due date" — the headline for an edit with the detail folded away. */
    public function changedFields(): string
    {
        return implode(', ', array_column($this->changeLines(), 'label'));
    }
}
