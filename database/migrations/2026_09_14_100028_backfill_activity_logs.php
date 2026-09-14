<?php

use App\Models\Report;
use App\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives everything already in the workspace its first audit entry.
 *
 * Without this the trail starts empty and a task written last month reads as
 * though nobody made it. `created_by` / `author_id` and the row's own
 * created_at already hold the answer — this is only writing it where the trail
 * can see it.
 *
 * Soft-deleted rows are included: a deleted task is precisely the one somebody
 * asks about afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfill('tasks', Task::class, 'created_by');
        $this->backfill('reports', Report::class, 'author_id');
    }

    protected function backfill(string $table, string $type, string $actorColumn): void
    {
        DB::table($table)
            ->select(["{$table}.id", "{$table}.tenant_id", "{$table}.{$actorColumn}", "{$table}.created_at"])
            // Anything written since the trail went live recorded its own
            // creation. Skipping those keeps a re-run from doubling them.
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('activity_logs')
                ->whereColumn('activity_logs.subject_id', "{$table}.id")
                ->where('activity_logs.subject_type', $type)
                ->where('activity_logs.action', 'created'))
            ->orderBy("{$table}.id")
            ->chunk(500, function ($rows) use ($type, $actorColumn) {
                DB::table('activity_logs')->insert($rows->map(fn ($row) => [
                    'tenant_id' => $row->tenant_id,
                    'subject_type' => $type,
                    'subject_id' => $row->id,
                    'user_id' => $row->{$actorColumn},
                    'action' => 'created',
                    'change_set' => null,
                    'created_at' => $row->created_at,
                ])->all());
            });
    }

    /**
     * Deliberately nothing. A backfilled entry is indistinguishable from one
     * the trail wrote itself, so undoing this by shape would take real history
     * with it — and rolling back the migration before it drops the table
     * anyway.
     */
    public function down(): void {}
};
