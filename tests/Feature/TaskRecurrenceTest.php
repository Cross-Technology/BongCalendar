<?php

namespace Tests\Feature;

use App\Services\TaskRecurrenceService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class TaskRecurrenceTest extends TestCase
{
    protected TaskRecurrenceService $recurrence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recurrence = new TaskRecurrenceService;
    }

    /** @return array<int, string> */
    protected function dates(string $start, array $rule, string $tz = 'Asia/Phnom_Penh'): array
    {
        return array_map(
            fn (CarbonImmutable $date) => $date->format('Y-m-d H:i'),
            $this->recurrence->dates(CarbonImmutable::parse($start, $tz), $rule, $tz),
        );
    }

    public function test_every_day_for_the_rest_of_the_month(): void
    {
        $dates = $this->dates('2026-09-01 09:00', [
            'frequency' => 'daily',
            'until' => '2026-09-30',
        ]);

        $this->assertCount(30, $dates);
        $this->assertSame('2026-09-01 09:00', $dates[0]);
        $this->assertSame('2026-09-30 09:00', end($dates));
    }

    public function test_the_time_of_day_is_kept_on_every_occurrence(): void
    {
        $dates = $this->dates('2026-09-01 14:30', ['frequency' => 'daily', 'count' => 3]);

        $this->assertSame(
            ['2026-09-01 14:30', '2026-09-02 14:30', '2026-09-03 14:30'],
            $dates,
        );
    }

    public function test_every_other_day(): void
    {
        $dates = $this->dates('2026-09-01 09:00', [
            'frequency' => 'daily',
            'interval' => 2,
            'count' => 4,
        ]);

        $this->assertSame(
            ['2026-09-01 09:00', '2026-09-03 09:00', '2026-09-05 09:00', '2026-09-07 09:00'],
            $dates,
        );
    }

    public function test_weekdays_skip_the_weekend(): void
    {
        // 2026-09-04 is a Friday.
        $dates = $this->dates('2026-09-04 09:00', [
            'frequency' => 'weekdays',
            'until' => '2026-09-09',
        ]);

        $this->assertSame(
            ['2026-09-04 09:00', '2026-09-07 09:00', '2026-09-08 09:00', '2026-09-09 09:00'],
            $dates,
            'Saturday and Sunday are left out'
        );
    }

    public function test_weekly_on_chosen_days(): void
    {
        // Monday and Wednesday for two weeks, starting Tuesday 2026-09-01.
        $dates = $this->dates('2026-09-01 09:00', [
            'frequency' => 'weekly',
            'days_of_week' => [1, 3],
            'until' => '2026-09-14',
        ]);

        $this->assertSame(
            ['2026-09-02 09:00', '2026-09-07 09:00', '2026-09-09 09:00', '2026-09-14 09:00'],
            $dates,
        );
    }

    public function test_weekly_defaults_to_the_day_it_started_on(): void
    {
        $dates = $this->dates('2026-09-01 09:00', ['frequency' => 'weekly', 'count' => 3]);

        // 2026-09-01 is a Tuesday.
        $this->assertSame(
            ['2026-09-01 09:00', '2026-09-08 09:00', '2026-09-15 09:00'],
            $dates,
        );
    }

    public function test_monthly_clamps_to_the_last_day_of_short_months(): void
    {
        $dates = $this->dates('2026-01-31 09:00', ['frequency' => 'monthly', 'count' => 4]);

        $this->assertSame(
            ['2026-01-31 09:00', '2026-02-28 09:00', '2026-03-31 09:00', '2026-04-30 09:00'],
            $dates,
            'the 31st becomes the last day of a shorter month, never the 1st of the next'
        );
    }

    public function test_a_count_and_an_end_date_both_apply(): void
    {
        $dates = $this->dates('2026-09-01 09:00', [
            'frequency' => 'daily',
            'count' => 20,
            'until' => '2026-09-05',
        ]);

        $this->assertCount(5, $dates, 'whichever runs out first wins');
    }

    public function test_a_series_is_capped(): void
    {
        $dates = $this->dates('2026-01-01 09:00', [
            'frequency' => 'daily',
            'until' => '2036-01-01',
        ]);

        $this->assertCount(TaskRecurrenceService::MAX_OCCURRENCES, $dates);
    }

    public function test_a_rule_with_no_end_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dates('2026-09-01 09:00', ['frequency' => 'daily']);
    }

    public function test_an_unknown_frequency_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dates('2026-09-01 09:00', ['frequency' => 'fortnightly', 'count' => 3]);
    }

    public function test_the_clock_survives_a_daylight_saving_change(): void
    {
        // London goes back an hour on 2026-10-25.
        $dates = $this->dates('2026-10-24 09:00', [
            'frequency' => 'daily',
            'count' => 3,
        ], 'Europe/London');

        $this->assertSame(
            ['2026-10-24 09:00', '2026-10-25 09:00', '2026-10-26 09:00'],
            $dates,
            'still 9am locally on both sides of the change'
        );
    }
}
