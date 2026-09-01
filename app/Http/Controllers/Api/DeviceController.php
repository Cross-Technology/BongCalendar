<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Devices that should receive push notifications, and the preferences that
 * decide when.
 */
class DeviceController extends Controller
{
    /**
     * Registers this device, or moves an existing token to this account.
     *
     * Tokens follow the install, not the person: signing in as someone else on
     * a shared tablet must not keep sending them the previous user's tasks.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', Rule::in(['ios', 'android', 'web'])],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $token = PushToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'last_active_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Device registered.',
            'data' => ['id' => $token->id, 'platform' => $token->platform],
        ], 201);
    }

    /** Signing out, or turning notifications off for this device only. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        PushToken::where('token', $data['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Device removed.']);
    }

    /** When the digests arrive, in the account's own timezone. */
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'digest_enabled' => ['sometimes', 'boolean'],
            'digest_morning_hour' => ['sometimes', 'integer', 'between:0,23'],
            'digest_evening_hour' => ['sometimes', 'integer', 'between:0,23'],
            'timezone' => ['sometimes', 'timezone'],
        ]);

        if (
            ($data['digest_morning_hour'] ?? $request->user()->digest_morning_hour)
            === ($data['digest_evening_hour'] ?? $request->user()->digest_evening_hour)
        ) {
            return response()->json([
                'message' => 'The morning and evening digests need different hours.',
            ], 422);
        }

        $request->user()->update($data);

        return response()->json(['data' => new UserResource($request->user()->fresh())]);
    }
}
