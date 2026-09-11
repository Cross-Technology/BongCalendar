<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NoteController extends Controller
{
    public function __construct(protected TenantContext $tenants) {}

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
            ->with('author')
            ->boardOrder()
            ->paginate($request->integer('per_page') ?: 25)
            ->withQueryString();

        return NoteResource::collection($notes);
    }

    public function store(NoteRequest $request): JsonResponse
    {
        $tenant = $this->tenants->require();

        $this->authorize('create', [Note::class, $tenant->id]);

        $note = Note::create([
            'tenant_id' => $tenant->id,
            'author_id' => $request->user()->id,
            'title' => $request->input('title'),
            'body' => $request->string('body'),
            'color' => $request->input('color', Note::COLORS[0]),
            'visibility' => $request->input('visibility', 'tenant'),
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        return response()->json(['data' => new NoteResource($note->load('author'))], 201);
    }

    public function show(Note $note): JsonResponse
    {
        $this->authorize('view', $note);

        return response()->json(['data' => new NoteResource($note->load('author'))]);
    }

    public function update(NoteRequest $request, Note $note): JsonResponse
    {
        $this->authorize('update', $note);

        // Only the author decides whether their note stays private — an admin
        // may tidy a shared note but must not publish someone else's.
        $data = $note->author_id === $request->user()->id
            ? $request->validated()
            : collect($request->validated())->except('visibility')->all();

        $note->update($data);

        return response()->json(['data' => new NoteResource($note->load('author'))]);
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

        return response()->json(['data' => new NoteResource($note->load('author'))]);
    }
}
