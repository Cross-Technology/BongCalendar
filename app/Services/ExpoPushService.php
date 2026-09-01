<?php

namespace App\Services;

use App\Models\PushToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivery through Expo's push service, which fans out to APNs and FCM.
 *
 * Two things matter here beyond posting JSON: Expo accepts at most 100
 * messages per request, and it reports tokens that no longer exist. Those are
 * deleted on the spot — a device that has uninstalled the app would otherwise
 * be retried twice a day forever.
 */
class ExpoPushService
{
    protected const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    protected const CHUNK = 100;

    /**
     * @param  Collection<int, PushToken>  $tokens
     * @param  array<string, mixed>  $data  Payload the app reads on tap.
     * @return int  How many messages were accepted.
     */
    public function send(Collection $tokens, string $title, string $body, array $data = []): int
    {
        if ($tokens->isEmpty() || ! config('services.expo.push_enabled', true)) {
            return 0;
        }

        $sent = 0;

        foreach ($tokens->chunk(self::CHUNK) as $chunk) {
            $messages = $chunk->map(fn (PushToken $token) => [
                'to' => $token->token,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'sound' => 'default',
                // Matches the channel declared in the app's expo-notifications
                // plugin config; Android drops messages naming a channel that
                // does not exist.
                'channelId' => 'reminders',
            ])->values()->all();

            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate',
                ])->timeout(15)->post(self::ENDPOINT, $messages);
            } catch (\Throwable $e) {
                Log::warning('Expo push request failed.', ['error' => $e->getMessage()]);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('Expo push rejected the batch.', ['status' => $response->status()]);

                continue;
            }

            $sent += $this->handleReceipts($response->json('data') ?? [], $chunk->values());
        }

        return $sent;
    }

    /**
     * @param  array<int, array<string, mixed>>  $receipts
     * @param  Collection<int, PushToken>  $tokens
     */
    protected function handleReceipts(array $receipts, Collection $tokens): int
    {
        $accepted = 0;

        foreach ($receipts as $index => $receipt) {
            if (($receipt['status'] ?? null) === 'ok') {
                $accepted++;

                continue;
            }

            $error = $receipt['details']['error'] ?? null;

            // The device is gone for good; anything else may be transient.
            if ($error === 'DeviceNotRegistered') {
                $tokens->get($index)?->delete();

                continue;
            }

            Log::info('Expo push message not delivered.', [
                'error' => $error,
                'message' => $receipt['message'] ?? null,
            ]);
        }

        return $accepted;
    }
}
