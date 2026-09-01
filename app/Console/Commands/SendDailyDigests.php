<?php

namespace App\Console\Commands;

use App\Models\DigestDelivery;
use App\Models\PushToken;
use App\Models\User;
use App\Services\DailyDigestService;
use App\Services\ExpoPushService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sends the morning nudge and the evening summary.
 *
 * Runs every hour rather than twice a day, because "8am" means 8am where the
 * user is: the command asks each account what time it is for them and sends to
 * the ones whose hour has just arrived. A delivery row per user, kind and day
 * keeps an hourly schedule from sending the same digest twice.
 */
class SendDailyDigests extends Command
{
    protected $signature = 'push:digests
                            {--kind= : Force morning or evening, ignoring the clock}
                            {--user= : Send to one user id only}
                            {--force : Send even if today\'s digest already went out}
                            {--dry : Print what would be sent without sending}';

    protected $description = 'Push the morning task digest and evening summary to their devices';

    public function handle(DailyDigestService $digests, ExpoPushService $push): int
    {
        $forced = $this->option('kind');

        if ($forced && ! in_array($forced, ['morning', 'evening'], true)) {
            $this->error('--kind must be morning or evening.');

            return self::FAILURE;
        }

        $users = User::query()
            ->where('digest_enabled', true)
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            // No devices, nothing to push to.
            ->whereHas('pushTokens')
            ->with('pushTokens')
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $tz = $user->timezone ?: 'UTC';
            $localNow = CarbonImmutable::now($tz);

            $kind = $forced ?: $this->dueKind($user, $localNow);

            if (! $kind) {
                continue;
            }

            $today = $localNow->toDateString();

            if (! $this->option('force') && DigestDelivery::alreadySent($user->id, $kind, $today)) {
                continue;
            }

            $message = $kind === 'morning'
                ? $digests->morning($user, $localNow)
                : $digests->evening($user, $localNow);

            if (! $message) {
                // Nothing worth saying. Recorded anyway so the hourly run does
                // not recompute it for the rest of the hour.
                if (! $this->option('dry')) {
                    DigestDelivery::record($user->id, $kind, $today, 0);
                }

                continue;
            }

            if ($this->option('dry')) {
                $this->line("[{$kind}] {$user->email}: {$message['title']} — {$message['body']}");

                continue;
            }

            $delivered = $push->send(
                $user->pushTokens,
                $message['title'],
                $message['body'],
                $message['data'],
            );

            DigestDelivery::record($user->id, $kind, $today, $delivered);

            $sent += $delivered;
        }

        $this->info("Digests sent to {$sent} devices.");

        return self::SUCCESS;
    }

    /** Which digest, if any, is due for this user right now. */
    protected function dueKind(User $user, CarbonImmutable $localNow): ?string
    {
        return match ($localNow->hour) {
            (int) $user->digest_morning_hour => 'morning',
            (int) $user->digest_evening_hour => 'evening',
            default => null,
        };
    }
}
