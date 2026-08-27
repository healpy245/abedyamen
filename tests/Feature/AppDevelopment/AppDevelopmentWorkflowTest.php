<?php

declare(strict_types=1);

namespace Tests\Feature\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketActivityType;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\AppDevelopmentTicketType;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketActivity;
use App\Models\AppDevelopment\AppDevelopmentTicketAttachment;
use App\Models\User;
use App\Services\AppDevelopment\TicketNumberService;
use App\Services\AppDevelopment\TicketWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppDevelopmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('app-development.index'))->assertRedirect('/login');
        $this->get(route('app-development.tickets.index'))->assertRedirect('/login');
        $this->get(route('app-development.releases.index'))->assertRedirect('/login');
    }

    public function test_authorized_qa_and_developer_can_enter(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();

        $this->actingAs($qa)->get(route('app-development.index'))->assertOk();
        $this->actingAs($dev)->get(route('app-development.index'))->assertOk();
        $this->actingAs($qa)->get(route('app-development.tickets.index'))->assertOk();
        $this->actingAs($dev)->get(route('app-development.tickets.index'))->assertOk();
    }

    public function test_unrelated_workspace_user_gets_403(): void
    {
        $outsider = User::factory()->create([
            'is_admin' => false,
            'projects' => [Project::Form->value],
        ]);

        $this->actingAs($outsider)->get(route('app-development.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('app-development.tickets.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('app-development.releases.index'))->assertForbidden();
    }

    public function test_qa_can_create_ticket_with_number_and_creator(): void
    {
        $qa = $this->makeQa(['name' => 'Yamen']);

        $response = $this->actingAs($qa)->post(route('app-development.tickets.store'), [
            'title' => 'Orders page freezes after changing branch',
            'type' => AppDevelopmentTicketType::Bug->value,
            'priority' => AppDevelopmentTicketPriority::High->value,
            'app_types' => ['web'],
            'description' => 'When changing from branch 1 to branch 3, the loader remains forever.',
        ]);

        $ticket = AppDevelopmentTicket::query()->firstOrFail();

        $response->assertRedirect(route('app-development.tickets.show', $ticket));
        $this->assertSame('KAM-0001', $ticket->ticket_number);
        $this->assertSame($qa->id, $ticket->created_by);
        $this->assertSame(AppDevelopmentTicketStatus::Open, $ticket->status);
        $this->assertTrue(
            $ticket->activities()->where('event_type', AppDevelopmentTicketActivityType::Created)->exists()
        );
    }

    public function test_developer_cannot_create_ticket(): void
    {
        $dev = $this->makeDeveloper();

        $this->actingAs($dev)->get(route('app-development.tickets.create'))->assertForbidden();
        $this->actingAs($dev)->post(route('app-development.tickets.store'), [
            'title' => 'Should not work',
            'type' => AppDevelopmentTicketType::Bug->value,
            'priority' => AppDevelopmentTicketPriority::Normal->value,
            'app_types' => ['web'],
            'description' => 'Nope',
        ])->assertForbidden();

        $this->assertSame(0, AppDevelopmentTicket::query()->count());
    }

    public function test_qa_can_delete_ticket(): void
    {
        $qa = $this->makeQa();
        $ticket = $this->makeTicket($qa);

        $this->actingAs($qa)
            ->delete(route('app-development.tickets.destroy', $ticket))
            ->assertRedirect(route('app-development.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, AppDevelopmentTicket::query()->count());
    }

    public function test_developer_cannot_delete_ticket(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->makeTicket($qa);

        $this->actingAs($dev)
            ->delete(route('app-development.tickets.destroy', $ticket))
            ->assertForbidden();

        $this->assertTrue(AppDevelopmentTicket::query()->whereKey($ticket->id)->exists());
    }

    public function test_developer_can_claim_open_ticket(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper(['name' => 'Amro']);
        $ticket = $this->makeTicket($qa);

        $this->actingAs($dev)
            ->post(route('app-development.tickets.start-work', $ticket))
            ->assertRedirect(route('app-development.tickets.show', $ticket->fresh()));

        $ticket->refresh();
        $this->assertSame(AppDevelopmentTicketStatus::Working, $ticket->status);
        $this->assertSame($dev->id, $ticket->assigned_to);
        $this->assertTrue(
            $ticket->activities()->where('event_type', AppDevelopmentTicketActivityType::StartedWork)->exists()
        );
    }

    public function test_second_developer_cannot_claim_already_assigned_ticket(): void
    {
        $qa = $this->makeQa();
        $first = $this->makeDeveloper(['name' => 'Amro']);
        $second = $this->makeDeveloper(['name' => 'Moaz']);
        $ticket = $this->makeTicket($qa);

        app(TicketWorkflowService::class)->startWork($ticket, $first);

        $this->actingAs($second)
            ->from(route('app-development.tickets.show', $ticket))
            ->post(route('app-development.tickets.start-work', $ticket))
            ->assertRedirect(route('app-development.tickets.show', $ticket))
            ->assertSessionHas('error', __('app-development.errors.already_assigned'));

        $ticket->refresh();
        $this->assertSame($first->id, $ticket->assigned_to);
        $this->assertSame(AppDevelopmentTicketStatus::Working, $ticket->status);
    }

    public function test_developer_can_submit_working_ticket_to_qa(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->makeTicket($qa);
        app(TicketWorkflowService::class)->startWork($ticket, $dev);

        $this->actingAs($dev)->post(route('app-development.tickets.send-to-qa', $ticket), [
            'note' => 'Fixed on Android. Included in APK v2.7.14.',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(AppDevelopmentTicketStatus::Qa, $ticket->status);
        $this->assertNotNull($ticket->submitted_for_qa_at);
        $this->assertTrue(
            $ticket->activities()->where('event_type', AppDevelopmentTicketActivityType::SentToQa)->exists()
        );
    }

    public function test_qa_cannot_trigger_developer_only_transitions(): void
    {
        $qa = $this->makeQa();
        $ticket = $this->makeTicket($qa);

        $this->actingAs($qa)->post(route('app-development.tickets.start-work', $ticket))->assertForbidden();

        $dev = $this->makeDeveloper();
        app(TicketWorkflowService::class)->startWork($ticket, $dev);

        $this->actingAs($qa)->post(route('app-development.tickets.send-to-qa', $ticket))->assertForbidden();
    }

    public function test_qa_can_complete_and_developer_cannot(): void
    {
        $qa = $this->makeQa(['name' => 'Yamen']);
        $dev = $this->makeDeveloper();
        $ticket = $this->readyForQa($qa, $dev);

        $this->actingAs($dev)->post(route('app-development.tickets.complete', $ticket))->assertForbidden();
        $this->assertSame(AppDevelopmentTicketStatus::Qa, $ticket->fresh()->status);

        $this->actingAs($qa)->post(route('app-development.tickets.complete', $ticket), [
            'note' => 'Looks good.',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(AppDevelopmentTicketStatus::Completed, $ticket->status);
        $this->assertSame($qa->id, $ticket->completed_by);
        $this->assertNotNull($ticket->completed_at);
    }

    public function test_qa_rejection_requires_notes_and_keeps_developer(): void
    {
        $qa = $this->makeQa(['name' => 'Abed Jaber']);
        $dev = $this->makeDeveloper(['name' => 'Amro']);
        $ticket = $this->readyForQa($qa, $dev);

        $this->actingAs($qa)
            ->from(route('app-development.tickets.show', $ticket))
            ->post(route('app-development.tickets.return-to-development', $ticket), [
                'note' => '',
            ])
            ->assertSessionHasErrors('note');

        $this->assertSame(AppDevelopmentTicketStatus::Qa, $ticket->fresh()->status);

        $note = 'The loader is fixed but branch 3 shows orders from branch 1 until the page is refreshed.';

        $this->actingAs($qa)->post(route('app-development.tickets.return-to-development', $ticket), [
            'note' => $note,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(AppDevelopmentTicketStatus::Working, $ticket->status);
        $this->assertSame($dev->id, $ticket->assigned_to);
        $this->assertSame(1, $ticket->qa_rejection_count);
        $this->assertTrue(
            $ticket->activities()->where('event_type', AppDevelopmentTicketActivityType::QaRejected)->exists()
        );
        $this->assertSame($note, $ticket->latestQaRejection?->note());

        $this->actingAs($dev)
            ->get(route('app-development.tickets.show', $ticket))
            ->assertOk()
            ->assertSee($note, false);
    }

    public function test_invalid_transitions_are_blocked(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->makeTicket($qa);

        $this->actingAs($qa)->post(route('app-development.tickets.complete', $ticket))->assertForbidden();

        $workflow = app(TicketWorkflowService::class);
        $workflow->startWork($ticket, $dev);
        $workflow->submitForQa($ticket->fresh(), $dev);
        $completed = $workflow->complete($ticket->fresh(), $qa);

        $this->expectException(\App\Exceptions\AppDevelopment\TicketWorkflowException::class);
        $workflow->startWork($completed->fresh(), $dev);
    }

    public function test_completed_ticket_cannot_return_to_working(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->readyForQa($qa, $dev);
        app(TicketWorkflowService::class)->complete($ticket, $qa);

        $this->actingAs($qa)
            ->post(route('app-development.tickets.return-to-development', $ticket->fresh()), [
                'note' => 'Trying to reopen',
            ])
            ->assertForbidden();

        $this->assertSame(AppDevelopmentTicketStatus::Completed, $ticket->fresh()->status);
    }

    public function test_working_qa_cycles_preserve_history(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->readyForQa($qa, $dev);
        $workflow = app(TicketWorkflowService::class);

        $workflow->returnToDevelopment($ticket->fresh(), $qa, 'Still broken');
        $workflow->submitForQa($ticket->fresh(), $dev, 'Tried again');
        $workflow->returnToDevelopment($ticket->fresh(), $qa, 'Still broken again');
        $workflow->submitForQa($ticket->fresh(), $dev);

        $this->assertSame(2, $ticket->fresh()->qa_rejection_count);
        $this->assertSame(
            2,
            AppDevelopmentTicketActivity::query()
                ->where('ticket_id', $ticket->id)
                ->where('event_type', AppDevelopmentTicketActivityType::QaRejected)
                ->count()
        );
        $this->assertSame(
            3,
            AppDevelopmentTicketActivity::query()
                ->where('ticket_id', $ticket->id)
                ->where('event_type', AppDevelopmentTicketActivityType::SentToQa)
                ->count()
        );
    }

    public function test_developer_can_upload_apk_and_qa_cannot(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper(['name' => 'Amro']);
        $ticket = $this->makeTicket($qa);

        $this->actingAs($qa)->get(route('app-development.releases.create'))->assertForbidden();

        $file = UploadedFile::fake()->create('KAMAN-v2.7.14.apk', 1024);

        $this->actingAs($qa)->post(route('app-development.releases.store'), [
            'version_name' => '2.7.14',
            'version_code' => 2714,
            'apk' => $file,
        ])->assertForbidden();

        $this->actingAs($dev)->post(route('app-development.releases.store'), [
            'version_name' => '2.7.14',
            'version_code' => 2714,
            'release_notes' => 'Fixed branch switching',
            'apk' => UploadedFile::fake()->create('KAMAN-v2.7.14.apk', 1024),
            'ticket_ids' => [$ticket->id],
        ])->assertRedirect();

        $release = AppDevelopmentRelease::query()->firstOrFail();
        $this->assertTrue($release->is_latest);
        $this->assertSame($dev->id, $release->uploaded_by);
        $this->assertNotSame('', $release->checksum_sha256);
        $this->assertTrue(Storage::disk('local')->exists($release->file_path));
        $this->assertTrue($release->tickets()->whereKey($ticket->id)->exists());
    }

    public function test_qa_can_download_apk_and_previous_releases_remain(): void
    {
        $qa = $this->makeQa(['name' => 'Yamen']);
        $dev = $this->makeDeveloper();
        $outsider = User::factory()->create([
            'is_admin' => false,
            'projects' => [Project::Form->value],
        ]);

        $first = $this->actingAs($dev)->post(route('app-development.releases.store'), [
            'version_name' => '2.7.13',
            'apk' => UploadedFile::fake()->create('old.apk', 800),
        ]);
        $first->assertRedirect();

        $this->actingAs($dev)->post(route('app-development.releases.store'), [
            'version_name' => '2.7.14',
            'apk' => UploadedFile::fake()->create('new.apk', 900),
        ])->assertRedirect();

        $old = AppDevelopmentRelease::query()->where('version_name', '2.7.13')->firstOrFail();
        $new = AppDevelopmentRelease::query()->where('version_name', '2.7.14')->firstOrFail();

        $this->assertFalse($old->fresh()->is_latest);
        $this->assertTrue($new->fresh()->is_latest);
        $this->assertTrue(Storage::disk('local')->exists($old->file_path));
        $this->assertTrue(Storage::disk('local')->exists($new->file_path));

        $this->actingAs($qa)->get(route('app-development.releases.download', $new))->assertOk();
        $this->actingAs($qa)->get(route('app-development.releases.download', $old))->assertOk();
        $this->actingAs($outsider)->get(route('app-development.releases.download', $new))->assertForbidden();

        $this->post('/logout');
        $this->get(route('app-development.releases.download', $new))->assertRedirect('/login');
    }

    public function test_attachments_are_protected(): void
    {
        $qa = $this->makeQa();
        $outsider = User::factory()->create([
            'is_admin' => false,
            'projects' => [Project::AiChatbot->value],
        ]);
        $ticket = $this->makeTicket($qa);

        $this->actingAs($qa)->post(route('app-development.tickets.attachments.store', $ticket), [
            'attachment' => UploadedFile::fake()->image('crash.png'),
        ])->assertRedirect();

        $attachment = AppDevelopmentTicketAttachment::query()->firstOrFail();

        $this->actingAs($qa)
            ->get(route('app-development.tickets.attachments.download', [$ticket, $attachment]))
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes');

        $this->actingAs($qa)
            ->withHeaders(['Range' => 'bytes=0-1'])
            ->get(route('app-development.tickets.attachments.download', [$ticket, $attachment]))
            ->assertStatus(206);

        $this->actingAs($outsider)
            ->get(route('app-development.tickets.attachments.download', [$ticket, $attachment]))
            ->assertForbidden();

        $this->post('/logout');
        $this->get(route('app-development.tickets.attachments.download', [$ticket, $attachment]))
            ->assertRedirect('/login');
    }

    public function test_comment_can_include_voice_note(): void
    {
        $qa = $this->makeQa();
        $ticket = $this->makeTicket($qa);

        $voice = UploadedFile::fake()->create('note.webm', 120, 'audio/webm');

        $this->actingAs($qa)->post(route('app-development.tickets.comments.store', $ticket), [
            'voices' => [$voice],
        ])->assertRedirect(route('app-development.tickets.show', $ticket));

        $comment = $ticket->comments()->latest('id')->first();
        $this->assertNotNull($comment);
        $this->assertNotNull($comment->attachment_id);
        $this->assertTrue($comment->attachment?->isAudio());
    }

    public function test_seeder_reuses_yamen_and_does_not_strip_existing_projects(): void
    {
        $this->seed([
            \Database\Seeders\WorkspaceUserSeeder::class,
            \Database\Seeders\AppDevelopmentMemberSeeder::class,
        ]);

        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $this->assertTrue($yamen->is_admin);
        $this->assertTrue($yamen->canAccessProject(Project::Form));
        $this->assertTrue($yamen->canAccessProject(Project::AiChatbot));
        $this->assertTrue($yamen->canAccessProject(Project::AppDevelopment));
        $this->assertTrue($yamen->isQa());
        $this->assertTrue($yamen->isDeveloper());
        $this->assertTrue($yamen->isAppDevelopmentAdmin());
        $this->assertSame(AppDevelopmentRole::Admin, $yamen->appDevelopmentRole());

        $this->assertSame(1, User::query()->where('email', 'yamen@kaman.rest')->count());
        $this->assertSame(0, User::query()->where('email', 'yamen@kaman')->count());

        $malan = User::query()->where('email', 'malan@kaman.rest')->firstOrFail();
        $this->assertSame(['ai-chatbot'], $malan->projectKeys());
        $this->assertFalse($malan->canAccessProject(Project::AppDevelopment));

        $ahmad = User::query()->where('email', 'ahmad@kaman.rest')->firstOrFail();
        $this->assertSame(['form'], $ahmad->projectKeys());

        $this->assertNotNull(User::query()->where('email', 'abedjaber@kaman.rest')->first());
        $this->assertTrue(User::query()->where('email', 'amro@kaman.rest')->firstOrFail()->isDeveloper());
    }

    public function test_qa_queue_redirects_to_tickets_qa_tab(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();

        $this->actingAs($qa)
            ->get(route('app-development.qa.index'))
            ->assertRedirect(route('app-development.index', ['tab' => 'qa']));
        $this->actingAs($dev)
            ->get(route('app-development.qa.index'))
            ->assertRedirect(route('app-development.index', ['tab' => 'qa']));
    }

    public function test_admin_has_qa_and_developer_actions(): void
    {
        $admin = $this->makeMember(AppDevelopmentRole::Admin, [
            'name' => 'Yamen',
            'is_admin' => true,
        ]);

        $this->assertTrue($admin->isQa());
        $this->assertTrue($admin->isDeveloper());
        $this->assertTrue($admin->isAppDevelopmentAdmin());

        $this->actingAs($admin)->get(route('app-development.index'))->assertOk()
            ->assertSee(__('app-development.nav.new_ticket'))
            ->assertSee(__('app-development.nav.upload_apk'))
            ->assertDontSee('kaman-filter-chip--mine', false)
            ->assertDontSee('kaman-filter-chip--returned', false)
            ->assertSee(__('app-development.tabs.qa'));

        $this->actingAs($admin)->get(route('app-development.tickets.create'))->assertOk();
        $this->actingAs($admin)->get(route('app-development.releases.create'))->assertOk();

        $this->actingAs($admin)->post(route('app-development.tickets.store'), [
            'title' => 'Admin can open tickets',
            'type' => AppDevelopmentTicketType::Bug->value,
            'priority' => AppDevelopmentTicketPriority::High->value,
            'app_types' => ['printing', 'web'],
            'description' => 'Superadmin should be able to create tickets.',
        ])->assertRedirect();

        $ticket = AppDevelopmentTicket::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('app-development.tickets.start-work', $ticket))
            ->assertRedirect(route('app-development.tickets.show', $ticket->fresh()));

        $this->actingAs($admin)
            ->post(route('app-development.tickets.send-to-qa', $ticket->fresh()), ['note' => 'Ready'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('app-development.tickets.complete', $ticket->fresh()), ['note' => 'OK'])
            ->assertRedirect();

        $this->assertSame(AppDevelopmentTicketStatus::Completed, $ticket->fresh()->status);
    }

    public function test_ticket_and_release_forms_open_as_modals(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->makeTicket($qa);

        $this->actingAs($qa)
            ->get(route('app-development.tickets.create'))
            ->assertOk()
            ->assertSee('app-dev-modal', false)
            ->assertSee(__('app-development.tickets.create'));

        $this->actingAs($qa)
            ->withHeaders(['X-App-Dev-Modal' => '1'])
            ->get(route('app-development.tickets.create'))
            ->assertOk()
            ->assertSee('name="title"', false)
            ->assertDontSee('id="app-dev-modal"', false);

        $this->actingAs($qa)
            ->get(route('app-development.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('app-dev-modal', false)
            ->assertSee($ticket->title)
            ->assertSee($ticket->description)
            ->assertDontSee(__('app-development.modal.overview'));

        $this->actingAs($qa)
            ->get(route('app-development.tickets.edit', $ticket))
            ->assertOk()
            ->assertSee(__('app-development.tickets.edit'));

        $this->actingAs($dev)
            ->get(route('app-development.releases.create'))
            ->assertOk()
            ->assertSee('app-dev-modal', false)
            ->assertSee(__('app-development.releases.upload'));
    }

    public function test_tickets_index_offers_inline_priority_and_status_pickers(): void
    {
        $qa = $this->makeQa();
        $this->makeTicket($qa);

        $this->actingAs($qa)
            ->get(route('app-development.index'))
            ->assertOk()
            ->assertSee('data-kaman-inline', false)
            ->assertSee('data-field="priority"', false)
            ->assertSee('data-field="status"', false)
            ->assertSee('app-development-inline.js', false);
    }

    public function test_qa_can_change_priority_inline_from_table(): void
    {
        $qa = $this->makeQa();
        $ticket = $this->makeTicket($qa, [
            'priority' => AppDevelopmentTicketPriority::High,
        ]);

        $this->actingAs($qa)
            ->patchJson(route('app-development.tickets.priority', $ticket), [
                'priority' => AppDevelopmentTicketPriority::Critical->value,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('value', 'critical')
            ->assertJsonPath('critical', true)
            ->assertJsonStructure(['badge_html', 'message']);

        $this->assertSame(AppDevelopmentTicketPriority::Critical, $ticket->fresh()->priority);

        $this->assertDatabaseHas('app_development_ticket_activities', [
            'ticket_id' => $ticket->id,
            'event_type' => AppDevelopmentTicketActivityType::Updated->value,
        ]);
    }

    public function test_developer_can_change_status_inline_from_table(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->makeTicket($qa);

        $this->actingAs($dev)
            ->patchJson(route('app-development.tickets.status', $ticket), [
                'status' => AppDevelopmentTicketStatus::Working->value,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('value', 'working')
            ->assertJsonStructure(['status_counts' => ['open', 'working', 'qa', 'completed']]);

        $this->assertSame(AppDevelopmentTicketStatus::Working, $ticket->fresh()->status);
    }

    public function test_setting_status_to_completed_records_completion_fields(): void
    {
        $qa = $this->makeQa();
        $ticket = $this->makeTicket($qa, [
            'status' => AppDevelopmentTicketStatus::Working,
        ]);

        $this->actingAs($qa)
            ->patchJson(route('app-development.tickets.status', $ticket), [
                'status' => AppDevelopmentTicketStatus::Completed->value,
            ])
            ->assertOk();

        $ticket->refresh();
        $this->assertSame(AppDevelopmentTicketStatus::Completed, $ticket->status);
        $this->assertNotNull($ticket->completed_at);
        $this->assertSame($qa->id, (int) $ticket->completed_by);
    }

    public function test_outsider_cannot_change_priority_or_status(): void
    {
        $qa = $this->makeQa();
        $ticket = $this->makeTicket($qa);
        $outsider = User::factory()->create([
            'is_admin' => false,
            'projects' => [Project::Form->value],
        ]);

        $this->actingAs($outsider)
            ->patchJson(route('app-development.tickets.priority', $ticket), [
                'priority' => AppDevelopmentTicketPriority::Low->value,
            ])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->patchJson(route('app-development.tickets.status', $ticket), [
                'status' => AppDevelopmentTicketStatus::Completed->value,
            ])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeQa(array $overrides = []): User
    {
        return $this->makeMember(AppDevelopmentRole::Qa, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDeveloper(array $overrides = []): User
    {
        return $this->makeMember(AppDevelopmentRole::Developer, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMember(AppDevelopmentRole $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_admin' => false,
            'projects' => [Project::AppDevelopment->value],
        ], $overrides));

        AppDevelopmentMember::query()->create([
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user->fresh(['appDevelopmentMembership']) ?? $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTicket(User $qa, array $overrides = []): AppDevelopmentTicket
    {
        $ticket = AppDevelopmentTicket::query()->create(array_merge([
            'ticket_number' => 'TMP-'.Str::ulid(),
            'title' => 'Orders page freezes after changing branch',
            'description' => 'When changing from branch 1 to branch 3, the loader remains forever.',
            'type' => AppDevelopmentTicketType::Bug,
            'priority' => AppDevelopmentTicketPriority::High,
            'status' => AppDevelopmentTicketStatus::Open,
            'created_by' => $qa->id,
        ], $overrides));

        app(TicketNumberService::class)->assign($ticket);
        $ticket->syncAppTypes(['web']);

        return $ticket->refresh();
    }

    private function readyForQa(User $qa, User $dev): AppDevelopmentTicket
    {
        $ticket = $this->makeTicket($qa);
        $workflow = app(TicketWorkflowService::class);
        $workflow->startWork($ticket, $dev);

        return $workflow->submitForQa($ticket->fresh(), $dev);
    }
}
