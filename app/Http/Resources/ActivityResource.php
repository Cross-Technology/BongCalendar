<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a task's or report's audit trail.
 *
 * `changes` is sent as it was recorded — labels and values already resolved —
 * so a client renders the trail without having to know what a status code or a
 * department id meant at the time.
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->actionLabel(),
            'user_id' => $this->user_id,
            // "System" when nothing was signed in — a console command, the
            // recurrence job or the seeder.
            'actor_name' => $this->actorName(),
            'user' => new UserResource($this->whenLoaded('user')),
            'changes' => $this->changeLines(),
            'created_at' => $this->created_at,
        ];
    }
}
