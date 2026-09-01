<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Calendar;
use App\Models\Department;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController extends Controller
{
    public function __construct(protected TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenants->require()->id;

        // Counts cover only what the caller may see, so a department never
        // reports someone else's private calendars.
        $visibleIds = Calendar::visibleTo($request->user(), $tenantId)->pluck('id');

        $departments = Department::forTenant($tenantId)
            ->withCount([
                'calendars' => fn ($q) => $q->whereIn('calendars.id', $visibleIds),
                'events' => fn ($q) => $q->whereIn('events.calendar_id', $visibleIds),
            ])
            ->get();

        return DepartmentResource::collection($departments);
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $tenant = $this->tenants->require();

        $this->authorize('create', [Department::class, $tenant->id]);

        $department = Department::create([
            'tenant_id' => $tenant->id,
            'name' => $request->string('name'),
            'description' => $request->input('description'),
            'color' => $request->input('color', '#6f5cf0'),
            'icon' => $request->input('icon'),
            'position' => $request->integer('position'),
        ]);

        return response()->json(['data' => new DepartmentResource($department)], 201);
    }

    public function show(Request $request, Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        $visibleIds = Calendar::visibleTo($request->user(), $department->tenant_id)->pluck('id');

        $department->loadCount([
            'calendars' => fn ($q) => $q->whereIn('calendars.id', $visibleIds),
            'events' => fn ($q) => $q->whereIn('events.calendar_id', $visibleIds),
        ])->load(['calendars' => fn ($q) => $q->whereIn('calendars.id', $visibleIds)->orderBy('name')]);

        return response()->json(['data' => new DepartmentResource($department)]);
    }

    public function update(DepartmentRequest $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $department->update($request->validated());

        return response()->json(['data' => new DepartmentResource($department)]);
    }

    /**
     * Deleting a department never deletes calendars: they fall back to
     * ungrouped, which is what the nullOnDelete foreign key does.
     */
    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        $department->calendars()->update(['department_id' => null]);
        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }

    /** Move a calendar into this department, or out of every department. */
    public function assign(Request $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $calendar = Calendar::where('tenant_id', $department->tenant_id)
            ->findOrFail($request->integer('calendar_id'));

        $calendar->update(['department_id' => $request->boolean('detach') ? null : $department->id]);

        return response()->json(['message' => 'Calendar moved.']);
    }
}
