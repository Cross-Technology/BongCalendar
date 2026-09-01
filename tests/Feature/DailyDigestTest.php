<?php

namespace Tests\Feature;

use App\Models\DigestDelivery;
use App\Models\PushToken;
use App\Models\Task;
use App\Models\User;
use App\Services\DailyDigestService;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Rady Hak',
            'timezone' => 'Asia/Phnom_Penh',
        ]);
        app(WorkspaceService::class)->create($this->user, 'Acme', 'Asia/Phnom_Penh');
        $this->user->refresh();

        PushToken::create([
            'user_id' => $this->user->id,
            'token' => 'ExponentPushToken[test-device]',
            'platform' => 'ios',
        ]);
    }

    protected function task(array $attributes = []): Task
    {
        return Task::factory()->create($attributes + [
            'tenant_id' => $this->user->current_tenant_id,
            'created_by' => $this->user->id,
            'assignee_id' => $this->user->id,
        ]);
    }

    protected function localNow(int $hour = 8): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Phnom_Penh')->setTime($hour, 0);
    }

    /* --------------------------------------------------------------- content */

    public function test_the_morning_digest_counts_urgent_and_normal_work(): void
    {
        $today = $this->localNow()->setTime(14, 0);

        $this->task(['due_date' => $today->utc(), 'priority' => 'urgent']);
        $this->task(['due_date' => $today->utc(), 'priority' => 'high']);
        $this->task(['due_date' => $today->utc(), 'priority' => 'medium']);

        $digest = app(DailyDigestService::class)->morning($this->user, $this->localNow());

        $this->assertNotNull($digest);
        $this->assertSame('Good morning, Rady', $digest['title']);
        $this->assertStringContainsString('3 tasks due today', $digest['body']);
        $this->assertStringContainsString('2 urgent', $digest['body']);
        $this->assertStringContainsString('1 normal', $digest['body']);
        $this->assertSame(2, $digest['data']['urgent']);
    }

    public function test_the_morning_digest_mentions_checklist_items_and_overdue_work(): void
    {
        $today = $this->localNow()->setTime(14, 0);

        $task = $this->task(['due_date' => $today->utc(), 'priority' => 'medium']);
        $task->checklist()->create(['title' => 'Count crates', 'position' => 0]);
        $task->checklist()->create(['title' => 'Sign invoice', 'position' => 1]);
        $task->checklist()->create(['title' => 'Done already', 'completed' => true, 'position' => 2]);

        $this->task(['due_date' => $today->subDays(3)->utc(), 'priority' => 'medium']);

        $digest = app(DailyDigestService::class)->morning($this->user, $this->localNow());

        $this->assertStringContainsString('1 task overdue', $digest['body']);
        $this->assertStringContainsString('2 checklist items to tick off', $digest['body']);
    }

    public function test_without_urgent_work_the_breakdown_is_left_out(): void
    {
        $this->task([
            'due_date' => $this->localNow()->setTime(14, 0)->utc(),
            'priority' => 'medium',
        ]);

        $digest = app(DailyDigestService::class)->morning($this->user, $this->localNow());

        // "1 task due today — 1 task" would say the same thing twice.
        $this->assertSame('1 task due today.', $digest['body']);
    }

    public function test_a_clear_day_produces_no_morning_digest(): void
    {
        $this->assertNull(app(DailyDigestService::class)->morning($this->user, $this->localNow()));
    }

    public function test_the_evening_summary_reports_what_was_finished(): void
    {
        $today = $this->localNow()->setTime(10, 0);

        $done = $this->task(['due_date' => $today->utc()]);
        $done->setStatus('done');

        $another = $this->task(['due_date' => $today->utc()]);
        $another->setStatus('done');

        $this->task(['due_date' => $today->utc()]);

        $item = $this->task()->checklist()->create(['title' => 'Tick me', 'position' => 0]);
        $item->update(['completed' => true]);

        $digest = app(DailyDigestService::class)->evening($this->user, $this->localNow(19));

        $this->assertNotNull($digest);
        $this->assertStringContainsString('You completed 2 tasks today', $digest['body']);
        $this->assertStringContainsString('1 checklist item ticked off', $digest['body']);
        $this->assertStringContainsString('1 task from today still open', $digest['body']);
        $this->assertSame(2, $digest['data']['completed']);
    }

    public function test_a_quiet_day_produces_no_evening_summary(): void
    {
        $this->assertNull(app(DailyDigestService::class)->evening($this->user, $this->localNow(19)));
    }

    public function test_someone_elses_task_is_not_counted(): void
    {
        $colleague = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->user->currentTenant, $colleague, 'member');

        $this->task([
            'due_date' => $this->localNow()->setTime(14, 0)->utc(),
            'assignee_id' => $colleague->id,
        ]);

        $this->assertNull(app(DailyDigestService::class)->morning($this->user, $this->localNow()));
    }

    /* -------------------------------------------------------------- delivery */

    public function test_the_command_pushes_at_the_users_local_hour_only(): void
    {
        Http::fake([
            'exp.host/*' => Http::response(['data' => [['status' => 'ok']]]),
        ]);

        $this->task(['due_date' => $this->localNow()->setTime(14, 0)->utc(), 'priority' => 'urgent']);

        // 03:00 in Phnom Penh: nothing is due yet.
        $this->travelTo(CarbonImmutable::now('Asia/Phnom_Penh')->setTime(3, 0)->utc());
        $this->artisan('push:digests')->assertSuccessful();
        Http::assertNothingSent();

        // 08:00 local is the morning hour.
        $this->travelTo(CarbonImmutable::now('Asia/Phnom_Penh')->setTime(8, 30)->utc());
        $this->artisan('push:digests')->assertSuccessful();

        Http::assertSent(function ($request) {
            $payload = $request->data()[0];

            return str_contains($payload['title'], 'Good morning')
                && str_contains($payload['body'], '1 task due today')
                && $payload['to'] === 'ExponentPushToken[test-device]'
                && $payload['data']['kind'] === 'morning_digest';
        });
    }

    public function test_the_same_digest_is_never_sent_twice_in_a_day(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);

        $this->task(['due_date' => $this->localNow()->setTime(14, 0)->utc()]);
        $this->travelTo(CarbonImmutable::now('Asia/Phnom_Penh')->setTime(8, 5)->utc());

        $this->artisan('push:digests')->assertSuccessful();
        $this->artisan('push:digests')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, DigestDelivery::where('kind', 'morning')->count());
    }

    public function test_a_user_who_turned_digests_off_gets_nothing(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);

        $this->user->update(['digest_enabled' => false]);
        $this->task(['due_date' => $this->localNow()->setTime(14, 0)->utc()]);

        $this->travelTo(CarbonImmutable::now('Asia/Phnom_Penh')->setTime(8, 5)->utc());
        $this->artisan('push:digests')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_dead_token_is_deleted_rather_than_retried_forever(): void
    {
        Http::fake([
            'exp.host/*' => Http::response([
                'data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]],
            ]),
        ]);

        $this->task(['due_date' => $this->localNow()->setTime(14, 0)->utc()]);
        $this->travelTo(CarbonImmutable::now('Asia/Phnom_Penh')->setTime(8, 5)->utc());

        $this->artisan('push:digests')->assertSuccessful();

        $this->assertSame(0, PushToken::count());
    }
}
