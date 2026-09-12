<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            ->set('form_body', '<div>Call Sok on <strong>Monday</strong>.</div>')
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

        $note = Note::where('body_text', 'Door code is 4821.')->firstOrFail();

        // The fast path is plain text, stored as markup like everything else.
        $this->assertSame('<div>Door code is 4821.</div>', $note->body);

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

        $this->assertSame('private', Note::where('body_text', 'Just for me.')->firstOrFail()->visibility);
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
            // The draft is escaped into markup on its way to the editor.
            ->assertSet('form_body', '<div>Needs a heading.</div>')
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

    public function test_a_note_opens_in_a_read_dialog(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
            'title' => 'Handover',
            'body' => str_repeat('A long note that the card can only preview. ', 20),
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->assertSet('viewingId', null)
            ->call('openNote', $note->id)
            ->assertSet('viewingId', $note->id)
            // Dialog-only affordances, so this cannot pass off the card alone.
            ->assertSee('Edit note')
            ->assertSee('Handover')
            ->call('closeNote')
            ->assertSet('viewingId', null);
    }

    public function test_a_member_can_read_a_shared_note_but_not_a_private_one(): void
    {
        $shared = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
        ]);

        $private = Note::factory()->private()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
        ]);

        $member = $this->member();

        Livewire::actingAs($member)
            ->test('pages::notes')
            ->call('openNote', $shared->id)
            ->assertSet('viewingId', $shared->id)
            // A reader with no edit rights gets no edit button.
            ->assertDontSee('Edit note');

        Livewire::actingAs($member)
            ->test('pages::notes')
            ->call('openNote', $private->id)
            ->assertForbidden();
    }

    public function test_editing_from_the_dialog_hands_over_to_the_editor(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
            'title' => 'Draft',
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('openNote', $note->id)
            ->call('edit', $note->id)
            // One dialog at a time.
            ->assertSet('viewingId', null)
            ->assertSet('showModal', true)
            ->assertSet('form_title', 'Draft');
    }

    public function test_deleting_the_note_being_read_closes_the_dialog(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('openNote', $note->id)
            ->call('delete', $note->id)
            ->assertSet('viewingId', null);

        $this->assertSoftDeleted('notes', ['id' => $note->id]);
    }

    /** A note can vanish under the reader — that must not throw at them. */
    public function test_the_dialog_closes_itself_if_the_note_goes_away(): void
    {
        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('openNote', $note->id)
            ->assertSet('viewingId', $note->id);

        $note->delete();

        $component->call('$refresh')
            ->assertOk()
            ->assertSet('viewingId', null);
    }

    public function test_the_rich_text_editor_is_rendered_and_shielded_from_livewire(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('create')
            ->assertSee('rich-editor', escape: false)
            ->assertSee('quillEditor(', escape: false)
            // Without wire:ignore, Livewire morphs the subtree the editor owns
            // and wipes what is being typed.
            ->assertSee('wire:ignore', escape: false);
    }

    /**
     * Same trap as the report header: behind wire:ignore a repeating wire:key
     * makes the morph reuse the element, so the editor opens showing the
     * previous note's text instead of this one's.
     */
    public function test_opening_the_editor_again_rebuilds_it(): void
    {
        $first = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
            'body' => '<p>First note</p>',
            'body_text' => 'First note',
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('edit', $first->id);

        preg_match('/wire:key="(note-editor-[^"]+)"/', $component->html(), $opened);
        $this->assertNotEmpty($opened, 'The note editor should carry a wire:key.');

        // The same note, opened a second time.
        $component->call('save')->call('edit', $first->id);

        preg_match('/wire:key="(note-editor-[^"]+)"/', $component->html(), $reopened);

        $this->assertNotSame($opened[1], $reopened[1], 'The editor must be rebuilt on each opening.');
    }

    public function test_the_editor_strips_dangerous_markup_before_saving(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('create')
            ->set('form_body', '<div>Safe</div><script>alert(1)</script><a href="javascript:alert(1)">x</a>')
            ->call('save')
            ->assertHasNoErrors();

        $note = Note::firstOrFail();

        $this->assertStringNotContainsString('<script', $note->body);
        $this->assertStringNotContainsString('javascript:', $note->body);
        $this->assertStringContainsString('Safe', $note->body);
        // The div is a block boundary, so the link text lands on its own line.
        $this->assertSame("Safe\nx", $note->body_text);
    }

    public function test_an_untouched_editor_is_not_a_note(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('create')
            // What an untouched editor posts.
            ->set('form_body', '<div><br></div>')
            ->call('save')
            ->assertHasErrors('form_body');

        $this->assertSame(0, Note::count());
    }

    public function test_a_file_can_be_attached_while_writing_a_note(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('create')
            ->set('form_body', '<div>Quote from the supplier</div>')
            ->set('uploads', [UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf')])
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoErrors()
            // Pending files are handed over once the note exists.
            ->assertSet('uploads', []);

        $attachment = Attachment::firstOrFail();

        $this->assertSame(Note::firstOrFail()->id, $attachment->attachable_id);
        $this->assertSame('quote.pdf', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_an_oversized_file_is_refused_as_it_is_chosen(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('create')
            ->set('uploads', [
                UploadedFile::fake()->create('huge.pdf', Attachment::MAX_KILOBYTES + 1, 'application/pdf'),
            ])
            ->assertHasErrors('uploads')
            // The bad pick is dropped rather than left to fail at save.
            ->assertSet('uploads', []);
    }

    public function test_a_good_file_survives_a_bad_one_in_the_same_pick(): void
    {
        Storage::fake('local');

        $component = Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('create')
            ->set('uploads', [
                UploadedFile::fake()->create('fine.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->create('nope.svg', 4, 'image/svg+xml'),
            ])
            ->assertHasErrors('uploads');

        $this->assertCount(1, $component->get('uploads'));
    }

    public function test_an_attachment_can_be_removed_from_a_note(): void
    {
        Storage::fake('local');

        $note = Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => $this->owner->id,
        ]);

        $attachment = app(AttachmentService::class)->store(
            UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'),
            $note,
            $this->owner,
            $this->tenant->id,
        );

        Livewire::actingAs($this->owner)
            ->test('pages::notes')
            ->call('edit', $note->id)
            ->call('removeAttachment', $attachment->id);

        $this->assertSame(0, Attachment::count());
        Storage::disk('local')->assertMissing($attachment->path);
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
