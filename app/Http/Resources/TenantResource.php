<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            // Only ever serialised to members of the workspace, who are the
            // people entitled to pass it on.
            'invite_code' => $this->invite_code,
            'timezone' => $this->timezone,
            'owner_id' => $this->owner_id,
            'role' => $this->whenPivotLoaded('tenant_user', fn () => $this->pivot->role),
            'members_count' => $this->whenCounted('users'),
            'calendars_count' => $this->whenCounted('calendars'),
            'members' => UserResource::collection($this->whenLoaded('users')),
            'created_at' => $this->created_at,
        ];
    }
}
