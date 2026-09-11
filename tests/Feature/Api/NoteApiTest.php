<?php

namespace Tests\Feature\Api;

use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class NoteApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tenant = app(WorkspaceService::class)->create($this->user, 'Acme');
        $this->user->refresh();

        $this->actingAsApi($this->user);
    }

    /**
     * Swap the bearer token. The guard resolves its user once per test run, so
     * this must be called before the test's first request — each test speaks
     * as a single identity.
     */
    protected function actingAsApi(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
    }

    /** A member of the workspace who is not the author. */
    protected function member(string $role = 'member'): User
    {
        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member, $role);

        return $member->refresh();
    }

    public function test_a_note_can_be_taken_and_listed(): void
    {
        $this->postJson('/api/v1/notes', [
            'title' => 'Supplier',
            'body' => 'Call Sok on Monday.',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Supplier')
            ->assertJsonPath('data.tenant_id', $this->tenant->id)
            ->assertJsonPath('data.author_id', $this->user->id)
            // Shared with the workspace unless the author says otherwise.
            ->assertJsonPath('data.visibility', 'tenant');

        $this->getJson('/api/v1/notes')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Supplier')
            ->assertJsonPath('data.0.is_mine', true);
    }

    public function test_a_note_needs_a_body(): void
    {
        $this->postJson('/api/v1/notes', ['title' => 'Empty'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_an_untitled_note_falls_back_to_its_first_line(): void
    {
        $this->postJson('/api/v1/notes', ['body' => "Door code 4821\nSecond line"])
            ->assertCreated()
            ->assertJsonPath('data.title', null)
            ->assertJsonPath('data.display_title', 'Door code 4821');
    }

    /** Seeds one shared and one private note, both written by the owner. */
    protected function seedBoard(): void
    {
        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
            'title' => 'Shared plan',
        ]);

        Note::factory()->private()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
            'title' => 'My own thoughts',
        ]);
    }

    public function test_an_author_sees_their_private_note_beside_the_shared_ones(): void
    {
        $this->seedBoard();

        $this->getJson('/api/v1/notes')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_member_sees_shared_notes_but_not_private_ones(): void
    {
        $this->seedBoard();

        $this->actingAsApi($this->member());

        $this->getJson('/api/v1/notes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Shared plan');
    }

    public function test_another_member_cannot_read_a_private_note_directly(): void
    {
        $note = Note::factory()->private()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
        ]);

        $this->actingAsApi($this->member());

        $this->getJson("/api/v1/notes/{$note->id}")->assertForbidden();
    }

    public function test_notes_never_leak_across_workspaces(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
        ]);

        $outsider = User::factory()->create();
        app(WorkspaceService::class)->create($outsider, 'Other Co');

        $this->actingAsApi($outsider->refresh());

        $this->getJson('/api/v1/notes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/notes/{$note->id}")->assertForbidden();
    }

    public function test_a_member_cannot_edit_or_delete_someone_elses_note(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
        ]);

        $this->actingAsApi($this->member());

        $this->patchJson("/api/v1/notes/{$note->id}", ['body' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/v1/notes/{$note->id}")->assertForbidden();
    }

    public function test_an_admin_may_tidy_a_shared_note_but_not_publish_a_private_one(): void
    {
        $shared = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
        ]);

        $private = Note::factory()->private()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
        ]);

        $this->actingAsApi($this->member('admin'));

        $this->patchJson("/api/v1/notes/{$shared->id}", ['body' => 'Tidied up.'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Tidied up.');

        // A private note is closed to admins too — that is what `private` promises.
        $this->patchJson("/api/v1/notes/{$private->id}", ['body' => 'Nope'])->assertForbidden();
    }

    public function test_an_admin_cannot_flip_the_visibility_of_someone_elses_note(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
        ]);

        $this->actingAsApi($this->member('admin'));

        $this->patchJson("/api/v1/notes/{$note->id}", [
            'body' => 'Still shared.',
            'visibility' => 'private',
        ])->assertOk()->assertJsonPath('data.visibility', 'tenant');

        $this->assertSame('tenant', $note->fresh()->visibility);
    }

    public function test_the_author_can_make_a_note_private_again(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
        ]);

        $this->patchJson("/api/v1/notes/{$note->id}", ['visibility' => 'private'])
            ->assertOk()
            ->assertJsonPath('data.visibility', 'private');
    }

    public function test_pinning_toggles_and_floats_the_note_to_the_top(): void
    {
        $first = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
            'title' => 'Older',
        ]);

        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
            'title' => 'Newer',
        ]);

        $this->postJson("/api/v1/notes/{$first->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.is_pinned', true);

        $this->getJson('/api/v1/notes')->assertOk()->assertJsonPath('data.0.title', 'Older');

        $this->postJson("/api/v1/notes/{$first->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.is_pinned', false);
    }

    public function test_the_list_can_be_searched_and_narrowed(): void
    {
        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
            'title' => 'Door code',
            'body' => 'The code is 4821.',
        ]);

        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
            'title' => 'Lunch order',
            'body' => 'Two coffees.',
        ]);

        $this->getJson('/api/v1/notes?q=4821')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Door code');

        // Matching on the title works just as well as on the body.
        $this->getJson('/api/v1/notes?q=Lunch')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Lunch order');

        $other = $this->member();
        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $other->id,
            'title' => 'Not mine',
        ]);

        $this->getJson('/api/v1/notes?mine=1')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_an_author_can_delete_their_own_note(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->user->id,
        ]);

        $this->deleteJson("/api/v1/notes/{$note->id}")->assertOk();

        $this->assertSoftDeleted('notes', ['id' => $note->id]);
        $this->getJson('/api/v1/notes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_notes_require_authentication(): void
    {
        $this->withHeader('Authorization', '')->getJson('/api/v1/notes')->assertUnauthorized();
    }
}
