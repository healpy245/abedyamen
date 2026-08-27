<?php

declare(strict_types=1);

namespace Tests\Feature\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketType;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_timer_start_pause_complete_and_blocks_second_timer(): void
    {
        $qa = $this->member(AppDevelopmentRole::Qa);
        $dev = $this->member(AppDevelopmentRole::Developer);
        $ticket = $this->ticket($qa);

        $this->actingAs($qa)->post(route('app-development.tickets.tasks.store', $ticket), [
            'title' => 'Diagnose printer',
            'description' => 'Check logs',
            'assignee_id' => $dev->id,
            'priority' => AppDevelopmentTicketPriority::High->value,
        ])->assertRedirect();

        $task = AppDevelopmentTask::query()->firstOrFail();
        $this->assertSame(AppDevelopmentTaskStatus::Todo, $task->status);

        $other = AppDevelopmentTask::query()->create([
            'ticket_id' => $ticket->id,
            'title' => 'Second task',
            'assignee_id' => $dev->id,
            'priority' => AppDevelopmentTicketPriority::Normal,
            'status' => AppDevelopmentTaskStatus::Todo,
            'created_by' => $qa->id,
        ]);

        $this->actingAs($dev)
            ->post(route('app-development.tasks.timer.start', $task))
            ->assertRedirect();

        $this->assertSame(AppDevelopmentTaskStatus::InProgress, $task->fresh()->status);
        $this->assertNotNull(AppDevelopmentTimeEntry::query()->whereNull('ended_at')->first());

        $this->actingAs($dev)
            ->postJson(route('app-development.tasks.timer.start', $other))
            ->assertStatus(409);

        $this->actingAs($dev)
            ->post(route('app-development.tasks.timer.pause', $task))
            ->assertRedirect();

        $this->assertSame(AppDevelopmentTaskStatus::Paused, $task->fresh()->status);
        $this->assertNull(AppDevelopmentTimeEntry::query()->whereNull('ended_at')->first());

        $this->actingAs($dev)
            ->post(route('app-development.tasks.timer.start', $task))
            ->assertRedirect();

        $this->actingAs($dev)
            ->post(route('app-development.tasks.timer.complete', $task), [
                'completion_note' => 'Fixed',
            ])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame(AppDevelopmentTaskStatus::Completed, $task->status);
        $this->assertGreaterThan(0, $task->totalDurationSeconds());
    }

    public function test_reports_are_admin_only(): void
    {
        $admin = $this->member(AppDevelopmentRole::Admin);
        $dev = $this->member(AppDevelopmentRole::Developer);

        $this->actingAs($dev)->get(route('app-development.reports.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('app-development.reports.index'))->assertOk();
    }

    private function member(AppDevelopmentRole $role): User
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'projects' => [Project::AppDevelopment->value],
        ]);
        AppDevelopmentMember::query()->create([
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function ticket(User $qa): AppDevelopmentTicket
    {
        $ticket = AppDevelopmentTicket::query()->create([
            'ticket_number' => 'KAM-5555',
            'title' => 'Printer issue',
            'description' => 'No receipt',
            'type' => AppDevelopmentTicketType::Bug,
            'priority' => AppDevelopmentTicketPriority::High,
            'status' => 'open',
            'created_by' => $qa->id,
        ]);
        $ticket->syncAppTypes(['printing']);

        return $ticket;
    }
}
