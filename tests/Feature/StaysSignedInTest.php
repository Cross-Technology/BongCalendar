<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPOpenSourceSaver\JWTAuth\Blacklist;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Signing out is the only thing that ends a session — not time.
 */
class StaysSignedInTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'rady@example.com',
            'password' => 'password',
            // Cleared so the assertions below mean something: the factory
            // seeds one, and Laravel only fills an empty remember_token.
            'remember_token' => null,
        ]);

        app(WorkspaceService::class)->create($this->user, 'Acme');
        $this->user->refresh();
    }

    protected function login(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => 'rady@example.com',
            'password' => 'password',
        ])->assertOk()->json('access_token');
    }

    /* -------------------------------------------------------------- the API */

    public function test_an_issued_token_carries_no_expiry(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'rady@example.com',
            'password' => 'password',
        ])->assertOk();

        // A client that sees null here knows not to schedule a refresh.
        $this->assertNull($response->json('expires_in'));

        $payload = JWTAuth::setToken($response->json('access_token'))->getPayload();

        $this->assertNull($payload->get('exp'), 'The token should not carry an exp claim.');
    }

    public function test_a_token_still_works_years_later(): void
    {
        $token = $this->login();

        // Far past anything the old 60-minute TTL or 14-day refresh allowed.
        $this->travel(5)->years();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'rady@example.com');
    }

    /**
     * The other half of the promise: a token that never expires has to be
     * revocable, or signing out would mean nothing.
     */
    public function test_signing_out_revokes_a_token_that_never_expires(): void
    {
        $token = $this->login();

        // Read the payload before it is blacklisted — afterwards, asking for it
        // throws rather than returning claims.
        $payload = JWTAuth::setToken($token)->getPayload();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertTrue(
            app(Blacklist::class)->has($payload),
            'Signing out must blacklist the token, since nothing else will.',
        );
    }

    public function test_a_signed_out_token_is_refused(): void
    {
        $token = $this->login();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    /* ---------------------------------------------------------- the browser */

    public function test_signing_in_keeps_the_browser_signed_in(): void
    {
        Livewire::test('pages::auth.login')
            ->set('email', 'rady@example.com')
            ->set('password', 'password')
            ->call('login');

        $this->assertAuthenticatedAs($this->user);

        // The remember token is what brings someone back once the session
        // cookie is gone — without it, "stay signed in" lasts one session.
        // Laravel only writes this when the remember flag is set, so its
        // presence is the proof that sign-in asked to be remembered.
        $this->assertNotNull($this->user->fresh()->remember_token);
    }

    public function test_registering_keeps_the_new_account_signed_in(): void
    {
        Livewire::test('pages::auth.register')
            ->set('name', 'Sophea')
            ->set('email', 'sophea@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $user = User::where('email', 'sophea@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->remember_token);
    }

    public function test_signing_out_ends_it(): void
    {
        Livewire::test('pages::auth.login')
            ->set('email', 'rady@example.com')
            ->set('password', 'password')
            ->call('login');

        $remembered = $this->user->fresh()->remember_token;
        $this->assertNotNull($remembered);

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();

        // Cycled, so the cookie on the old device no longer signs anyone in.
        $this->assertNotSame($remembered, $this->user->fresh()->remember_token);
    }
}
