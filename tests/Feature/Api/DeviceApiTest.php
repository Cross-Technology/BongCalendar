<?php

namespace Tests\Feature\Api;

use App\Models\PushToken;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->create($user, 'Acme');

        return $user->refresh();
    }

    protected function as(User $user): static
    {
        app('tymon.jwt')->unsetToken();
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user->fresh()));
    }

    public function test_a_device_registers_and_unregisters(): void
    {
        $user = $this->makeUser();

        $this->as($user)
            ->postJson('/api/v1/devices', [
                'token' => 'ExponentPushToken[abc]',
                'platform' => 'ios',
                'device_name' => "Rady's iPad",
            ])->assertCreated();

        $this->assertDatabaseHas('push_tokens', [
            'token' => 'ExponentPushToken[abc]',
            'user_id' => $user->id,
        ]);

        // Registering the same token again updates rather than duplicates.
        $this->as($user)->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[abc]'])->assertCreated();
        $this->assertSame(1, PushToken::count());

        $this->as($user)->deleteJson('/api/v1/devices', ['token' => 'ExponentPushToken[abc]'])->assertOk();
        $this->assertSame(0, PushToken::count());
    }

    public function test_a_shared_device_follows_whoever_signed_in_last(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();

        $this->as($first)->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[tablet]'])->assertCreated();
        $this->as($second)->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[tablet]'])->assertCreated();

        $this->assertSame(1, PushToken::count());
        $this->assertSame($second->id, PushToken::firstOrFail()->user_id);
    }

    public function test_digest_preferences_can_be_changed(): void
    {
        $user = $this->makeUser();

        $this->as($user)
            ->patchJson('/api/v1/me/notifications', [
                'digest_enabled' => true,
                'digest_morning_hour' => 7,
                'digest_evening_hour' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.digest_morning_hour', 7)
            ->assertJsonPath('data.digest_evening_hour', 20);
    }

    public function test_the_two_digests_cannot_share_an_hour(): void
    {
        $user = $this->makeUser();

        $this->as($user)
            ->patchJson('/api/v1/me/notifications', [
                'digest_morning_hour' => 8,
                'digest_evening_hour' => 8,
            ])
            ->assertStatus(422);
    }

    public function test_devices_require_authentication(): void
    {
        $this->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[x]'])->assertUnauthorized();
    }
}
