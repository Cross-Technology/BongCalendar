<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /**
     * The private disk. Attachments inherit the privacy of whatever they hang
     * off, and a note can be private — so the bytes must never sit under a
     * guessable public URL. Every read goes through the policy instead.
     */
    public const DISK = 'local';

    /**
     * @param  Model|null  $attachable  Null only for an image uploaded into a
     *                                  note that has not been saved yet;
     *                                  syncEmbedded() hands it its parent.
     */
    public function store(UploadedFile $file, ?Model $attachable, User $user, int $tenantId, bool $embedded = false): Attachment
    {
        // The stored name is generated. The uploader's filename is kept for
        // display only, so "../../.env" or a .php extension buys nothing.
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $directory = sprintf('attachments/%d/%s', $tenantId, now()->format('Y/m'));

        $path = $file->storeAs($directory, Str::ulid().'.'.$extension, self::DISK);

        return Attachment::create([
            'tenant_id' => $tenantId,
            'attachable_type' => $attachable?->getMorphClass(),
            'attachable_id' => $attachable?->getKey(),
            'is_embedded' => $embedded,
            'uploaded_by' => $user->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $this->displayName($file->getClientOriginalName()),
            // Guessed from the contents, not from the header the client sent.
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
        ]);
    }

    /**
     * Reconciles the images inside a body with the rows that back them.
     *
     * Called once the note is saved, when there is finally an id to hang
     * things off. Two things happen, and both are driven by the body rather
     * than by what the editor claims it did — the markup is the record of what
     * the note actually shows:
     *
     *  - images uploaded while the note was being written are adopted, so they
     *    stop being orphans and inherit the note's privacy;
     *  - images the writer deleted out of the body are removed, bytes and all,
     *    rather than sitting on the disk unreachable and unreferenced.
     *
     * Adoption is scoped to the uploader's own orphans, so quoting someone
     * else's attachment id in a hand-written body cannot pull that file onto a
     * note they control. An image uploaded and then deleted again before the
     * note was ever saved is left alone here — it belongs to no note, so it
     * cannot be told apart from one being written in another tab; pruneOrphans()
     * collects those once they are old enough to be safely abandoned.
     *
     * @param  array<int, int>  $keepIds  Attachment ids the saved body references.
     */
    public function syncEmbedded(Model $attachable, array $keepIds, User $user, int $tenantId): void
    {
        if ($keepIds !== []) {
            Attachment::query()
                ->whereNull('attachable_id')
                ->where('is_embedded', true)
                ->where('uploaded_by', $user->id)
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $keepIds)
                ->update([
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
                ]);
        }

        $dropped = Attachment::query()
            ->where('is_embedded', true)
            ->where('attachable_type', $attachable->getMorphClass())
            ->where('attachable_id', $attachable->getKey())
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
            ->get();

        $dropped->each(fn (Attachment $attachment) => $this->remove($attachment));
    }

    /** Removes the row and the bytes together, so storage does not leak. */
    public function remove(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);

        $attachment->delete();
    }

    /**
     * Orphans from a composer that was closed without saving.
     *
     * An image is stored the moment it is dropped into the editor, because it
     * needs a URL to render — so abandoning the note leaves bytes on the disk
     * that no note will ever adopt. Nothing can read them, but nothing frees
     * them either, which is why this is swept on a schedule rather than left.
     *
     * The grace period is generous on purpose: a half-written note can sit
     * open in a tab overnight, and sweeping its images would empty the editor
     * in front of whoever is still writing it.
     */
    public function pruneOrphans(CarbonInterface $before): int
    {
        $orphans = Attachment::query()
            ->whereNull('attachable_id')
            ->where('created_at', '<', $before)
            ->get();

        $orphans->each(fn (Attachment $attachment) => $this->remove($attachment));

        return $orphans->count();
    }

    /**
     * A filename fit to show and to put in a Content-Disposition header:
     * no directory separators, no control characters, no runaway length.
     */
    protected function displayName(?string $name): string
    {
        $name = (string) $name;
        $name = str_replace(['/', '\\', "\0"], '-', $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name) ?: 'file';

        return Str::limit($name, 120, '');
    }
}
