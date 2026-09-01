<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function __construct(protected WorkspaceService $workspaces) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->string('name'),
                'email' => $request->string('email')->lower(),
                'password' => $request->string('password'),
                'timezone' => $request->input('timezone', 'UTC'),
            ]);

            // Every new account starts inside a workspace of its own.
            $this->workspaces->create(
                $user,
                $request->input('workspace_name') ?: "{$user->name}'s Workspace",
                $request->input('timezone'),
            );

            return $user->fresh();
        });

        return $this->tokenResponse(JWTAuth::fromUser($user), $user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $token = Auth::guard('api')->attempt([
            'email' => strtolower($credentials['email']),
            'password' => $credentials['password'],
        ]);

        if (! $token) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return $this->tokenResponse($token, Auth::guard('api')->user());
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenants');

        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * Trades an expired-but-still-refreshable token for a fresh one.
     *
     * This route runs without `auth:api` on purpose: that middleware rejects an
     * expired token, which would make refreshing impossible exactly when it is
     * needed. The window is `jwt.refresh_ttl` (14 days by default); past that,
     * or with a tampered/blacklisted token, the client must sign in again.
     */
    public function refresh(): JsonResponse
    {
        try {
            $token = Auth::guard('api')->refresh();

            // Resolve the subject from the *new* token's payload. The guard's
            // user() re-reads the token off the request, which is the expired
            // one we just replaced, and would resolve to null.
            $user = User::find(JWTAuth::setToken($token)->getPayload()->get('sub'));
        } catch (JWTException) {
            return response()->json([
                'message' => 'Your session has expired. Please sign in again.',
            ], 401);
        }

        if (! $user) {
            return response()->json([
                'message' => 'Your session has expired. Please sign in again.',
            ], 401);
        }

        return $this->tokenResponse($token, $user);
    }

    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'Signed out.']);
    }

    protected function tokenResponse(string $token, User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
            'user' => new UserResource($user->load('tenants')),
        ], $status);
    }
}
