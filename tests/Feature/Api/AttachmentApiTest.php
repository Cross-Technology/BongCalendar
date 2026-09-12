<?php

namespace Tests\Feature\Api;

use App\Models\Attachment;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;
use ZipArchive;

class AttachmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->tenant = app(WorkspaceService::class)->create($this->user, 'Acme');
        $this->user->refresh();

        $this->actingAsApi($this->user);
    }

    /** The guard resolves its user once per test, so call before any request. */
    protected function actingAsApi(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
    }

    protected function member(): User
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $user, 'member');

        return $user->refresh();
    }

    protected function note(string $visibility = 'tenant', ?User $author = null): Note
    {
        return Note::factory()->create([
            'tenant_id' => $this->tenant->id,
            'author_id' => ($author ?? $this->user)->id,
            'visibility' => $visibility,
        ]);
    }

    protected function pdf(string $name = 'report.pdf', int $kilobytes = 10): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, "%PDF-1.4\n".str_repeat('x', $kilobytes * 1024)."\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /**
     * A genuine Office file, not a fake with a declared MIME type. Real .docx
     * and .xlsx are zip archives, and `mimes:` validates by sniffing content —
     * so this is the case most likely to be wrongly rejected in production
     * while a mime-declaring fake sails through.
     */
    protected function office(string $extension): UploadedFile
    {
        [$contentType, $main] = $extension === 'docx'
            ? ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word/document.xml']
            : ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xl/workbook.xml'];

        $path = tempnam(sys_get_temp_dir(), 'office').'.'.$extension;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            ."<Override PartName=\"/{$main}\" ContentType=\"{$contentType}\"/></Types>");
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            ."<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"{$main}\"/></Relationships>");
        $zip->addFromString($main, '<?xml version="1.0"?><root/>');
        $zip->close();

        return new UploadedFile($path, 'sheet.'.$extension, mime_content_type($path), null, true);
    }

    /**
     * Attaches without going through HTTP. The JWT guard resolves its user on
     * the first request of a test and keeps it, so any test that needs to act
     * as someone else must not spend that first request as the owner — doing
     * so silently runs the assertions as the wrong person.
     */
    protected function attach(Note $note, ?UploadedFile $file = null): Attachment
    {
        return app(AttachmentService::class)->store(
            $file ?? $this->pdf(),
            $note,
            $this->user,
            $this->tenant->id,
        );
    }

    public function test_a_pdf_can_be_attached_to_a_note(): void
    {
        $note = $this->note();

        $this->post("/api/v1/notes/{$note->id}/attachments", ['file' => $this->pdf()])
            ->assertCreated()
            ->assertJsonPath('data.original_name', 'report.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.extension', 'pdf');

        $attachment = Attachment::firstOrFail();

        $this->assertSame($note->id, $attachment->attachable_id);
        $this->assertSame(Note::class, $attachment->attachable_type);
        $this->assertSame($this->tenant->id, $attachment->tenant_id);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_real_word_and_excel_files_are_accepted(): void
    {
        $note = $this->note();

        foreach (['docx', 'xlsx'] as $extension) {
            $this->post("/api/v1/notes/{$note->id}/attachments", ['file' => $this->office($extension)])
                ->assertCreated()
                ->assertJsonPath('data.extension', $extension);
        }

        $this->assertSame(2, Attachment::count());
    }

    public function test_a_file_over_five_megabytes_is_refused(): void
    {
        $note = $this->note();

        $this->post("/api/v1/notes/{$note->id}/attachments", [
            'file' => UploadedFile::fake()->create('huge.pdf', Attachment::MAX_KILOBYTES + 1, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, Attachment::count());
    }

    /** SVG is a script-carrying document browsers render, so it stays out. */
    public function test_executable_and_svg_uploads_are_refused(): void
    {
        $note = $this->note();

        foreach (['payload.svg' => 'image/svg+xml', 'payload.exe' => 'application/octet-stream'] as $name => $mime) {
            $this->post("/api/v1/notes/{$note->id}/attachments", [
                'file' => UploadedFile::fake()->create($name, 8, $mime),
            ])->assertUnprocessable()->assertJsonValidationErrors('file');
        }

        $this->assertSame(0, Attachment::count());
    }

    /** The uploader's filename is shown, never used to build the path. */
    public function test_the_stored_path_is_generated_not_taken_from_the_upload(): void
    {
        $note = $this->note();

        $this->post("/api/v1/notes/{$note->id}/attachments", [
            'file' => $this->pdf('../../../../etc/passwd.pdf'),
        ])->assertCreated();

        $attachment = Attachment::firstOrFail();

        $this->assertStringNotContainsString('..', $attachment->path);
        $this->assertStringStartsWith("attachments/{$this->tenant->id}/", $attachment->path);
        // Kept for display, with the separators defused.
        $this->assertStringNotContainsString('/', $attachment->original_name);
    }

    public function test_a_member_can_download_an_attachment_on_a_shared_note(): void
    {
        $attachment = $this->attach($this->note());

        $this->actingAsApi($this->member());

        $this->get("/api/v1/attachments/{$attachment->id}")
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    /**
     * The privacy of a note has to carry to its files, or a private note's
     * attachment becomes a back door into it.
     */
    public function test_an_attachment_on_a_private_note_is_closed_to_everyone_else(): void
    {
        $attachment = $this->attach($this->note('private'));

        $this->actingAsApi($this->member());

        $this->get("/api/v1/attachments/{$attachment->id}")->assertForbidden();
        $this->delete("/api/v1/attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_attachments_do_not_leak_across_workspaces(): void
    {
        $attachment = $this->attach($this->note());

        $outsider = User::factory()->create();
        app(WorkspaceService::class)->create($outsider, 'Other Co');

        $this->actingAsApi($outsider->refresh());

        $this->get("/api/v1/attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_removing_an_attachment_deletes_the_file_too(): void
    {
        $note = $this->note();
        $this->post("/api/v1/notes/{$note->id}/attachments", ['file' => $this->pdf()])->assertCreated();
        $attachment = Attachment::firstOrFail();

        $this->delete("/api/v1/attachments/{$attachment->id}")->assertOk();

        $this->assertSame(0, Attachment::count());
        // Storage must not keep bytes nothing points at any more.
        Storage::disk('local')->assertMissing($attachment->path);
    }

    /**
     * PHP discards an oversized upload before Laravel sees it, so the limit the
     * UI quotes has to be the smaller of the app's rule and php.ini's.
     */
    public function test_the_quoted_size_limit_never_promises_more_than_php_allows(): void
    {
        $effective = Attachment::effectiveMaxKilobytes();

        $this->assertGreaterThan(0, $effective);
        $this->assertLessThanOrEqual(Attachment::MAX_KILOBYTES, $effective);
        $this->assertSame(
            min(Attachment::MAX_KILOBYTES, Attachment::phpMaxKilobytes()),
            $effective,
        );
    }

    public function test_a_note_carries_its_attachments_in_the_api(): void
    {
        $note = $this->note();
        $this->post("/api/v1/notes/{$note->id}/attachments", ['file' => $this->pdf()])->assertCreated();

        $this->getJson("/api/v1/notes/{$note->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.original_name', 'report.pdf');
    }
}
