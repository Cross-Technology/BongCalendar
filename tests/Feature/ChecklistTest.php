<?php

namespace Tests\Feature;

use App\Models\ChecklistItem;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        $user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        app(WorkspaceService::class)->create($user, 'Acme', 'Asia/Phnom_Penh');

        return $user->refresh();
    }

    protected function makeTask(User $user): Task
    {
        return Task::factory()->create([
            'tenant_id' => $user->current_tenant_id,
            'created_by' => $user->id,
        ]);
    }

    public function test_items_can_be_added_ticked_and_removed_from_the_panel(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user);

        $component = Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->set('newChecklistTitle', 'Count the crates')
            ->call('addChecklistItem')
            ->assertHasNoErrors()
            ->assertSet('newChecklistTitle', '');

        $item = ChecklistItem::where('title', 'Count the crates')->firstOrFail();
        $this->assertSame($task->id, $item->task_id);
        $this->assertFalse($item->completed);

        $component->call('toggleChecklistItem', $item->id);
        $this->assertTrue($item->fresh()->completed);

        $component->call('toggleChecklistItem', $item->id);
        $this->assertFalse($item->fresh()->completed);

        $component->call('deleteChecklistItem', $item->id);
        $this->assertModelMissing($item);
    }

    public function test_items_can_be_reordered(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user);

        $component = Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id);

        foreach (['First', 'Second', 'Third'] as $title) {
            $component->set('newChecklistTitle', $title)->call('addChecklistItem');
        }

        $second = ChecklistItem::where('title', 'Second')->firstOrFail();

        $component->call('moveChecklistItem', $second->id, -1);

        $this->assertSame(
            ['Second', 'First', 'Third'],
            $task->checklist()->get()->pluck('title')->all()
        );

        $component->call('moveChecklistItem', $second->id, 1);

        $this->assertSame(
            ['First', 'Second', 'Third'],
            $task->checklist()->get()->pluck('title')->all()
        );
    }

    public function test_moving_past_either_end_does_nothing(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user);

        $component = Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->set('newChecklistTitle', 'Only one')
            ->call('addChecklistItem');

        $item = ChecklistItem::where('title', 'Only one')->firstOrFail();

        $component->call('moveChecklistItem', $item->id, -1);
        $component->call('moveChecklistItem', $item->id, 1);

        $this->assertSame(['Only one'], $task->checklist()->get()->pluck('title')->all());
    }

    public function test_deleting_a_task_takes_its_checklist_with_it(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user);
        $item = $task->checklist()->create(['title' => 'Goes away', 'position' => 0]);

        // Soft-deleting the task leaves the row; a hard delete cascades.
        $task->forceDelete();

        $this->assertModelMissing($item);
    }

    public function test_the_checklist_api_round_trips(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user);

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));

        $item = $this->postJson("/api/v1/tasks/{$task->id}/checklist", ['title' => 'Sign the invoice'])
            ->assertCreated()
            ->assertJsonPath('data.completed', false)
            ->json('data');

        $this->patchJson("/api/v1/checklist/{$item['id']}", ['completed' => true])
            ->assertOk()
            ->assertJsonPath('data.completed', true);

        $this->getJson("/api/v1/tasks/{$task->id}/checklist")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Sign the invoice');

        $this->deleteJson("/api/v1/checklist/{$item['id']}")->assertOk();
        $this->getJson("/api/v1/tasks/{$task->id}/checklist")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_checklist_in_another_workspace_is_off_limits(): void
    {
        $user = $this->makeUser();
        $stranger = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($stranger, 'Other Co');

        $foreign = Task::factory()->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $stranger->id,
        ]);
        $item = $foreign->checklist()->create(['title' => 'Not yours', 'position' => 0]);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('toggleChecklistItem', $item->id)
            ->assertForbidden();

        $this->assertFalse($item->fresh()->completed);
    }
}
