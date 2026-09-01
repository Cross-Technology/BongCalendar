<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EventRequest;
use App\Http\Resources\EventResource;
use App\Models\Calendar;
use App\Models\Event;
use App\Services\EventService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    public function __construct(
        protected TenantContext $tenants,
        protected EventService $events,
    ) {}

    /**
     * List events overlapping a window. Defaults to the current month so a
     * client can call this with no parameters and get something useful.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'calendar_ids' => ['nullable', 'array'],
            'calendar_ids.*' => ['integer'],
        ]);

        $user = $request->user();
        $timezone = $user->timezone ?: 'UTC';

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'], $timezone)
            : CarbonImmutable::now($timezone)->startOfMonth();

        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'], $timezone)
            : $from->endOfMonth();

        $events = $this->events->inRange(
            $user,
            $this->tenants->require()->id,
            $from->utc(),
            $to->utc(),
            $data['calendar_ids'] ?? null,
        );

        return EventResource::collection($events);
    }

    public function store(EventRequest $request): JsonResponse
    {
        $calendar = Calendar::findOrFail($request->integer('calendar_id'));

        $this->authorize('addEvents', $calendar);
        $this->assertSameTenant($calendar->tenant_id);

        $event = $this->events->create($calendar, $request->user(), $request->validated());

        return response()->json(['data' => new EventResource($event)], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json([
            'data' => new EventResource(
                $event->load(['calendar', 'creator:id,name,email', 'invitations.user:id,name,email', 'reminders'])
            ),
        ]);
    }

    public function update(EventRequest $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        // Moving an event between calendars needs write access on the target too.
        if ($request->filled('calendar_id') && $request->integer('calendar_id') !== $event->calendar_id) {
            $target = Calendar::findOrFail($request->integer('calendar_id'));
            $this->authorize('addEvents', $target);
            $this->assertSameTenant($target->tenant_id);
        }

        $event = $this->events->update($event, $request->validated());

        return response()->json(['data' => new EventResource($event)]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        $event->delete();

        return response()->json(['message' => 'Event deleted.']);
    }

    protected function assertSameTenant(int $tenantId): void
    {
        abort_unless($tenantId === $this->tenants->require()->id, 403, 'Wrong workspace.');
    }
}
