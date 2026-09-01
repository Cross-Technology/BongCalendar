<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant for the request and refuses anything the
 * authenticated user is not a member of.
 *
 * Precedence: X-Tenant header (slug or id) > user's current_tenant_id.
 * The header lets a single JWT serve a client that switches workspaces
 * without re-issuing the token.
 */
class ResolveTenant
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'Unauthenticated.', 401);
        }

        $requested = $request->header('X-Tenant') ?? $request->input('tenant');

        $tenant = $requested
            ? Tenant::where('slug', $requested)->orWhere('id', $requested)->first()
            : $user->currentTenant;

        if (! $tenant) {
            return $this->deny($request, 'No workspace selected.', 409);
        }

        if (! $user->belongsToTenant($tenant->id)) {
            return $this->deny($request, 'You are not a member of this workspace.', 403);
        }

        $this->context->set($tenant);

        // Keep the user's default in sync when they switch via header.
        if ($user->current_tenant_id !== $tenant->id) {
            $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        }

        return $next($request);
    }

    protected function deny(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], $status);
        }

        return redirect()->route('workspaces.index')->with('error', $message);
    }
}
