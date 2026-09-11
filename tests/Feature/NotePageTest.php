<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotePageTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme', 'Asia/Phnom_Penh');
        $this->owner->refresh();
    }

    protected function member(string $role = 'member'): User
    {
        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member, $role);

        return $member->refresh();
    }

    public function test_the_page_lists_the_workspace_noticeboard(): void
    {
        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
            'title' => 'Door code',
        ]);

        $this->actingAs($this->owner)
            ->get('/notes')
            ->assertOk()
            ->assertSee('Door code')
            ->assertSee('Notes')
            // The inline composer is the primary way in, so it must be on the
            // page itself rather than behind the modal.
            ->assertSee('Write a note', escape: false)
            // Each card carries its own colour into the CSS tint.
            ->assertSee('--note-color: #', escape: false)
            ->assertSee('note-card', escape: false);
    }

    public function test_a_member_can_write_a_note(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('create')
            ->set('form_title', 'Supplier')
            ->set('form_body', 'Call Sok on Monday.')
            ->set('form_color', '#10b981')
            ->call('save')
            ->assertSet('showModal', false)
            ->assertHasNoErrors();

        $note = Note::where('title', 'Supplier')->firstOrFail();

        $this->assertSame($this->tenant->id, $note->tenant_id);
        $this->assertSame($this->owner->id, $note->author_id);
        $this->assertSame('tenant', $note->visibility);
    }

    public function test_the_inline_composer_writes_a_note_without_a_title(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->set('quick_body', 'Door code is 4821.')
            ->set('quick_color', '#10b981')
            ->call('quickSave')
            ->assertHasNoErrors()
            // The composer empties itself, ready for the next thought.
            ->assertSet('quick_body', '')
            ->assertDispatched('note-saved');

        $note = Note::where('body', 'Door code is 4821.')->firstOrFail();

        $this->assertNull($note->title);
        $this->assertSame('#10b981', $note->color);
        $this->assertSame('tenant', $note->visibility);
        $this->assertSame($this->owner->id, $note->author_id);
        $this->assertSame($this->tenant->id, $note->tenant_id);
    }

    public function test_the_inline_composer_can_keep_a_note_private(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->set('quick_body', 'Just for me.')
            ->set('quick_visibility', 'private')
            ->call('quickSave')
            ->assertHasNoErrors();

        $this->assertSame('private', Note::where('body', 'Just for me.')->firstOrFail()->visibility);
    }

    public function test_the_inline_composer_rejects_an_empty_note(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->set('quick_body', '')
            ->call('quickSave')
            ->assertHasErrors('quick_body');

        $this->assertSame(0, Note::count());
    }

    public function test_a_draft_carries_into_the_full_editor(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->set('quick_body', 'Needs a heading.')
            ->set('quick_color', '#ef4444')
            ->call('expandDraft')
            ->assertSet('showModal', true)
            ->assertSet('form_body', 'Needs a heading.')
            ->assertSet('form_color', '#ef4444')
            // Nothing is written until the full editor is submitted.
            ->assertSet('editingId', null);

        $this->assertSame(0, Note::count());
    }

    public function test_a_note_cannot_be_saved_empty(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('create')
            ->set('form_body', '')
            ->call('save')
            ->assertHasErrors('form_body');
    }

    public function test_a_private_note_stays_with_its_author(): void
    {
        Note::factory()->private()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
            'title' => 'My own thoughts',
        ]);

        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
            'title' => 'Shared plan',
        ]);

        $this->actingAs($this->owner)->get('/notes')
            ->assertSee('My own thoughts')
            ->assertSee('Shared plan');

        $this->actingAs($this->member())->get('/notes')
            ->assertDontSee('My own thoughts')
            ->assertSee('Shared plan');
    }

    public function test_a_member_cannot_delete_someone_elses_note(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
        ]);

        Livewire::actingAs($this->member())
            ->test('pages::notes')
            ->call('delete', $note->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted('notes', ['id' => $note->id]);
    }

    public function test_the_author_can_edit_pin_and_delete(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
            'title' => 'Draft',
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('edit', $note->id)
            ->assertSet('form_title', 'Draft')
            ->set('form_title', 'Final')
            ->call('save')
            ->assertHasNoErrors()
            ->call('togglePin', $note->id);

        $note->refresh();
        $this->assertSame('Final', $note->title);
        $this->assertTrue($note->is_pinned);

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('delete', $note->id);

        $this->assertSoftDeleted('notes', ['id' => $note->id]);
    }

    public function test_the_board_can_be_searched_and_filtered(): void
    {
        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
            'title' => 'Door code',
        ]);

        $other = $this->member();
        Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $other->id,
            'title' => 'Lunch order',
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->set('search', 'Door')
            ->assertSee('Door code')
            ->assertDontSee('Lunch order')
            ->set('search', '')
            ->call('setFilter', 'mine')
            ->assertSee('Door code')
            ->assertDontSee('Lunch order');
    }

    public function test_notes_from_another_workspace_never_appear(): void
    {
        $outsider = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($outsider, 'Other Co');

        Note::factory()->create([
            'tenant_id' => $otherTenant->id,
            'author_id' => $outsider->id,
            'title' => 'Their secret',
        ]);

        $this->actingAs($this->owner)->get('/notes')->assertDontSee('Their secret');
    }

    public function test_a_full_board_paginates(): void
    {
        Note::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->get('/notes')->assertOk();
        $this->actingAs($this->owner)->get('/notes?page=2')->assertOk();
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/notes')->assertRedirect('/login');
    }
}
