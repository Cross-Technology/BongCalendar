<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'calendar_id' => $this->calendar_id,
            'created_by' => $this->created_by,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'color' => $this->displayColor(),
            // UTC on the wire; `timezone` is the zone the event was authored in.
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'all_day' => $this->all_day,
            'status' => $this->status,
            'recurrence_rule' => $this->recurrence_rule,
            'recurrence_until' => $this->recurrence_until?->toIso8601String(),
            'calendar' => new CalendarResource($this->whenLoaded('calendar')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'invitations' => EventInvitationResource::collection($this->whenLoaded('invitations')),
            'reminders' => $this->whenLoaded('reminders', fn () => $this->reminders->map(fn ($r) => [
                'id' => $r->id,
                'minutes_before' => $r->minutes_before,
                'channel' => $r->channel,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
