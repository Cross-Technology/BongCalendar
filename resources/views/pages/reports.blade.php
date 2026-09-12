<?php

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Department;
use App\Models\Report;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Reports — BongCalendar')]
class extends Component
{
    use InteractsWithTenant;

    /** The day being reported on, as Y-m-d. Lives in the URL so a day is shareable. */
    #[Url(except: '')]
    public string $date = '';

    /** Report open in the read dialog. */
    public ?int $viewingId = null;

    /** Department being written for; the editor is open while this is set. */
    public ?int $writingDepartmentId = null;

    public string $form_body = '';

    public function mount(): void
    {
        $this->requireTenant();

        if (! $this->isValidDate($this->date)) {
            $this->date = $this->todaysDate();
        }
    }

    protected function isValidDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    /** A hand-edited `?date=` must not blow the page up. */
    public function updatedDate(): void
    {
        if (! $this->isValidDate($this->date)) {
            $this->date = $this->todaysDate();
        }

        $this->viewingId = null;
    }

    /* ----------------------------------------------------------------- days */

    /**
     * Today in the workspace's zone, not the server's — a report filed at
     * 9pm in Phnom Penh belongs to that day, not to whatever UTC calls it.
     */
    public function todaysDate(): string
    {
        return CarbonImmutable::now($this->requireTenant()->timezone ?: 'UTC')->toDateString();
    }

    public function day(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->isValidDate($this->date) ? $this->date : $this->todaysDate());
    }

    public function shiftDay(int $days): void
    {
        $this->date = $this->day()->addDays($days)->toDateString();
        $this->viewingId = null;
    }

    public function goToToday(): void
    {
        $this->date = $this->todaysDate();
        $this->viewingId = null;
    }

    public function isToday(): bool
    {
        return $this->date === $this->todaysDate();
    }

    public function isFuture(): bool
    {
        return $this->day()->gt(CarbonImmutable::parse($this->todaysDate()));
    }

    /* ----------------------------------------------------------------- data */

    /**
     * Every department paired with its report for the day, or null. Built from
     * the department list rather than from the reports, because the whole
     * point is showing who has *not* written one — and a department that never
     * reported has no row to find.
     *
     * @return Collection<int, array{department: Department, report: ?Report}>
     */
    public function rows(): Collection
    {
        $tenantId = $this->requireTenant()->id;

        $reports = Report::forTenant($tenantId)
            ->onDate($this->day()->toDateString())
            ->with(['author', 'lastEditor'])
            ->get()
            ->keyBy('department_id');

        return Department::forTenant($tenantId)->get()
            ->map(fn (Department $department) => [
                'department' => $department,
                'report' => $reports->get($department->id),
            ]);
    }

    /* ------------------------------------------------------------ read view */

    public function openReport(int $reportId): void
    {
        $report = Report::findOrFail($reportId);

        $this->authorize('view', $report);

        $this->viewingId = $report->id;
    }

    public function closeReport(): void
    {
        $this->viewingId = null;
    }

    /** Resolved each render, so a report deleted under the reader just closes. */
    public function viewingReport(): ?Report
    {
        if (! $this->viewingId) {
            return null;
        }

        $report = Report::with(['author', 'lastEditor', 'department'])->find($this->viewingId);

        if (! $report || $this->currentUser()->cannot('view', $report)) {
            $this->viewingId = null;

            return null;
        }

        return $report;
    }

    /* -------------------------------------------------------------- writing */

    public function startWriting(int $departmentId): void
    {
        $tenant = $this->requireTenant();

        $this->authorize('create', [Report::class, $tenant->id]);

        $department = Department::forTenant($tenant->id)->findOrFail($departmentId);

        $existing = Report::forTenant($tenant->id)
            ->where('department_id', $department->id)
            ->onDate($this->day()->toDateString())
            ->first();

        if ($existing) {
            $this->authorize('update', $existing);
        }

        $this->resetValidation();
        $this->viewingId = null;
        $this->writingDepartmentId = $department->id;
        $this->form_body = $existing?->body ?? '';
    }

    public function cancelWriting(): void
    {
        $this->writingDepartmentId = null;
        $this->form_body = '';
        $this->resetValidation();
    }

    public function save(ReportService $reports): void
    {
        $tenant = $this->requireTenant();

        $this->authorize('create', [Report::class, $tenant->id]);

        $department = Department::forTenant($tenant->id)->findOrFail($this->writingDepartmentId);

        // Checked against the sanitised text, not the raw markup: an editor
        // left untouched still sends "<div><br></div>", which is not a report.
        if ($reports->isBlank($this->form_body)) {
            $this->addError('form_body', 'Write something before saving the report.');

            return;
        }

        $reports->write($tenant, $department, $this->currentUser(), $this->day(), $this->form_body);

        $this->writingDepartmentId = null;
        $this->form_body = '';

        session()->flash('status', 'Report saved.');
    }

    public function deleteReport(int $reportId): void
    {
        $report = Report::findOrFail($reportId);

        $this->authorize('delete', $report);

        $report->delete();

        if ($this->viewingId === $reportId) {
            $this->viewingId = null;
        }
    }

    public function with(): array
    {
        return [
            'rows' => $this->rows(),
            'viewingReport' => $this->viewingReport(),
            'writingDepartment' => $this->writingDepartmentId
                ? Department::forTenant($this->requireTenant()->id)->find($this->writingDepartmentId)
                : null,
        ];
    }
};
?>

