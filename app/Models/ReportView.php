<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's reading of one report — a read receipt.
 *
 * A day's report is the department's record for the whole workspace, so the
 * useful question is not who wrote it but who actually read it. Written by
 * ReportService::markSeen(), which is the only thing that should touch it.
 */
#[Fillable(['report_id', 'user_id', 'first_seen_at', 'last_seen_at', 'views'])]
class ReportView extends Model
{
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'views' => 'integer',
        ];
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
