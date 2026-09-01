<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarResource extends JsonResource
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
            'owner_id' => $this->owner_id,
            'department_id' => $this->department_id,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'timezone' => $this->timezone,
            'visibility' => $this->visibility,
            'is_default' => $this->is_default,
            'is_owner' => $user && $this->owner_id === $user->id,
            'permission' => $user ? $this->permissionFor($user) : null,
            'events_count' => $this->whenCounted('events'),
            'owner' => new UserResource($this->whenLoaded('owner')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'shares' => CalendarShareResource::collection($this->whenLoaded('shares')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
