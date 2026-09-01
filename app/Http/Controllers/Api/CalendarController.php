<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CalendarRequest;
use App\Http\Resources\CalendarResource;
use App\Http\Resources\CalendarShareResource;
use App\Models\Calendar;
use App\Models\CalendarShare;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CalendarController extends Controller
{
    public function __construct(protected TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $calendars = Calendar::visibleTo($request->user(), $this->tenants->require()->id)
            ->withCount('events')
            ->when($request->filled('department'), fn ($q) => $q->where('department_id', $request->integer('department')))
            ->with(['owner:id,name,email', 'department:id,tenant_id,name,color,slug'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return CalendarResource::collection($calendars);
    }

    public function store(CalendarRequest $request): JsonResponse
    {
        $tenant = $this->tenants->require();

        $calendar = Calendar::create([
            'tenant_id' => $tenant->id,
            'owner_id' => $request->user()->id,
            'department_id' => $request->input('department_id'),
            'name' => $request->string('name'),
            'description' => $request->input('description'),
            'color' => $request->input('color', '#2563eb'),
            'timezone' => $request->input('timezone', $tenant->timezone),
            'visibility' => $request->input('visibility', 'private'),
        ]);

        return response()->json(['data' => new CalendarResource($calendar)], 201);
    }

    public function show(Request $request, Calendar $calendar): JsonResponse
    {
        $this->authorize('view', $calendar);

        return response()->json([
            'data' => new CalendarResource(
                $calendar->loadCount('events')->load(['owner:id,name,email', 'shares.user:id,name,email'])
            ),
        ]);
    }

    public function update(CalendarRequest $request, Calendar $calendar): JsonResponse
    {
        $this->authorize('update', $calendar);

        $calendar->update($request->validated());

        return response()->json(['data' => new CalendarResource($calendar)]);
    }

    public function destroy(Request $request, Calendar $calendar): JsonResponse
    {
        $this->authorize('delete', $calendar);

        $calendar->delete();

        return response()->json(['message' => 'Calendar deleted.']);
    }

    public function shares(Request $request, Calendar $calendar): AnonymousResourceCollection
    {
        $this->authorize('view', $calendar);

        return CalendarShareResource::collection(
            $calendar->shares()->with('user:id,name,email')->get()
        );
    }

    /** Share with a workspace member, or update their existing permission. */
    public function share(Request $request, Calendar $calendar): JsonResponse
    {
        $this->authorize('share', $calendar);

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'permission' => ['required', Rule::in(CalendarShare::PERMISSIONS)],
        ]);

        $user = User::where('email', strtolower($data['email']))->firstOrFail();

        if ($user->id === $calendar->owner_id) {
            return response()->json(['message' => 'The owner already has full access.'], 422);
        }

        if (! $user->belongsToTenant($calendar->tenant_id)) {
            return response()->json(['message' => 'That user is not a member of this workspace.'], 422);
        }

        $share = CalendarShare::updateOrCreate(
            ['calendar_id' => $calendar->id, 'user_id' => $user->id],
            ['permission' => $data['permission'], 'invited_by' => $request->user()->id],
        );

        return response()->json([
            'data' => new CalendarShareResource($share->load('user:id,name,email')),
        ], 201);
    }

    public function revokeShare(Request $request, Calendar $calendar, User $user): JsonResponse
    {
        $this->authorize('share', $calendar);

        $calendar->shares()->where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Access revoked.']);
    }
}
