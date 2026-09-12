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
     * Tokens do not expire — people stay signed in until they sign out — so a
     * client never has to refresh. The route stays because rotating a token
     * without signing out is still worth doing, and it must work on a token of
     * any age rather than quietly cutting clients off at some window.
     */
    public function test_refresh_rotates_a_token_of_any_age(): void
    {
        $user = User::factory()->create(['email' => 'rady@example.com']);
        app(WorkspaceService::class)->create($user, 'Acme');

        $old = $this->travelTo(now()->subYear(), fn () => JWTAuth::fromUser($user));

        // Age alone shuts nobody out any more.
        $this->withHeader('Authorization', "Bearer {$old}")
            ->getJson('/api/v1/calendars')
            ->assertOk();

        $token = $this->withHeader('Authorization', "Bearer {$old}")
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

    /**
     * Signing out is the boundary that replaced expiry: a revoked token cannot
     * be traded back in for a working one.
     */
    public function test_a_signed_out_token_cannot_be_refreshed(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->create($user, 'Acme');

        $token = JWTAuth::fromUser($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        app('tymon.jwt')->unsetToken();
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your session has expired. Please sign in again.');
    }

    public function test_refresh_without_a_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }
}
