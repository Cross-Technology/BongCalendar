<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Models\Tenant;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Two ways into a workspace:
 *
 *  - An invitation addressed to an email, which grants nothing until that
 *    person accepts it.
 *  - The workspace's own code, which whoever holds it can redeem themselves.
 *
 * Both end in the same place — a membership — but only the first waits on an
 * answer, which is why they are separate.
 */
class WorkspaceInvitationController extends Controller
{
    public function __construct(protected WorkspaceService $workspaces) {}

    /* -------------------------------------------------- for administrators */

    public function index(Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('manageMembers', $tenant);

        return WorkspaceInvitationResource::collection(
            $tenant->invitations()->with('inviter:id,name,email')->latest()->get()
        );
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('manageMembers', $tenant);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', Rule::in(['member', 'admin'])],
        ]);

        $email = strtolower(trim($data['email']));

        if ($tenant->users()->where('email', $email)->exists()) {
            return response()->json(['message' => 'They are already a member of this workspace.'], 422);
        }

        // Re-inviting refreshes the open invitation rather than stacking a
        // second one the invitee would have to answer twice.
        $invitation = $tenant->invitations()->pending()->forEmail($email)->first()
            ?? new WorkspaceInvitation(['tenant_id' => $tenant->id, 'email' => $email]);

        $invitation->fill([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'role' => $data['role'] ?? 'member',
            'invited_by' => $request->user()->id,
            'status' => 'pending',
            'expires_at' => now()->addDays(14),
        ])->save();

        return response()->json([
            'data' => new WorkspaceInvitationResource($invitation->load('inviter:id,name,email')),
        ], 201);
    }

    /** Withdraws an invitation that has not been answered. */
    public function destroy(Tenant $tenant, WorkspaceInvitation $invitation): JsonResponse
    {
        $this->authorize('manageMembers', $tenant);
        abort_unless($invitation->tenant_id === $tenant->id, 404);

        $invitation->forceFill(['status' => 'revoked', 'responded_at' => now()])->save();

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /* ------------------------------------------------------ for the invitee */

    /** Invitations waiting on the signed-in account. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $invitations = WorkspaceInvitation::pending()
            ->forEmail($request->user()->email)
            ->with(['tenant:id,name,slug', 'inviter:id,name,email'])
            ->latest()
            ->get();

        return WorkspaceInvitationResource::collection($invitations);
    }

    public function accept(Request $request, WorkspaceInvitation $invitation): JsonResponse
    {
        $user = $request->user();

        abort_unless(strcasecmp($invitation->email, $user->email) === 0, 403, 'This invitation is for someone else.');

        if (! $invitation->isOpen()) {
            return response()->json([
                'message' => $invitation->isExpired()
                    ? 'This invitation has expired. Ask for a new one.'
                    : 'This invitation is no longer open.',
            ], 410);
        }

        $invitation->accept($user, $this->workspaces);

        return response()->json([
            'message' => "You joined {$invitation->tenant->name}.",
            'data' => new WorkspaceInvitationResource($invitation->load('tenant:id,name,slug')),
        ]);
    }

    public function decline(Request $request, WorkspaceInvitation $invitation): JsonResponse
    {
        abort_unless(
            strcasecmp($invitation->email, $request->user()->email) === 0,
            403,
            'This invitation is for someone else.'
        );

        if (! $invitation->isOpen()) {
            return response()->json(['message' => 'This invitation is no longer open.'], 410);
        }

        $invitation->decline();

        return response()->json(['message' => 'Invitation declined.']);
    }

    /* ------------------------------------------------------------ join code */

    /** Looks a code up without joining, so the UI can name the workspace first. */
    public function preview(string $code): JsonResponse
    {
        $tenant = Tenant::where('invite_code', strtoupper(trim($code)))->first();

        if (! $tenant) {
            return response()->json(['message' => 'That code does not match any workspace.'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'members_count' => $tenant->users()->count(),
            ],
        ]);
    }

    /** Redeems a workspace code. The holder of the code is the one deciding. */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $tenant = Tenant::where('invite_code', strtoupper(trim($data['code'])))->first();

        if (! $tenant) {
            return response()->json(['message' => 'That code does not match any workspace.'], 404);
        }

        $user = $request->user();

        if ($user->belongsToTenant($tenant->id)) {
            return response()->json(['message' => "You are already in {$tenant->name}."], 422);
        }

        $this->workspaces->addMember($tenant, $user, 'member');

        return response()->json([
            'message' => "You joined {$tenant->name}.",
            'data' => ['id' => $tenant->id, 'name' => $tenant->name],
        ]);
    }

    /** Invalidates the old code, for when it has been shared too widely. */
    public function regenerateCode(Tenant $tenant): JsonResponse
    {
        $this->authorize('manageMembers', $tenant);

        $tenant->forceFill(['invite_code' => Tenant::generateInviteCode()])->save();

        return response()->json(['data' => ['invite_code' => $tenant->invite_code]]);
    }
}
