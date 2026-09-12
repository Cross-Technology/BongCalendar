<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(protected WorkspaceService $workspaces) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenants = $request->user()->tenants()
            ->withCount(['users', 'calendars'])
            ->orderBy('name')
            ->get();

        return TenantResource::collection($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $tenant = $this->workspaces->create($request->user(), $data['name'], $data['timezone'] ?? null);

        return response()->json(['data' => new TenantResource($tenant)], 201);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        return response()->json([
            'data' => new TenantResource($tenant->loadCount(['users', 'calendars'])->load('users')),
        ]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
        ]);

        $tenant->update($data);

        return response()->json(['data' => new TenantResource($tenant)]);
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('delete', $tenant);

        $tenant->delete();

        return response()->json(['message' => 'Workspace deleted.']);
    }

    /** Make this workspace the caller's active one. */
    public function switch(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $request->user()->forceFill(['current_tenant_id' => $tenant->id])->save();

        return response()->json(['data' => new TenantResource($tenant)]);
    }

    public function members(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        return UserResource::collection($tenant->users()->orderBy('name')->get());
    }

    public function addMember(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('manageMembers', $tenant);

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['nullable', Rule::in(['admin', 'member'])],
        ]);

        $user = User::where('email', strtolower($data['email']))->firstOrFail();

        $this->workspaces->addMember($tenant, $user, $data['role'] ?? 'member');

        return response()->json(['message' => 'Member added.', 'data' => new UserResource($user)], 201);
    }

    /**
     * Promote a member to admin, or put an admin back to member. Owner-only —
     * see TenantPolicy::manageRoles.
     */
    public function updateMemberRole(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        $this->authorize('manageRoles', $tenant);

        $data = $request->validate([
            'role' => ['required', Rule::in(WorkspaceService::ASSIGNABLE_ROLES)],
        ]);

        if ($tenant->owner_id === $user->id) {
            return response()->json(['message' => "The workspace owner's role cannot be changed."], 422);
        }

        if (! $user->belongsToTenant($tenant->id)) {
            return response()->json(['message' => 'That user is not a member of this workspace.'], 404);
        }

        $this->workspaces->changeRole($tenant, $user, $data['role']);

        // Re-read through the relation so the pivot — and so the resource's
        // `role` — reflects the change rather than the role it came in with.
        $member = $tenant->users()->whereKey($user->id)->firstOrFail();

        return response()->json([
            'message' => 'Role updated.',
            'data' => new UserResource($member),
        ]);
    }

    public function removeMember(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $tenant);

        if ($tenant->owner_id === $user->id) {
            return response()->json(['message' => 'The workspace owner cannot be removed.'], 422);
        }

        $this->workspaces->removeMember($tenant, $user);

        return response()->json(['message' => 'Member removed.']);
    }
}
