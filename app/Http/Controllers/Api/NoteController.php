<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use App\Services\AttachmentService;
use App\Services\RichTextService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NoteController extends Controller
{
    public function __construct(
        protected TenantContext $tenants,
        protected RichTextService $richText,
        protected AttachmentService $attachments,
    ) {}

    /**
     * The workspace noticeboard: shared notes plus the caller's own private
     * ones. `?q=` searches, `?mine=1` narrows to the caller, `?pinned=1` to
     * pinned notes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenants->require()->id;
        $user = $request->user();

        $notes = Note::visibleTo($user, $tenantId)
            ->search($request->query('q'))
            ->when($request->boolean('mine'), fn ($q) => $q->where('author_id', $user->id))
            ->when($request->boolean('pinned'), fn ($q) => $q->where('is_pinned', true))
            ->with(['author', 'files'])
            ->boardOrder()
            ->paginate($request->integer('per_page') ?: 25)
            ->withQueryString();

        return NoteResource::collection($notes);
    }

    public function store(NoteRequest $request): JsonResponse
    {
        $tenant = $this->tenants->require();

        $this->authorize('create', [Note::class, $tenant->id]);

        if ($this->richText->isBlank($request->string('body'), images: true)) {
            return $this->blankBody();
        }

        // `images: true` — a note is the one body that may carry a picture.
        $body = $this->richText->sanitize($request->string('body'), images: true);

        $note = Note::create([
            'tenant_id' => $tenant->id,
            'author_id' => $request->user()->id,
            'title' => $request->input('title'),
            'body' => $body,
            'body_text' => $this->richText->toText($body),
            'color' => $request->input('color', Note::COLORS[0]),
            'visibility' => $request->input('visibility', 'tenant'),
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        $this->syncImages($note, $body, $request);

        return response()->json(['data' => new NoteResource($note->load(['author', 'files']))], 201);
    }

    public function show(Note $note): JsonResponse
    {
        $this->authorize('view', $note);

        return response()->json(['data' => new NoteResource($note->load(['author', 'files.uploader']))]);
    }

    public function update(NoteRequest $request, Note $note): JsonResponse
    {
        $this->authorize('update', $note);

        // Only the author decides whether their note stays private — an admin
        // may tidy a shared note but must not publish someone else's.
        $data = $note->author_id === $request->user()->id
            ? $request->validated()
            : collect($request->validated())->except('visibility')->all();

        if (array_key_exists('body', $data)) {
            if ($this->richText->isBlank($data['body'], images: true)) {
                return $this->blankBody();
            }

            $data['body'] = $this->richText->sanitize($data['body'], images: true);
            $data['body_text'] = $this->richText->toText($data['body']);
        }

        $note->update($data);

        if (array_key_exists('body', $data)) {
            $this->syncImages($note, $data['body'], $request);
        }

        return response()->json(['data' => new NoteResource($note->load(['author', 'files']))]);
    }

    public function destroy(Note $note): JsonResponse
    {
        $this->authorize('delete', $note);

        $note->delete();

        return response()->json(['message' => 'Note deleted.']);
    }

    /** Pin or unpin — the one field a client flips on its own. */
    public function pin(Request $request, Note $note): JsonResponse
    {
        $this->authorize('update', $note);

        $note->update([
            'is_pinned' => $request->has('is_pinned') ? $request->boolean('is_pinned') : ! $note->is_pinned,
        ]);

        return response()->json(['data' => new NoteResource($note->load(['author', 'files']))]);
    }

    /** An editor left untouched still posts markup, but it is not a note. */
    /**
     * Ties the note to the pictures its body draws.
     *
     * A client uploads an image before the note exists (POST
     * attachments/inline), so the row starts unparented; this is where it
     * finds its note, and where one deleted out of the body is thrown away.
     */
    protected function syncImages(Note $note, string $body, Request $request): void
    {
        $this->attachments->syncEmbedded(
            $note,
            $this->richText->embeddedAttachmentIds($body),
            $request->user(),
            $note->tenant_id,
        );
    }

    protected function blankBody(): JsonResponse
    {
        return response()->json([
            'message' => 'The note is empty.',
            'errors' => ['body' => ['Write something before saving the note.']],
        ], 422);
    }
}
