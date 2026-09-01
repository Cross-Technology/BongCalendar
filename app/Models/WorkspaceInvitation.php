<?php

namespace App\Models;

use App\Services\WorkspaceService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join a workspace, addressed to one email and waiting on
 * that person's answer. Membership is only granted when they accept, so an
 * invite never adds anyone to a workspace behind their back.
 */
#[Fillable(['tenant_id', 'invited_by', 'email', 'role', 'status', 'expires_at'])]
class WorkspaceInvitation extends Model
{
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'role' => 'member',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** Invitations waiting on an answer, and not yet timed out. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', strtolower(trim($email)));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    /** Grants membership and closes the invitation. */
    public function accept(User $user, WorkspaceService $workspaces): void
    {
        $workspaces->addMember($this->tenant, $user, $this->role);

        $this->forceFill([
            'status' => 'accepted',
            'accepted_by' => $user->id,
            'responded_at' => now(),
        ])->save();
    }

    public function decline(): void
    {
        $this->forceFill([
            'status' => 'declined',
            'responded_at' => now(),
        ])->save();
    }
}
