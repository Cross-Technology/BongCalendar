<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Turns "every weekday until the end of the month" into a list of dates.
 *
 * Deliberately free of the database: given a start, a rule and a timezone it
 * returns the due dates, so the rules can be read and tested on their own. The
 * time of day is carried from the start date — a task repeating daily at 9am
 * stays at 9am, including across a daylight-saving change, because each date
 * is built in the user's zone rather than by adding 24 hours.
 */
class TaskRecurrenceService
{
    public const FREQUENCIES = ['daily', 'weekdays', 'weekly', 'monthly'];

    /**
     * Hard ceiling on one series. A year of daily tasks is already a lot to
     * tick off; beyond that a mistyped end date becomes thousands of rows.
     */
    public const MAX_OCCURRENCES = 366;

    /**
     * @param  array{frequency: string, interval?: int|null, days_of_week?: array<int>|null, until?: string|null, count?: int|null}  $rule
     * @return array<int, CarbonImmutable>  Due dates, in order, including the first.
     */
    public function dates(CarbonImmutable $start, array $rule, string $timezone): array
    {
        $frequency = $rule['frequency'];

        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException("Unknown repeat frequency [{$frequency}].");
        }

        $interval = max(1, (int) ($rule['interval'] ?? 1));
        $start = $start->setTimezone($timezone);

        $until = isset($rule['until'])
            ? CarbonImmutable::parse($rule['until'], $timezone)->endOfDay()
            : null;

        // A count of occurrences and an end date are alternatives; when both
        // are given, whichever runs out first wins.
        $limit = isset($rule['count'])
            ? min(max(1, (int) $rule['count']), self::MAX_OCCURRENCES)
            : self::MAX_OCCURRENCES;

        if (! $until && ! isset($rule['count'])) {
            throw new InvalidArgumentException('A repeat needs an end date or a number of occurrences.');
        }

        $weekdays = $this->weekdaysFor($frequency, $rule, $start);

        $dates = [];
        $cursor = $start;
        // Guards against a rule that can never match — "every Monday" with no
        // Monday before the end date would otherwise spin to the ceiling.
        $inspected = 0;
        $maxInspections = self::MAX_OCCURRENCES * 8;

        while (count($dates) < $limit && $inspected < $maxInspections) {
            $inspected++;

            if ($until && $cursor->greaterThan($until)) {
                break;
            }

            if ($this->matches($cursor, $start, $frequency, $interval, $weekdays)) {
                $dates[] = $cursor;
            }

            $cursor = $frequency === 'monthly'
                ? $this->nextMonth($start, count($dates), $interval)
                : $cursor->addDay();

            // Monthly walks month to month, so it can pass the end in one step.
            if ($frequency === 'monthly' && $until && $cursor->greaterThan($until)) {
                break;
            }
        }

        return $dates;
    }

    /** How many tasks a rule would create, without building them. */
    public function count(CarbonImmutable $start, array $rule, string $timezone): int
    {
        return count($this->dates($start, $rule, $timezone));
    }

    /**
     * @param  array<int>  $weekdays  ISO days (1 = Monday) the rule allows.
     */
    protected function matches(
        CarbonImmutable $date,
        CarbonImmutable $start,
        string $frequency,
        int $interval,
        array $weekdays,
    ): bool {
        return match ($frequency) {
            // Whole days only: the start carries a time, so an unrounded diff
            // is fractional and never lands on the interval.
            'daily' => (int) $start->startOfDay()->diffInDays($date->startOfDay()) % $interval === 0,
            'weekdays' => ! $date->isWeekend(),
            'weekly' => in_array($date->dayOfWeekIso, $weekdays, true)
                && intdiv((int) $start->startOfWeek()->diffInDays($date->startOfWeek()), 7) % $interval === 0,
            // Monthly is walked directly, so every candidate is a match.
            'monthly' => true,
            default => false,
        };
    }

    /**
     * Same day-of-month each time, clamped for short months: the 31st becomes
     * the 30th in April and the 28th in February rather than spilling into the
     * next month, which is what a person means by "monthly on the 31st".
     */
    protected function nextMonth(CarbonImmutable $start, int $taken, int $interval): CarbonImmutable
    {
        // Walk from the first of the month: adding months to the 31st
        // overflows into the month after next (Jan 31 + 1 month = Mar 3),
        // which silently skips February.
        $target = $start->startOfMonth()->addMonthsNoOverflow($taken * $interval);
        $day = min($start->day, $target->daysInMonth);

        return $target->setDay($day)->setTime($start->hour, $start->minute, $start->second);
    }

    /** @return array<int> */
    protected function weekdaysFor(string $frequency, array $rule, CarbonImmutable $start): array
    {
        if ($frequency !== 'weekly') {
            return [];
        }

        $days = array_values(array_filter(
            array_map('intval', $rule['days_of_week'] ?? []),
            fn (int $day) => $day >= CarbonInterface::MONDAY && $day <= 7,
        ));

        // No days chosen means "the same day of the week I started on".
        return $days ?: [$start->dayOfWeekIso];
    }
}
