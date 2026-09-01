<?php

namespace App\Policies;

use App\Models\Calendar;
use App\Models\User;

class CalendarPolicy
{
    public function view(User $user, Calendar $calendar): bool
    {
        return $calendar->isReadableBy($user);
    }

    public function update(User $user, Calendar $calendar): bool
    {
        return $calendar->isManageableBy($user);
    }

    public function delete(User $user, Calendar $calendar): bool
    {
        // The workspace must keep at least one calendar per owner.
        return $calendar->owner_id === $user->id && ! $calendar->is_default;
    }

    /** Adding events to the calendar. */
    public function addEvents(User $user, Calendar $calendar): bool
    {
        return $calendar->isWritableBy($user);
    }

    public function share(User $user, Calendar $calendar): bool
    {
        return $calendar->isManageableBy($user);
    }
}
