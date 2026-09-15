<?php

namespace App\Http\Controllers;

use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Note;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves and manages attachments for both front doors — the dashboard reaches
 * these through the session guard, the API through JWT.
 */
class AttachmentController extends Controller
{
    public function __construct(protected AttachmentService $attachments) {}

    /**
     * Streams the file. Files live on a private disk, so this is the only way
     * to read one and every read is checked against the parent's policy.
     */
    public function download(Attachment $attachment): Response
    {
        $this->authorize('view', $attachment);

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        // Images and PDFs are shown in place; anything else is pushed to disk
        // rather than rendered. `nosniff` stops the browser second-guessing the
        // type we declare and running a file as something else.
        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => $attachment->isInline()
                    ? ResponseHeaderBag::DISPOSITION_INLINE
                    : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            ]
        );
    }

    public function storeForNote(Request $request, Note $note): JsonResponse
    {
        $this->authorize('update', $note);

        $request->validate(['file' => array_merge(['required'], Attachment::uploadRules())]);

        $attachment = $this->attachments->store(
            $request->file('file'),
            $note,
            $request->user(),
            $note->tenant_id,
        );

        return response()->json(['data' => new AttachmentResource($attachment)], 201);
    }

    /**
     * Takes an image the writer just dropped into a note body and hands back
     * the URL to draw it at.
     *
     * Deliberately not a Livewire upload: the editor sits behind wire:ignore
     * and needs the URL in the same gesture, so this answers a plain fetch
     * with the one thing the editor needs.
     *
     * `note` is absent while a new note is still being composed. The file is
     * stored unparented and is readable by nobody but its uploader until the
     * note is saved and adopts it (AttachmentService::syncEmbedded), so a
     * picture cannot be read by the workspace before the note carrying it
     * exists.
     */
    public function storeInline(Request $request): JsonResponse
    {
        $request->validate([
            'file' => array_merge(['required'], Attachment::imageRules()),
            'note_id' => ['nullable', 'integer'],
        ], attributes: ['file' => 'image']);

        $note = $request->filled('note_id')
            ? Note::findOrFail($request->integer('note_id'))
            : null;

        if ($note) {
            $this->authorize('update', $note);
            $tenantId = $note->tenant_id;
        } else {
            $tenantId = (int) $request->user()->current_tenant_id;

            abort_if($tenantId === 0, 409, 'No workspace selected.');

            $this->authorize('create', [Note::class, $tenantId]);
        }

        $attachment = $this->attachments->store(
            $request->file('file'),
            $note,
            $request->user(),
            $tenantId,
            embedded: true,
        );

        return response()->json([
            'id' => $attachment->id,
            // Root-relative, because the sanitiser rejects an embedded URL
            // that carries a host. See config/purifier.php.
            'url' => $attachment->inlineSrc(),
            'name' => $attachment->original_name,
        ], 201);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $this->authorize('delete', $attachment);

        $this->attachments->remove($attachment);

        return response()->json(['message' => 'Attachment removed.']);
    }
}
