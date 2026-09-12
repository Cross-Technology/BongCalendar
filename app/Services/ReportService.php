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
    /**
     * Fields a department header may carry. They are filled in when the header
     * is rendered, so one design serves every day and every author — the
     * alternative is retyping the date into the header each morning.
     *
     * @var array<string, string>
     */
    public const HEADER_FIELDS = [
        '{{department}}' => 'Department name',
        '{{date}}' => 'The day being reported on',
        '{{author}}' => 'Who wrote the report',
        '{{workspace}}' => 'Workspace name',
    ];

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

    /* -------------------------------------------------------------- headers */

    /**
     * A department's header with its fields filled in.
     *
     * Values are escaped before they go into the stored markup: a department
     * called "Sales & <Ops>" must read as text, not reopen the hole the
     * sanitiser closes.
     */
    public function renderHeader(Department $department, \DateTimeInterface $date, ?User $author = null): string
    {
        $header = trim((string) $department->report_header);

        if ($header === '') {
            return '';
        }

        return strtr($header, $this->headerValues($department, $date, $author));
    }

    /**
     * What each field becomes. Exposed so the designer can show it beside the
     * field itself — being told `{{date}}` means "the day being reported on"
     * is weaker than being shown "Friday, 12 September 2026".
     *
     * Values are escaped because they land inside stored markup: a department
     * called "Sales & <Ops>" must read as text rather than reopening the hole
     * the sanitiser closes.
     *
     * @return array<string, string>
     */
    public function headerValues(Department $department, \DateTimeInterface $date, ?User $author = null): array
    {
        return [
            '{{department}}' => e($department->name),
            // DateTimeInterface on purpose: a Report's `report_date` casts to
            // Illuminate\Support\Carbon, not CarbonImmutable, so a narrower
            // hint blows up on every report that has a header.
            '{{date}}' => e(CarbonImmutable::instance($date)->translatedFormat('l, j F Y')),
            '{{author}}' => e($author?->name ?? ''),
            '{{workspace}}' => e($department->tenant?->name ?? ''),
        ];
    }

    /** Stores a header, sanitised like any other rich text. Blank clears it. */
    public function saveHeader(Department $department, string $html): void
    {
        $clean = $this->richText->sanitize($html);

        $department->update([
            'report_header' => $this->richText->toText($clean) === '' ? null : $clean,
        ]);
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
