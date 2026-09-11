<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;

/**
 * Any member of a workspace can take a note in it. A shared note is readable
 * by the whole workspace and tidied by its author or an admin; a private note
 * stays with its author, and admin rank does not open it — that is the promise
 * `private` makes.
 */
class NotePolicy
{
    public function view(User $user, Note $note): bool
    {
        if (! $user->belongsToTenant($note->tenant_id)) {
            return false;
        }

        return $note->author_id === $user->id || ! $note->isPrivate();
    }

    public function create(User $user, int $tenantId): bool
    {
        return $user->belongsToTenant($tenantId);
    }

    /** Editing someone else's wording is an admin's job, and only when shared. */
    public function update(User $user, Note $note): bool
    {
        return $note->author_id === $user->id
            || (! $note->isPrivate() && $user->isTenantAdmin($note->tenant_id));
    }

    public function delete(User $user, Note $note): bool
    {
        return $this->update($user, $note);
    }
}
