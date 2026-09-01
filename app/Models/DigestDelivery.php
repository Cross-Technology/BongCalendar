<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One digest, sent once, to one person, on one day. */
#[Fillable(['user_id', 'kind', 'sent_for', 'devices'])]
class DigestDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'sent_for' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function alreadySent(int $userId, string $kind, string $date): bool
    {
        return static::where('user_id', $userId)
            ->where('kind', $kind)
            ->whereDate('sent_for', $date)
            ->exists();
    }

    public static function record(int $userId, string $kind, string $date, int $devices): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'kind' => $kind, 'sent_for' => $date],
            ['devices' => $devices],
        );
    }
}
