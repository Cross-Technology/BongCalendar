<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\UserResource;
use App\Models\Calendar;
use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function __construct(
        protected TenantContext $tenants,
        protected DepartmentService $departments,
    ) {}

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
                // Tasks are workspace-wide, so they are not visibility-filtered.
                'tasks' => fn ($q) => $q->whereNull('parent_task_id'),
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

    /* ------------------------------------------------------------- members */

    public function members(Department $department): AnonymousResourceCollection
    {
        $this->authorize('view', $department);

        return UserResource::collection($department->members()->orderBy('name')->get());
    }

    public function addMember(Request $request, Department $department): JsonResponse
    {
        $this->authorize('manageMembers', $department);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['nullable', Rule::in(DepartmentService::ROLES)],
        ]);

        $user = User::findOrFail($data['user_id']);

        try {
            $this->departments->addMember($department, $user, $data['role'] ?? 'member');
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Added to the department.',
            'data' => new UserResource($department->members()->whereKey($user->id)->firstOrFail()),
        ], 201);
    }

    public function removeMember(Department $department, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $department);

        $this->departments->removeMember($department, $user);

        return response()->json(['message' => 'Removed from the department.']);
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
