<?php

namespace App\Models\Concerns;

use App\Models\Activity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Keeps an audit trail for a model: who created it, and who changed what
 * since.
 *
 * Hooked onto the model's own events rather than onto each caller, because a
 * task is written from six places — the board, the detail panel, the API, a
 * template, the recurrence job and the seeder — and a trail with holes in it
 * is worse than none: it reads as "nobody touched this".
 *
 * A model using this trait may narrow what is kept:
 *   - {@see self::activityIgnored()}  columns that are noise, never logged
 *   - {@see self::activityOpaque()}   columns logged as changed, values not kept
 *   - {@see self::activityLabels()}   how a column is named to a reader
 *   - {@see self::activityValue()}    how a stored value is written out
 */
trait RecordsActivity
{
    public static function bootRecordsActivity(): void
    {
        static::created(function (Model $model) {
            $model->recordActivity('created');
        });

        static::updated(function (Model $model) {
            /*
             * Creating a task writes the row, then stamps its status and syncs
             * its owners — three saves for one act. They are part of creating
             * it, not edits of it, so the trail shows one entry and not three.
             */
            if ($model->wasRecentlyCreated) {
                return;
            }

            $model->recordActivity('updated', $model->activityChanges());
        });

        static::deleted(function (Model $model) {
            $model->recordActivity('deleted');
        });

        // Only a soft-deleting model has anything to restore.
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                $model->recordActivity('restored');
            });
        }
    }

    /**
     * This model's trail, newest first.
     *
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->newest();
    }

    /** The entry for its creation, which carries who made it and when. */
    public function creationActivity(): ?Activity
    {
        return $this->activities->firstWhere('action', 'created');
    }

    /** The last edit, or null when nothing has been changed since. */
    public function lastEditActivity(): ?Activity
    {
        return $this->activities->firstWhere('action', 'updated');
    }

    /* -------------------------------------------------------- what is kept */

    /**
     * Columns never worth an entry. Merged with the bookkeeping ones every
     * model has.
     *
     * @return array<int, string>
     */
    protected function activityIgnored(): array
    {
        return [];
    }

    /**
     * Columns recorded as having changed without keeping what they held — a
     * report body is a page of HTML, and a trail carrying two copies of it per
     * edit would be many times the size of the thing it describes.
     *
     * @return array<int, string>
     */
    protected function activityOpaque(): array
    {
        return [];
    }

    /**
     * How a column is named to a reader, for the ones a headline does not get
     * right ("Assignee id").
     *
     * @return array<string, string>
     */
    protected function activityLabels(): array
    {
        return [];
    }

    /**
     * A stored value as it should read in the trail.
     *
     * Resolved now rather than at display time on purpose: an audit entry says
     * what was true at that moment, so a department renamed next month must
     * not rewrite what last month's entry says.
     */
    public function activityValue(string $field, mixed $value): ?string
    {
        return $this->defaultActivityValue($value);
    }

    /**
     * The plain rendering every value falls back to. Kept apart from
     * {@see self::activityValue()} so a model that overrides that method can
     * still hand the fields it has nothing special to say about back here —
     * `parent::` cannot reach a trait method.
     */
    final protected function defaultActivityValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface || $value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        // Long free text is summarised: the trail says a description changed,
        // the task itself says what it now reads.
        return Str::limit(trim((string) $value), 120);
    }

    /* ------------------------------------------------------------- writing */

    /**
     * What this save changed, as entries to store.
     *
     * Read during the `updated` event, where the model still knows both sides:
     * Eloquent syncs the originals after the event has run.
     *
     * @return array<string, array{label: string, from: ?string, to: ?string, opaque?: bool}>
     */
    protected function activityChanges(): array
    {
        $ignored = array_merge(
            [$this->getKeyName(), 'created_at', 'updated_at', 'deleted_at'],
            $this->activityIgnored(),
        );

        $opaque = $this->activityOpaque();
        $labels = $this->activityLabels();
        $changes = [];

        foreach (array_keys($this->getChanges()) as $field) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $label = $labels[$field] ?? (string) Str::of($field)->replace('_id', '')->headline();

            if (in_array($field, $opaque, true)) {
                $changes[$field] = ['label' => $label, 'from' => null, 'to' => null, 'opaque' => true];

                continue;
            }

            $from = $this->activityValue($field, $this->getOriginal($field));
            $to = $this->activityValue($field, $this->getAttribute($field));

            // A cast can make two different raw values read the same — a date
            // rewritten to the same minute, say. Nothing changed to a reader.
            if ($from === $to) {
                continue;
            }

            $changes[$field] = ['label' => $label, 'from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /**
     * The edit this instance has already recorded, so a second save on the
     * same instance folds into it rather than starting a new entry.
     */
    protected ?Activity $pendingEdit = null;

    /**
     * @param  array<string, mixed>|null  $changes
     */
    protected function recordActivity(string $action, ?array $changes = null): ?Activity
    {
        // A save that touched nothing a reader cares about — a position nudge,
        // a body reindexed — is not an edit.
        if ($action === 'updated' && empty($changes)) {
            return null;
        }

        /*
         * One PATCH writes the plain fields, then the status, then the owners —
         * three saves for one edit. They are the same person changing the same
         * task at the same moment, so they read as one entry.
         *
         * Scoped to this instance, which lives for one request: two people
         * editing at once hold instances of their own and never merge.
         */
        if ($action === 'updated' && $this->pendingEdit !== null) {
            return $this->mergeIntoPendingEdit($changes);
        }

        $activity = Activity::create([
            'tenant_id' => $this->getAttribute('tenant_id'),
            'subject_type' => $this->getMorphClass(),
            'subject_id' => $this->getKey(),
            'user_id' => auth()->id(),
            'action' => $action,
            'change_set' => $changes,
        ]);

        if ($action === 'updated') {
            $this->pendingEdit = $activity;
        }

        return $activity;
    }

    /**
     * Folds a second save into the entry already open.
     *
     * A field changed twice keeps the value it started at and the one it ended
     * on — todo → in_progress → done is one move from Todo to Completed — and a
     * field put back the way it was drops out, because nothing changed.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function mergeIntoPendingEdit(array $changes): Activity
    {
        $merged = $this->pendingEdit->change_set ?? [];

        foreach ($changes as $field => $change) {
            if (isset($merged[$field])) {
                $change['from'] = $merged[$field]['from'] ?? null;
            }

            if (($change['from'] ?? null) === ($change['to'] ?? null) && empty($change['opaque'])) {
                unset($merged[$field]);

                continue;
            }

            $merged[$field] = $change;
        }

        // Every change undone leaves an entry saying nothing happened.
        if ($merged === []) {
            $this->pendingEdit->delete();

            return tap($this->pendingEdit, fn () => $this->pendingEdit = null);
        }

        $this->pendingEdit->forceFill(['change_set' => $merged])->save();

        return $this->pendingEdit;
    }
}