@php
    $reported = $rows->filter(fn ($row) => $row['report'] !== null)->count();
    $total = $rows->count();
@endphp

<div class="mx-auto flex max-w-5xl flex-col gap-5">
    <header class="flex flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="text-3xl font-extrabold tracking-tight">Reports</h1>
            <p class="mt-1.5 text-[15px] text-ink-500 dark:text-ink-400">
                What each department in {{ $this->currentTenant()->name }} did, one day at a time.
            </p>
        </div>
    </header>

    {{-- Day picker --}}
    <div class="panel flex flex-wrap items-center gap-3 p-3 shadow-sm shadow-ink-900/[0.03] sm:p-4">
        <div class="flex items-center gap-1">
            <button type="button" wire:click="shiftDay(-1)" aria-label="Previous day"
                    class="grid size-9 place-items-center rounded-xl border border-ink-200 text-ink-500 transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                <x-icon name="chevron-left" class="size-4" />
            </button>
            <button type="button" wire:click="shiftDay(1)" aria-label="Next day"
                    class="grid size-9 place-items-center rounded-xl border border-ink-200 text-ink-500 transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                <x-icon name="chevron-right" class="size-4" />
            </button>
        </div>

        <div class="min-w-0">
            <p class="text-[15px] font-bold tracking-tight">
                {{ $this->day()->format('l, j F Y') }}
                @if ($this->isToday())
                    <span class="ml-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-brand-700 dark:bg-brand-950 dark:text-brand-200">Today</span>
                @endif
            </p>
            <p class="text-[13px] text-ink-400">
                @if ($total === 0)
                    No departments yet
                @else
                    {{ $reported }} of {{ $total }} {{ \Illuminate\Support\Str::plural('department', $total) }} reported
                @endif
            </p>
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <label class="sr-only" for="report-date">Date</label>
            <input id="report-date" type="date" wire:model.live="date"
                   class="rounded-xl border border-ink-200 bg-white px-3 py-2 text-[14px] outline-none transition focus:border-brand-400 focus:ring-4 focus:ring-brand-100 dark:border-ink-700 dark:bg-ink-800 dark:focus:ring-brand-950">

            @unless ($this->isToday())
                <button type="button" wire:click="goToToday"
                        class="rounded-xl border border-ink-200 px-3 py-2 text-[13px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                    Today
                </button>
            @endunless
        </div>
    </div>

    {{-- One row per department: the day's roll call --}}
    @if ($rows->isNotEmpty())
        <div class="flex flex-col gap-3">
            @foreach ($rows as $row)
                @php $department = $row['department']; $report = $row['report']; @endphp

                <article wire:key="dept-{{ $department->id }}"
                         class="panel flex flex-col gap-3 p-4 shadow-sm shadow-ink-900/[0.03]">
                    <header class="flex flex-wrap items-center gap-3">
                        <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $department->color }}"></span>

                        <h2 class="min-w-0 flex-1 truncate text-[16px] font-bold tracking-tight">{{ $department->name }}</h2>

                        @if ($report)
                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200">
                                Reported
                            </span>
                        @else
                            <span class="rounded-full bg-ink-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-ink-500 dark:bg-ink-800 dark:text-ink-300">
                                Not yet
                            </span>
                        @endif
                    </header>

                    @if ($report)
                        <p class="text-[14px] leading-relaxed text-ink-600 dark:text-ink-300">{{ $report->excerpt() }}</p>

                        <footer class="flex flex-wrap items-center gap-x-2 gap-y-2 border-t border-ink-200/70 pt-3 text-[12px] text-ink-400 dark:border-ink-800">
                            <span class="grid size-5 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-[9px] font-bold text-white">
                                {{ \Illuminate\Support\Str::of($report->author->name)->substr(0, 1)->upper() }}
                            </span>
                            <span class="font-semibold text-ink-500 dark:text-ink-400">{{ $report->author->name }}</span>

                            @if ($report->lastEditor && $report->last_editor_id !== $report->author_id)
                                <span>· last edited by {{ $report->lastEditor->name }}</span>
                            @endif

                            <span class="ml-auto flex flex-wrap gap-2">
                                <button type="button" wire:click="openReport({{ $report->id }})"
                                        class="rounded-lg px-2.5 py-1 font-bold text-ink-600 transition hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800">
                                    Read
                                </button>
                                <button type="button" wire:click="startWriting({{ $department->id }})"
                                        class="rounded-lg px-2.5 py-1 font-bold text-brand-600 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-950">
                                    Edit
                                </button>
                                @can('delete', $report)
                                    <button type="button" wire:click="deleteReport({{ $report->id }})"
                                            wire:confirm="Delete {{ $department->name }}'s report for this day?"
                                            class="rounded-lg px-2.5 py-1 font-bold text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950">
                                        Delete
                                    </button>
                                @endcan
                            </span>
                        </footer>
                    @else
                        <div class="flex flex-wrap items-center gap-3">
                            <p class="min-w-0 flex-1 text-[13px] text-ink-400">
                                Nothing written for this day yet.
                            </p>
                            <button type="button" wire:click="startWriting({{ $department->id }})"
                                    class="flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-[13px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                                <x-icon name="plus" class="size-4" />
                                Write report
                            </button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @else
        <section class="panel flex flex-col items-center px-4 py-14 text-center shadow-sm shadow-ink-900/[0.03]">
            <span class="grid size-12 place-items-center rounded-2xl bg-ink-100 text-ink-400 dark:bg-ink-800">
                <x-icon name="building" class="size-6" />
            </span>
            <p class="mt-4 text-[15px] font-bold">No departments yet</p>
            <p class="mt-1 max-w-sm text-[13px] text-ink-400">
                Reports are filed per department, so add one first.
            </p>
            <a href="{{ route('departments.index') }}"
               class="mt-5 rounded-xl bg-brand-600 px-5 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700">
                Go to departments
            </a>
        </section>
    @endif

    {{-- Read view --}}
    @if ($viewingReport)
        <div class="fixed inset-0 z-50 grid place-items-end bg-ink-950/50 p-0 backdrop-blur-[2px] sm:place-items-center sm:p-4"
             x-on:keydown.escape.window="$wire.closeReport()"
             wire:click.self="closeReport">
            <div class="flex max-h-[92dvh] w-full max-w-2xl flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl dark:bg-ink-900">
                <header class="flex shrink-0 items-start gap-3 border-b border-ink-200/80 px-6 py-4 dark:border-ink-800">
                    <span class="mt-1.5 size-3 shrink-0 rounded-full" style="background-color: {{ $viewingReport->department->color }}"></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-lg font-bold tracking-tight">{{ $viewingReport->department->name }}</h2>
                        <p class="text-[12px] text-ink-400">
                            {{ $viewingReport->report_date->format('l, j F Y') }} · {{ $viewingReport->author->name }}
                        </p>
                    </div>
                    <button type="button" wire:click="closeReport" aria-label="Close"
                            class="grid size-8 shrink-0 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">✕</button>
                </header>

                {{-- Safe to render unescaped: ReportService sanitises every body
                     through the `report` HTMLPurifier profile before it is
                     stored, and nothing else writes this column. --}}
                <div class="rich-text min-h-0 flex-1 overflow-y-auto px-6 py-5 text-ink-700 dark:text-ink-200">
                    {!! $viewingReport->body !!}
                </div>

                <footer class="flex shrink-0 items-center gap-2 border-t border-ink-200/80 px-6 py-4 dark:border-ink-800">
                    <div class="ml-auto flex gap-2">
                        <button type="button" wire:click="closeReport"
                                class="rounded-xl border border-ink-200 px-4 py-2 text-[13px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                            Close
                        </button>
                        <button type="button" wire:click="startWriting({{ $viewingReport->department_id }})"
                                class="rounded-xl bg-brand-600 px-4 py-2 text-[13px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                            Edit report
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    @endif

    {{-- Editor --}}
    @if ($writingDepartment)
        <div class="fixed inset-0 z-50 grid place-items-end bg-ink-950/50 p-0 backdrop-blur-[2px] sm:place-items-center sm:p-4"
             x-on:keydown.escape.window="$wire.cancelWriting()">
            <div class="flex max-h-[92dvh] w-full max-w-2xl flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl dark:bg-ink-900">
                <header class="flex shrink-0 items-start gap-3 border-b border-ink-200/80 px-6 py-4 dark:border-ink-800">
                    <span class="mt-1.5 size-3 shrink-0 rounded-full" style="background-color: {{ $writingDepartment->color }}"></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-lg font-bold tracking-tight">{{ $writingDepartment->name }}</h2>
                        <p class="text-[12px] text-ink-400">Report for {{ $this->day()->format('l, j F Y') }}</p>
                    </div>
                    <button type="button" wire:click="cancelWriting" aria-label="Close"
                            class="grid size-8 shrink-0 place-items-center rounded-full text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800">✕</button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                    {{--
                        wire:ignore is load-bearing: Trix rewrites this subtree
                        as the user types, and letting Livewire morph it would
                        wipe the editor mid-sentence. The key rebuilds it when
                        the department or the day changes, so a second report
                        never opens with the first one's text.

                        `$wire.set(..., false)` stores the value without a round
                        trip — re-rendering on every keystroke would be both
                        wasteful and, with wire:ignore, out of step.
                    --}}
                    <div wire:ignore wire:key="editor-{{ $writingDepartment->id }}-{{ $date }}" x-data>
                        <input id="report-body-input" type="hidden" value="{{ $form_body }}">
                        <trix-editor input="report-body-input"
                                     placeholder="What happened today?"
                                     x-on:trix-change="$wire.set('form_body', $event.target.value, false)"></trix-editor>
                    </div>

                    @error('form_body')
                        <p class="mt-2 text-[13px] font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <footer class="flex shrink-0 items-center gap-2 border-t border-ink-200/80 px-6 py-4 dark:border-ink-800">
                    <div class="ml-auto flex gap-2">
                        <button type="button" wire:click="cancelWriting"
                                class="rounded-xl border border-ink-200 px-4 py-2.5 text-[14px] font-bold transition hover:bg-ink-50 dark:border-ink-700 dark:hover:bg-ink-800">
                            Cancel
                        </button>
                        <button type="button" wire:click="save"
                                class="rounded-xl bg-brand-600 px-5 py-2.5 text-[14px] font-bold text-white shadow-sm shadow-brand-600/25 transition hover:bg-brand-700 active:scale-95">
                            Save report
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    @endif
</div>
