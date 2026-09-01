<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One device that has agreed to receive push notifications. */
#[Fillable(['user_id', 'token', 'platform', 'device_name', 'last_active_at'])]
class PushToken extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
