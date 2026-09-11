<?php

declare(strict_types=1);

namespace Tests\Feature\Form;

use App\Models\FormWorkflowRun;
use App\Models\User;
use App\Services\Form\FormWorkflowRunService;
use App\Support\KamanUrl;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FormWorkflowRunHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_page_lists_previous_submissions(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        FormWorkflowRun::query()->create([
            'user_id' => $user->id,
            'method_type' => 'HAAT Menu Copy',
            'subdomain' => 'haattest1',
            'environment' => 'rest',
            'status' => FormWorkflowRun::STATUS_RUNNING,
            'payload' => ['subdomain' => 'haattest1'],
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('form.index'))
            ->assertOk()
            ->assertSee('HAAT Menu Copy · haattest1', false)
            ->assertSee('data-run-id=', false)
            ->assertSee(__('form.runs_status_running'));
    }

    public function test_show_run_returns_stored_debugger_events(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $run = FormWorkflowRun::query()->create([
            'user_id' => $user->id,
            'method_type' => 'HAAT Menu Copy',
            'subdomain' => 'haattest1',
            'environment' => 'rest',
            'status' => FormWorkflowRun::STATUS_RUNNING,
            'payload' => ['subdomain' => 'haattest1'],
            'started_at' => now(),
        ]);
        app(FormWorkflowRunService::class)->appendEvent($run, [
            'event' => 'step',
            'step' => 'links',
            'message' => 'Linking Chicken Breast Pita (14 contents)',
            'data' => ['status' => 'run'],
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->actingAs($user)
            ->getJson(route('form.runs.show', $run))
            ->assertOk()
            ->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('run.show_url', route('form.runs.show', $run))
            ->assertJsonPath('events.0.message', 'Linking Chicken Breast Pita (14 contents)');
    }

    public function test_stale_running_run_is_reclaimed_so_continue_is_available(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $run = FormWorkflowRun::query()->create([
            'user_id' => $user->id,
            'method_type' => 'HAAT Menu Copy',
            'subdomain' => 'haattest1',
            'environment' => 'rest',
            'status' => FormWorkflowRun::STATUS_RUNNING,
            'payload' => ['subdomain' => 'haattest1'],
            'started_at' => now()->subMinutes(10),
        ]);
        FormWorkflowRun::query()->whereKey($run->id)->update([
            'updated_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($user)
            ->getJson(route('form.runs.show', $run))
            ->assertOk()
            ->assertJsonPath('run.status', FormWorkflowRun::STATUS_PAUSED)
            ->assertJsonPath('run.can_continue', true);

        $this->actingAs($user)
            ->get(route('form.index'))
            ->assertOk()
            ->assertSee(__('form.runs_continue'))
            ->assertSee('data-run-continue=', false);
    }

    public function test_continue_without_restaurant_session_stays_paused(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $run = $this->pausedHaatRun($user);

        $this->actingAs($user)
            ->postJson(route('form.runs.continue', $run))
            ->assertStatus(422)
            ->assertJsonPath('needs_login', true)
            ->assertJsonPath('run.status', FormWorkflowRun::STATUS_PAUSED)
            ->assertJsonPath('run.can_continue', true);

        $this->assertSame(FormWorkflowRun::STATUS_PAUSED, $run->refresh()->status);
    }

    public function test_failed_link_run_cannot_continue_unless_login_is_required(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $run = FormWorkflowRun::query()->create([
            'user_id' => $user->id,
            'method_type' => 'HAAT Menu Copy',
            'subdomain' => 'haattest1',
            'environment' => 'rest',
            'status' => FormWorkflowRun::STATUS_FAILED,
            'payload' => ['subdomain' => 'haattest1'],
            'result' => ['success' => false, 'error' => 'Could not attach extras to meals.'],
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);

        $this->assertFalse($run->canContinue());

        $run->forceFill([
            'result' => ['success' => false, 'paused' => false, 'needs_login' => true],
        ])->save();

        $this->assertTrue($run->fresh()->canContinue());
    }

    public function test_continue_reuses_kaman_session_for_the_run_subdomain(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $run = $this->pausedHaatRun($user);
        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'session-token',
                'full_ai_kaman_base_url' => rtrim(KamanUrl::managerApi('haattest1', 'rest'), '/'),
            ])
            ->post(route('form.runs.continue', $run))
            ->assertOk();

        $this->assertSame('session-token', app(FormWorkflowRunService::class)->token($run));
    }

    public function test_continue_logs_in_with_posted_password(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $run = $this->pausedHaatRun($user);
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_ends_with($request->url(), '/login')) {
                return Http::response(['token' => 'fresh-token'], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->actingAs($user)
            ->postJson(route('form.runs.continue', $run), [
                'username' => 'haattest1',
                'password' => 'secret',
            ])
            ->assertOk();

        $this->assertSame('fresh-token', app(FormWorkflowRunService::class)->token($run));
    }

    public function test_admin_lists_other_users_menu_agent_runs_and_can_delete(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $admin = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $other = User::where('email', '!=', 'yamen@kaman.rest')->where('is_admin', false)->firstOrFail();

        $foreign = FormWorkflowRun::query()->create([
            'user_id' => $other->id,
            'method_type' => 'Menu Agent',
            'subdomain' => 'otherrest',
            'environment' => 'rest',
            'status' => FormWorkflowRun::STATUS_COMPLETED,
            'payload' => [
                'subdomain' => 'otherrest',
                'conversation_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'title' => 'Other user drinks',
            ],
            'result' => [
                'conversation_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'messages' => [
                    ['role' => 'user', 'content' => 'store drinks', 'files' => []],
                    ['role' => 'assistant', 'content' => 'Done', 'files' => []],
                ],
            ],
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($admin)
            ->getJson(route('form.runs.index'))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $foreign->id,
                'title' => 'Other user drinks',
                'can_delete' => true,
            ]);

        $this->actingAs($admin)
            ->getJson(route('form.runs.show', $foreign))
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Other user drinks')
            ->assertJsonPath('conversation.messages.0.content', 'store drinks');

        $this->actingAs($admin)
            ->deleteJson(route('form.runs.destroy', $foreign))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_id', $foreign->id);

        $this->assertDatabaseMissing('form_workflow_runs', ['id' => $foreign->id]);
    }

    public function test_non_admin_cannot_access_another_users_run(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $owner = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $other = User::where('email', '!=', 'yamen@kaman.rest')->where('is_admin', false)->firstOrFail();
        $run = FormWorkflowRun::query()->create([
            'user_id' => $owner->id,
            'method_type' => 'Menu Agent',
            'subdomain' => 'yamenrest',
            'environment' => 'rest',
            'status' => FormWorkflowRun::STATUS_COMPLETED,
            'payload' => ['conversation_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);

        $this->actingAs($other)
            ->getJson(route('form.runs.show', $run))
            ->assertForbidden();

        $this->actingAs($other)
            ->deleteJson(route('form.runs.destroy', $run))
            ->assertForbidden();

        $this->assertDatabaseHas('form_workflow_runs', ['id' => $run->id]);
    }

    private function pausedHaatRun(User $user): FormWorkflowRun
    {
        return FormWorkflowRun::query()->create([
            'user_id' => $user->id,
            'method_type' => 'HAAT Menu Copy',
            'subdomain' => 'haattest1',
            'environment' => 'rest',
            'status' => FormWorkflowRun::STATUS_PAUSED,
            'payload' => ['subdomain' => 'haattest1', 'environment' => 'rest'],
            'started_at' => now()->subMinutes(10),
            'paused_at' => now(),
        ]);
    }
}
