<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventInvitationResource;
use App\Models\Event;
use App\Models\EventInvitation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    public function __construct(protected TenantContext $tenants) {}

    /** Invitations addressed to the caller in the active workspace. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $invitations = EventInvitation::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('event', fn ($q) => $q->where('tenant_id', $this->tenants->require()->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['event.calendar:id,name,color'])
            ->get();

        return EventInvitationResource::collection($invitations);
    }

    public function respond(Request $request, Event $event): JsonResponse
    {
        $this->authorize('respond', $event);

        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'declined', 'tentative'])],
        ]);

        $invitation = $event->invitations()->where('user_id', $request->user()->id)->firstOrFail();

        $invitation->update([
            'status' => $data['status'],
            'responded_at' => now(),
        ]);

        return response()->json(['data' => new EventInvitationResource($invitation)]);
    }
}
