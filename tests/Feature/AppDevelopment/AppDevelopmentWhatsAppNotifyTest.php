<?php

declare(strict_types=1);

namespace Tests\Feature\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\AppDevelopmentTicketType;
use App\Enums\Project;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use App\Services\AppDevelopment\AppDevelopmentWhatsAppNotifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppDevelopmentWhatsAppNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_ringbells_and_team_nav(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('app-development.index'))
            ->assertOk()
            ->assertSee('kaman-filter-chip-bell', false)
            ->assertSee(route('app-development.notify.open'), false)
            ->assertSee(route('app-development.notify.qa'), false)
            ->assertSee(__('app-development.nav.team'));
    }

    public function test_developer_does_not_see_ringbells(): void
    {
        $dev = $this->makeMember(AppDevelopmentRole::Developer);

        $this->actingAs($dev)
            ->get(route('app-development.index'))
            ->assertOk()
            ->assertDontSee('kaman-filter-chip-bell', false)
            ->assertDontSee(__('app-development.nav.team'));
    }

    public function test_open_ringbell_notifies_developers_not_disabled_qa(): void
    {
        Http::fake([
            'https://green.test/*' => Http::response(['idMessage' => 'ok'], 200),
        ]);

        $admin = $this->makeAdmin();
        $this->makeKamanInstance($admin);

        $dev = $this->makeMember(AppDevelopmentRole::Developer, [
            'name' => 'Amro',
            'email' => 'amro@test.rest',
        ], '0546452973', true);

        $qaOff = $this->makeMember(AppDevelopmentRole::Qa, [
            'name' => 'Abed Jaber',
            'email' => 'abedjaber@test.rest',
        ], '+972584680004', false);

        $this->makeTicket($admin, AppDevelopmentTicketStatus::Open);
        $this->makeTicket($admin, AppDevelopmentTicketStatus::Open);

        $this->actingAs($admin)
            ->post(route('app-development.notify.open'), [
                'user_ids' => [$dev->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($dev): bool {
            $body = $request->data();

            return $request->url() === 'https://green.test/waInstance1/sendMessage/token'
                && ($body['chatId'] ?? null) === '972546452973@c.us'
                && str_contains((string) ($body['message'] ?? ''), '2')
                && str_contains((string) ($body['message'] ?? ''), (string) $dev->name);
        });

        $this->assertFalse(
            AppDevelopmentMember::query()->where('user_id', $qaOff->id)->firstOrFail()->wantsWhatsappNotifications()
        );
    }

    public function test_qa_ringbell_notifies_testers_only(): void
    {
        Http::fake([
            'https://green.test/*' => Http::response(['idMessage' => 'ok'], 200),
        ]);

        $admin = $this->makeAdmin();
        $this->makeKamanInstance($admin);

        $tester = $this->makeMember(AppDevelopmentRole::Qa, [
            'name' => 'Ahmad Essa',
            'email' => 'ahmad@test.rest',
        ], '+972549133538', true);

        $this->makeMember(AppDevelopmentRole::Developer, [
            'name' => 'Moaz',
            'email' => 'moaz@test.rest',
        ], '+972523211175', true);

        $this->makeTicket($admin, AppDevelopmentTicketStatus::Qa);

        $this->actingAs($admin)
            ->post(route('app-development.notify.qa'), [
                'user_ids' => [$tester->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ($request->data()['chatId'] ?? null) === '972549133538@c.us'
            && str_contains((string) ($request->data()['message'] ?? ''), (string) $tester->name));
    }

    public function test_admin_can_toggle_member_whatsapp_and_syncs_ignore_list(): void
    {
        $admin = $this->makeAdmin();
        $instance = $this->makeKamanInstance($admin);
        $member = $this->makeMember(AppDevelopmentRole::Developer, [
            'name' => 'Minna',
            'email' => 'minna@test.rest',
        ], '+972547239847', true)->appDevelopmentMembership;

        $this->actingAs($admin)
            ->put(route('app-development.team.update', $member), [
                'phone' => '+972547239847',
                'whatsapp_notifications_enabled' => 0,
                'password' => 'MinnaNew123@',
                'password_confirmation' => 'MinnaNew123@',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $member->refresh();
        $this->assertFalse($member->whatsapp_notifications_enabled);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('MinnaNew123@', $member->user->fresh()->password));

        $instance->refresh();
        $ignored = $instance->ignoredReplyPhones();
        $this->assertNotEmpty($ignored);

        $normalized = app(AppDevelopmentWhatsAppNotifyService::class)->chatIdFromPhone('+972547239847');
        $this->assertSame('972547239847@c.us', $normalized);
    }

    public function test_kaman_bot_ignore_list_includes_worker_phones(): void
    {
        $admin = $this->makeAdmin();
        $instance = $this->makeKamanInstance($admin);
        $this->makeMember(AppDevelopmentRole::Developer, [
            'email' => 'amro2@test.rest',
        ], '0546452973', true);

        app(AppDevelopmentWhatsAppNotifyService::class)->syncWorkerPhonesOntoKamanBotIgnoreList();

        $instance->refresh();
        $ignored = collect($instance->ignoredReplyPhones())
            ->map(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?? '')
            ->all();

        $this->assertTrue(collect($ignored)->contains(fn (string $d): bool => str_contains($d, '546452973') || str_contains($d, '0546452973')));
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Yamen',
            'email' => 'yamen@test.rest',
            'is_admin' => true,
            'projects' => [Project::AppDevelopment->value],
        ]);

        AppDevelopmentMember::query()->create([
            'user_id' => $user->id,
            'role' => AppDevelopmentRole::Admin,
            'phone' => null,
            'whatsapp_notifications_enabled' => true,
        ]);

        return $user->fresh(['appDevelopmentMembership']) ?? $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMember(AppDevelopmentRole $role, array $overrides = [], ?string $phone = null, bool $notify = true): User
    {
        $user = User::factory()->create(array_merge([
            'is_admin' => false,
            'projects' => [Project::AppDevelopment->value],
        ], $overrides));

        AppDevelopmentMember::query()->create([
            'user_id' => $user->id,
            'role' => $role,
            'phone' => $phone,
            'whatsapp_notifications_enabled' => $notify,
        ]);

        return $user->fresh(['appDevelopmentMembership']) ?? $user;
    }

    private function makeKamanInstance(User $owner): ChatbotInstance
    {
        return ChatbotInstance::query()->create([
            'user_id' => $owner->id,
            'name' => 'Kaman POS — WhatsApp',
            'system_prompt' => 'test',
            'stores_members' => false,
            'integration_type' => 'kaman_whatsapp',
            'greenapi_url' => 'https://green.test/waInstance1/sendMessage/token',
            'integration_settings' => [
                'enabled' => true,
                'ignored_reply_phones' => [],
            ],
        ]);
    }

    private function makeTicket(User $creator, AppDevelopmentTicketStatus $status): AppDevelopmentTicket
    {
        return AppDevelopmentTicket::query()->create([
            'ticket_number' => 'KAM-'.fake()->unique()->numerify('####'),
            'title' => 'Test ticket',
            'description' => 'Desc',
            'type' => AppDevelopmentTicketType::Bug,
            'priority' => AppDevelopmentTicketPriority::Normal,
            'status' => $status,
            'created_by' => $creator->id,
        ]);
    }
}
