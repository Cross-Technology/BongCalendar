<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_returns_a_token_and_creates_a_starter_workspace(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rady',
            'email' => 'rady@example.com',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
            'timezone' => 'Asia/Phnom_Penh',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user' => ['id', 'email']]);

        $user = User::where('email', 'rady@example.com')->firstOrFail();

        $this->assertNotNull($user->current_tenant_id, 'a workspace should be created on registration');
        $this->assertSame(1, $user->tenants()->count());
        $this->assertSame('owner', $user->roleIn($user->current_tenant_id));
        // The starter workspace comes with a default calendar.
        $this->assertSame(1, $user->calendars()->where('is_default', true)->count());
    }

    public function test_login_rejects_bad_credentials(): void
    {
        User::factory()->create(['email' => 'rady@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'rady@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_returns_a_usable_token(): void
    {
        User::factory()->create(['email' => 'rady@example.com', 'password' => 'password']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'rady@example.com',
            'password' => 'password',
        ])->assertOk()->json('access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'rady@example.com');
    }

    public function test_protected_routes_reject_anonymous_callers(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/calendars')->assertUnauthorized();
    }

    /**
     * A client refreshes precisely because its access token has expired, so the
     * refresh route must accept an expired token that is still inside the
     * refresh window — otherwise every screen is stuck on "Unauthenticated."
     */
    public function test_an_expired_token_can_still_be_refreshed(): void
    {
        $user = User::factory()->create(['email' => 'rady@example.com']);
        app(WorkspaceService::class)->create($user, 'Acme');

        $expired = $this->travelTo(now()->subHours(2), fn () => JWTAuth::fromUser($user));

        // The expired token is rejected everywhere else...
        $this->withHeader('Authorization', "Bearer {$expired}")
            ->getJson('/api/v1/calendars')
            ->assertUnauthorized();

        // ...but still buys a fresh one.
        $token = $this->withHeader('Authorization', "Bearer {$expired}")
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonPath('user.email', 'rady@example.com')
            ->json('access_token');

        // Each real request is its own process; the test harness reuses one, and
        // the guard's JWT instance ('tymon.jwt') holds on to the first token it
        // parsed. Drop it so the new token is what actually gets checked.
        app('tymon.jwt')->unsetToken();
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/calendars')
            ->assertOk();
    }

    public function test_refresh_rejects_a_token_beyond_the_refresh_window(): void
    {
        $user = User::factory()->create();

        // refresh_ttl defaults to 14 days; a month-old token is past saving.
        $stale = $this->travelTo(now()->subDays(30), fn () => JWTAuth::fromUser($user));

        $this->withHeader('Authorization', "Bearer {$stale}")
            ->postJson('/api/v1/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your session has expired. Please sign in again.');
    }

    public function test_refresh_without_a_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }
}
