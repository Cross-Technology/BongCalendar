<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'avatar_url' => $this->avatar_url,
            'current_tenant_id' => $this->current_tenant_id,
            'digest_enabled' => (bool) $this->digest_enabled,
            'digest_morning_hour' => (int) $this->digest_morning_hour,
            'digest_evening_hour' => (int) $this->digest_evening_hour,
            'role' => $this->whenPivotLoaded('tenant_user', fn () => $this->pivot->role),
            'joined_at' => $this->whenPivotLoaded('tenant_user', fn () => $this->pivot->joined_at),
            'tenants' => TenantResource::collection($this->whenLoaded('tenants')),
            'created_at' => $this->created_at,
        ];
    }
}
