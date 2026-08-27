<?php

declare(strict_types=1);

namespace Tests\Feature\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\AppDevelopmentTicketType;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use App\Services\AppDevelopment\TicketNumberService;
use App\Services\AppDevelopment\TicketWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppDevelopmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_creating_a_ticket_notifies_other_team_members_not_the_actor(): void
    {
        $qa = $this->makeQa(['name' => 'Yamen']);
        $otherQa = $this->makeQa(['name' => 'Abed Jaber']);
        $dev = $this->makeDeveloper(['name' => 'Amro']);

        $this->actingAs($qa)->post(route('app-development.tickets.store'), [
            'title' => 'Tables crash',
            'type' => AppDevelopmentTicketType::Bug->value,
            'priority' => AppDevelopmentTicketPriority::High->value,
            'app_types' => ['web'],
            'description' => 'Tap a table and the screen freezes.',
        ]);

        $this->assertSame(0, $qa->notifications()->count());
        $this->assertSame(1, $otherQa->fresh()->notifications()->count());
        $this->assertSame(1, $dev->fresh()->notifications()->count());
        $this->assertSame('created', $dev->fresh()->unreadNotifications->first()?->data['event']);
    }

    public function test_comment_notifies_the_other_party(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->makeTicket($qa, ['assigned_to' => $dev->id]);

        $this->actingAs($qa)->post(route('app-development.tickets.comments.store', $ticket), [
            'body' => 'Can you check branch 3 as well?',
        ]);

        $this->assertSame(0, $qa->notifications()->count());
        $this->assertSame(1, $dev->fresh()->unreadNotifications()->count());
        $this->assertSame('commented', $dev->fresh()->unreadNotifications->first()?->data['event']);
        $this->assertSame('Can you check branch 3 as well?', $dev->fresh()->unreadNotifications->first()?->data['excerpt']);
    }

    public function test_qa_rejection_notifies_assigned_developer(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();
        $ticket = $this->makeTicket($qa);
        $workflow = app(TicketWorkflowService::class);
        $workflow->startWork($ticket, $dev);
        $workflow->submitForQa($ticket->fresh(), $dev);

        $dev->notifications()->delete();
        $qa->notifications()->delete();

        $this->actingAs($qa)->post(route('app-development.tickets.return-to-development', $ticket->fresh()), [
            'note' => 'Still broken on branch 3.',
        ]);

        $this->assertSame(1, $dev->fresh()->unreadNotifications()->count());
        $this->assertSame('qa_rejected', $dev->fresh()->unreadNotifications->first()?->data['event']);
        $this->assertSame(0, $qa->fresh()->notifications()->count());
    }

    public function test_bell_and_mark_all_read(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();

        $this->actingAs($qa)->post(route('app-development.tickets.store'), [
            'title' => 'Tables crash',
            'type' => AppDevelopmentTicketType::Bug->value,
            'priority' => AppDevelopmentTicketPriority::Normal->value,
            'app_types' => ['web'],
            'description' => 'Tap a table and the screen freezes.',
        ]);

        $ticket = \App\Models\AppDevelopment\AppDevelopmentTicket::query()->firstOrFail();

        $this->actingAs($dev)
            ->get(route('app-development.index'))
            ->assertOk()
            ->assertSee(__('app-development.notifications.title'))
            ->assertSee($ticket->ticket_number);

        $this->assertSame(1, $dev->fresh()->unreadNotifications()->count());

        $this->actingAs($dev)
            ->post(route('app-development.notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, $dev->fresh()->unreadNotifications()->count());
    }

    public function test_opening_a_notification_marks_it_read_and_goes_to_the_ticket(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();

        $this->actingAs($qa)->post(route('app-development.tickets.store'), [
            'title' => 'Tables crash',
            'type' => AppDevelopmentTicketType::Bug->value,
            'priority' => AppDevelopmentTicketPriority::Normal->value,
            'app_types' => ['web'],
            'description' => 'Tap a table and the screen freezes.',
        ]);

        $notification = $dev->fresh()->unreadNotifications->first();
        $this->assertNotNull($notification);
        $ticket = AppDevelopmentTicket::query()->firstOrFail();

        $this->actingAs($dev)
            ->get(route('app-development.notifications.open', $notification))
            ->assertRedirect(route('app-development.tickets.show', $ticket));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_apk_upload_without_tickets_notifies_qa(): void
    {
        $qa = $this->makeQa();
        $dev = $this->makeDeveloper();

        $this->actingAs($dev)->post(route('app-development.releases.store'), [
            'version_name' => '2.7.14',
            'apk' => UploadedFile::fake()->create('KAMAN.apk', 500),
        ]);

        $this->assertSame(1, $qa->fresh()->unreadNotifications()->count());
        $this->assertSame('apk_uploaded', $qa->fresh()->unreadNotifications->first()?->data['event']);
        $this->assertSame(0, $dev->fresh()->notifications()->count());
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
}
