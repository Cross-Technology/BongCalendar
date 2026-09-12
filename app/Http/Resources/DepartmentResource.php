<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'icon' => $this->icon,
            // Sanitised HTML; fields such as {{date}} are filled at render time.
            'report_header' => $this->report_header,
            'position' => $this->position,
            // tenant_id can be absent when the model came from a partial select.
            'can_manage' => $user && $this->tenant_id ? $user->isTenantAdmin($this->tenant_id) : false,
            'members' => UserResource::collection($this->whenLoaded('members')),
            'members_count' => $this->whenCounted('members'),
            'calendars_count' => $this->whenCounted('calendars'),
            'events_count' => $this->whenCounted('events'),
            'calendars' => CalendarResource::collection($this->whenLoaded('calendars')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
