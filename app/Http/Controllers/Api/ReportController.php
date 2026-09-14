<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportRequest;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\ReportResource;
use App\Models\Department;
use App\Models\Report;
use App\Services\ReportService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController extends Controller
{
    public function __construct(
        protected TenantContext $tenants,
        protected ReportService $reports,
    ) {}

    /** `?date=`, `?from=&to=`, `?department_id=`, `?q=`. Newest day first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenants->require()->id;

        $reports = Report::forTenant($tenantId)
            ->when($request->filled('date'), fn ($q) => $q->onDate($request->date('date')))
            ->when(
                $request->filled('from') && $request->filled('to'),
                fn ($q) => $q->between($request->date('from'), $request->date('to'))
            )
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->search($request->query('q'))
            ->with(['department', 'author', 'lastEditor'])
            ->timeline()
            ->paginate($request->integer('per_page') ?: 25)
            ->withQueryString();

        return ReportResource::collection($reports);
    }

    /**
     * The day at a glance: every department, with its report or null. This is
     * the endpoint that answers "who still owes a report today?" — an index
     * alone cannot, because a department that never reported has no row.
     */
    public function daily(Request $request): JsonResponse
    {
        $tenantId = $this->tenants->require()->id;
        $date = $request->filled('date')
            ? CarbonImmutable::parse($request->query('date'))
            : CarbonImmutable::now($this->tenants->require()->timezone ?: 'UTC');

        $departments = Department::forTenant($tenantId)->get();

        $reports = Report::forTenant($tenantId)
            ->onDate($date->toDateString())
            ->with(['author', 'lastEditor'])
            ->get()
            ->keyBy('department_id');

        $rows = $departments->map(fn (Department $department) => [
            'department' => new DepartmentResource($department),
            'report' => ($report = $reports->get($department->id))
                ? new ReportResource($report)
                : null,
        ]);

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'departments_total' => $departments->count(),
                'reported' => $reports->count(),
                'missing' => $departments->count() - $reports->count(),
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * Writes the department's report for the day. Deliberately an upsert: a
     * department has one report per day, so posting twice corrects it rather
     * than failing on the unique index or stacking a duplicate.
     */
    public function store(ReportRequest $request): JsonResponse
    {
        $tenant = $this->tenants->require();

        $this->authorize('create', [Report::class, $tenant->id]);

        if ($this->reports->isBlank($request->string('body'))) {
            return response()->json([
                'message' => 'The report is empty.',
                'errors' => ['body' => ['Write something before saving the report.']],
            ], 422);
        }

        $department = Department::forTenant($tenant->id)->findOrFail($request->integer('department_id'));

        $existed = Report::forTenant($tenant->id)
            ->where('department_id', $department->id)
            ->onDate($request->string('report_date'))
            ->exists();

        $report = $this->reports->write(
            $tenant,
            $department,
            $request->user(),
            CarbonImmutable::parse($request->string('report_date')),
            $request->string('body'),
        );

        return response()->json(
            ['data' => new ReportResource($report->load(['department', 'author', 'lastEditor']))],
            $existed ? 200 : 201
        );
    }

    /** Fetching one report is someone reading it, so it leaves a receipt. */
    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorize('view', $report);

        $this->reports->markSeen($report, $request->user());

        return response()->json([
            'data' => new ReportResource($report->load(['department', 'author', 'lastEditor', 'views.user'])),
        ]);
    }

    public function update(ReportRequest $request, Report $report): JsonResponse
    {
        $this->authorize('update', $report);

        if ($this->reports->isBlank($request->string('body'))) {
            return response()->json([
                'message' => 'The report is empty.',
                'errors' => ['body' => ['Write something before saving the report.']],
            ], 422);
        }

        $this->reports->write(
            $report->tenant,
            $report->department,
            $request->user(),
            CarbonImmutable::parse($report->report_date),
            $request->string('body'),
        );

        return response()->json([
            'data' => new ReportResource($report->fresh()->load(['department', 'author', 'lastEditor'])),
        ]);
    }

    public function destroy(Report $report): JsonResponse
    {
        $this->authorize('delete', $report);

        $report->delete();

        return response()->json(['message' => 'Report deleted.']);
    }
}
