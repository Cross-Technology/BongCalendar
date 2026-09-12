<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

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
}
