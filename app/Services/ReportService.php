<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Report;
use App\Models\ReportView;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The only thing that should write a report's body.
 *
 * The HTML handling lives in RichTextService, which notes share; this keeps
 * the one-report-per-department-per-day rule.
 */
class ReportService
{
    public function __construct(protected RichTextService $richText) {}

    public function sanitize(string $html): string
    {
        return $this->richText->sanitize($html);
    }

    public function toText(string $html): string
    {
        return $this->richText->toText($html);
    }

    public function isBlank(string $html): bool
    {
        return $this->richText->isBlank($html);
    }

    /**
     * Write a department's report for a day — creating it, or updating the one
     * already there. The unique index is what guarantees one per day; this
     * keeps the common case from tripping it.
     */
    public function write(
        Tenant $tenant,
        Department $department,
        User $user,
        CarbonImmutable $date,
        string $html,
    ): Report {
        $clean = $this->sanitize($html);

        $report = Report::firstOrNew([
            'tenant_id' => $tenant->id,
            'department_id' => $department->id,
            'report_date' => $date->toDateString(),
        ]);

        $report->fill([
            'body' => $clean,
            'body_text' => $this->toText($clean),
        ]);

        // The first person to write it stays the author; everyone after that
        // is recorded as the last editor, so the byline never silently changes.
        if ($report->exists) {
            $report->last_editor_id = $user->id;
        } else {
            $report->author_id = $user->id;
        }

        $report->save();

        return $report;
    }

    /**
     * Record that someone has read a report.
     *
     * Called wherever the body is actually put in front of a person — the read
     * dialog, the editor opened on an existing report, the API's show — rather
     * than from the model, so a listing that happens to load a report does not
     * claim it was read.
     *
     * A repeat opening bumps the count and the last-seen time; the first-seen
     * time never moves, because that is the answer to "did it reach them in
     * time?" and a reread would erase it.
     */
    public function markSeen(Report $report, User $user): ReportView
    {
        $now = now();

        $view = ReportView::firstOrNew([
            'report_id' => $report->id,
            'user_id' => $user->id,
        ]);

        if (! $view->exists) {
            $view->first_seen_at = $now;
            $view->views = 0;
        }

        $view->last_seen_at = $now;
        $view->views = (int) $view->views + 1;

        try {
            $view->save();
        } catch (UniqueConstraintViolationException) {
            // Two tabs opened the same report at once and the other one won
            // the insert. Its row is the real one; fold this reading into it.
            $view = ReportView::where('report_id', $report->id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $view->increment('views', 1, ['last_seen_at' => $now]);
        }

        return $view;
    }
}
