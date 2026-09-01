<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        if ($event->created_by === $user->id) {
            return true;
        }

        if ($event->invitations()->where('user_id', $user->id)->exists()) {
            return true;
        }

        return $event->calendar?->isReadableBy($user) ?? false;
    }

    public function update(User $user, Event $event): bool
    {
        return $event->created_by === $user->id
            || ($event->calendar?->isWritableBy($user) ?? false);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }

    /** Accepting or declining an invitation to this event. */
    public function respond(User $user, Event $event): bool
    {
        return $event->invitations()->where('user_id', $user->id)->exists();
    }
}
