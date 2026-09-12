<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\User;
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

    public function store(UploadedFile $file, Model $attachable, User $user, int $tenantId): Attachment
    {
        // The stored name is generated. The uploader's filename is kept for
        // display only, so "../../.env" or a .php extension buys nothing.
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $directory = sprintf('attachments/%d/%s', $tenantId, now()->format('Y/m'));

        $path = $file->storeAs($directory, Str::ulid().'.'.$extension, self::DISK);

        return $attachable->attachments()->create([
            'tenant_id' => $tenantId,
            'uploaded_by' => $user->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $this->displayName($file->getClientOriginalName()),
            // Guessed from the contents, not from the header the client sent.
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
        ]);
    }

    /** Removes the row and the bytes together, so storage does not leak. */
    public function remove(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);

        $attachment->delete();
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
