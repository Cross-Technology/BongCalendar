<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * An attachment is only ever as private as the thing it hangs off, so both
 * questions are delegated: whoever may read the note may read its files, and
 * whoever may edit the note may remove them — as may the person who uploaded
 * one, even if they later lose edit rights.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        $parent = $attachment->attachable;

        /*
         * An image uploaded into a note that has not been saved yet has no
         * parent to inherit privacy from, so it falls back to the strictest
         * reading: only the person composing it. The editor needs to render
         * what they just dropped in, and nobody else has any business seeing a
         * note that does not exist yet.
         */
        if ($parent === null) {
            return $attachment->isOrphan() && $attachment->uploaded_by === $user->id;
        }

        return $user->can('view', $parent);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        $parent = $attachment->attachable;

        if ($parent === null) {
            return $attachment->isOrphan() && $attachment->uploaded_by === $user->id;
        }

        return $attachment->uploaded_by === $user->id || $user->can('update', $parent);
    }
}
