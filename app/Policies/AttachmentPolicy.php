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

        return $parent !== null && $user->can('view', $parent);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        $parent = $attachment->attachable;

        if ($parent === null) {
            return false;
        }

        return $attachment->uploaded_by === $user->id || $user->can('update', $parent);
    }
}
