<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a task's checklist. Deliberately not a Task: checklist items
 * have no status, priority, assignee or due date — they are a list of things
 * to tick off inside one task, which is what keeps them cheap to add.
 */
#[Fillable(['task_id', 'title', 'completed', 'position'])]
class ChecklistItem extends Model
{
    use HasFactory;

    /**
     * Mirrors the column defaults so a freshly created item reports `completed`
     * rather than null — the database default is not read back on insert.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'completed' => false,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function toggle(): void
    {
        $this->update(['completed' => ! $this->completed]);
    }
}
