<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'workspace_name' => $this->whenLoaded('tenant', fn () => $this->tenant->name),
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'is_open' => $this->isOpen(),
            'expires_at' => $this->expires_at,
            'responded_at' => $this->responded_at,
            'invited_by' => $this->invited_by,
            'inviter' => new UserResource($this->whenLoaded('inviter')),
            'created_at' => $this->created_at,
        ];
    }
}
