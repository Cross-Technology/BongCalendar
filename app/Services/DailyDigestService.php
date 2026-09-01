<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Works out what the morning and evening pushes should say.
 *
 * Kept apart from delivery so the wording can be read and tested without a
 * device: given a user and a day, these return the message or null when there
 * is nothing worth interrupting someone for.
 */
class DailyDigestService
{
    /** Priorities the app treats as "urgent" in the count. */
    protected const URGENT = ['urgent', 'high'];

    /**
     * Tasks that are this person's business: assigned to them, or unassigned
     * in a workspace they belong to. Being buzzed about a colleague's deadline
     * is noise, not a reminder.
     *
     * @return Builder<Task>
     */
    protected function theirTasks(User $user): Builder
    {
        $tenantIds = $user->tenants()->pluck('tenants.id');

        return Task::query()
            ->whereIn('tenant_id', $tenantIds)
            ->roots()
            ->where(fn (Builder $q) => $q->where('assignee_id', $user->id)->orWhereNull('assignee_id'));
    }

    /**
     * "5 tasks due today — 2 urgent, plus 3 checklist items."
     *
     * @return array{title: string, body: string, data: array<string, mixed>}|null
     */
    public function morning(User $user, ?CarbonImmutable $now = null): ?array
    {
        $tz = $user->timezone ?: 'UTC';
        $today = ($now ?? CarbonImmutable::now($tz))->setTimezone($tz)->startOfDay();

        $dueToday = $this->theirTasks($user)
            ->open()
            ->whereBetween('due_date', [$today->utc(), $today->endOfDay()->utc()])
            ->get();

        $overdue = $this->theirTasks($user)
            ->open()
            ->where('due_date', '<', $today->utc())
            ->count();

        // Nothing due and nothing late: say nothing. A daily "you have 0 tasks"
        // teaches people to swipe the notification away without reading it.
        if ($dueToday->isEmpty() && $overdue === 0) {
            return null;
        }

        $urgent = $dueToday->whereIn('priority', self::URGENT)->count();
        $normal = $dueToday->count() - $urgent;

        $checklist = ChecklistItem::whereIn('task_id', $dueToday->pluck('id'))
            ->where('completed', false)
            ->count();

        // The breakdown only earns its place when there is something urgent to
        // single out: "1 task due today — 1 task" says the same thing twice.
        $parts = [];

        if ($urgent > 0) {
            $parts[] = $urgent.' urgent';

            if ($normal > 0) {
                $parts[] = $normal.' normal';
            }
        }

        $body = $dueToday->isEmpty()
            ? $this->plural($overdue, 'task').' still overdue.'
            : $this->plural($dueToday->count(), 'task').' due today'
                .($parts ? ' — '.implode(', ', $parts) : '').'.';

        if ($dueToday->isNotEmpty() && $overdue > 0) {
            $body .= ' '.$this->plural($overdue, 'task').' overdue.';
        }

        if ($checklist > 0) {
            $body .= ' '.$this->plural($checklist, 'checklist item').' to tick off.';
        }

        return [
            'title' => 'Good morning, '.strtok($user->name, ' '),
            'body' => $body,
            'data' => [
                'kind' => 'morning_digest',
                'url' => '/today',
                'due_today' => $dueToday->count(),
                'urgent' => $urgent,
                'overdue' => $overdue,
                'checklist_open' => $checklist,
            ],
        ];
    }

    /**
     * "You completed 4 tasks today. 2 still open."
     *
     * @return array{title: string, body: string, data: array<string, mixed>}|null
     */
    public function evening(User $user, ?CarbonImmutable $now = null): ?array
    {
        $tz = $user->timezone ?: 'UTC';
        $today = ($now ?? CarbonImmutable::now($tz))->setTimezone($tz)->startOfDay();
        $window = [$today->utc(), $today->endOfDay()->utc()];

        $completed = $this->theirTasks($user)
            ->where('status', 'done')
            ->whereBetween('completed_at', $window)
            ->count();

        $checklistDone = ChecklistItem::where('completed', true)
            ->whereBetween('updated_at', $window)
            ->whereHas('task', fn (Builder $q) => $q
                ->whereIn('tenant_id', $user->tenants()->pluck('tenants.id'))
                ->where(fn (Builder $inner) => $inner->where('assignee_id', $user->id)->orWhereNull('assignee_id')))
            ->count();

        $stillDue = $this->theirTasks($user)
            ->open()
            ->whereBetween('due_date', $window)
            ->count();

        // A day with no movement at all and nothing left hanging needs no report.
        if ($completed === 0 && $checklistDone === 0 && $stillDue === 0) {
            return null;
        }

        $body = $completed > 0
            ? 'You completed '.$this->plural($completed, 'task').' today.'
            : 'No tasks completed today.';

        if ($checklistDone > 0) {
            $body .= ' '.$this->plural($checklistDone, 'checklist item').' ticked off.';
        }

        $body .= $stillDue > 0
            ? ' '.$this->plural($stillDue, 'task').' from today still open.'
            : ' Nothing left from today.';

        return [
            'title' => 'Today’s summary',
            'body' => $body,
            'data' => [
                'kind' => 'evening_summary',
                'url' => '/completed',
                'completed' => $completed,
                'checklist_completed' => $checklistDone,
                'still_open' => $stillDue,
            ],
        ];
    }

    protected function plural(int $count, string $word): string
    {
        return $count.' '.$word.($count === 1 ? '' : 's');
    }
}
