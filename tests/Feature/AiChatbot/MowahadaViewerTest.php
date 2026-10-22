<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\MansourBotService;
use Database\Seeders\InvitationViewerSeeder;
use Database\Seeders\MowahadaViewerSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MowahadaViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_open_pages_and_the_test_chat_only(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(MowahadaViewerSeeder::class);

        $owner = User::query()->where('email', User::MOWAHADA_EMAIL)->firstOrFail();
        $viewer = User::query()->where('email', MowahadaViewerSeeder::EMAIL)->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $owner->id)->where('integration_type', 'mansour')->firstOrFail();

        $this->assertSame(0, ChatbotInstance::query()->where('user_id', $viewer->id)->count());
        $this->assertTrue($viewer->isChatbotOnly());
        $this->assertFalse($viewer->isMowahada());

        $this->actingAs($viewer)
            ->get(route('ai-chatbot.workspace.conversations', $bot))
            ->assertOk();

        $chat = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $owner->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972500000001@c.us',
            'contact_name' => 'تجربة',
            'contact_phone' => '972500000001',
            'title' => 'تجربة',
        ]);

        $this->actingAs($viewer)
            ->get(route('ai-chatbot.workspace.conversations.show', [$bot, $chat]))
            ->assertOk()
            ->assertSee('xl:grid-cols-[440px_minmax(0,1fr)_250px]', false)
            ->assertDontSee('xl:grid-cols-[420px_minmax(0,1fr)]"', false);

        $this->actingAs($viewer)
            ->get(route('ai-chatbot.workspace.test.page', $bot))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('ai-chatbot.farz.dashboard'))
            ->assertOk();

        $this->actingAs($viewer)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), [
                'name' => 'تغير',
                'system_prompt' => 'لا',
                'opening_message' => 'لا',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [1],
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson(route('ai-chatbot.workspace.conversations.reply', [$bot, $chat]), [
                'message' => 'مرحبا',
            ])
            ->assertForbidden();

        $this->assertSame($bot->name, $bot->fresh()->name);
    }

    public function test_a_mowahada_viewer_sees_the_invite_bot(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(MowahadaViewerSeeder::class);

        $owner = User::query()->where('email', User::MOWAHADA_EMAIL)->firstOrFail();
        $viewer = User::query()->where('email', MowahadaViewerSeeder::EMAIL)->firstOrFail();
        $invite = app(MansourBotService::class)->ensureInviteBot($owner);
        $this->assertInstanceOf(ChatbotInstance::class, $invite);

        $this->actingAs($viewer)
            ->get(route('ai-chatbot.farz.dashboard'))
            ->assertOk()
            ->assertSee('دعوه نسائيه')
            ->assertSee('data-farz-open="farz-bot-'.$invite->id.'"', false);

        $this->actingAs($viewer)
            ->get(route('ai-chatbot.workspace.conversations', $invite))
            ->assertOk();

        $this->actingAs($viewer)
            ->post(route('ai-chatbot.workspace.mansour.trigger', $invite), [
                'contact_ids' => [1],
            ])
            ->assertForbidden();
    }

    public function test_invitation_user_can_only_view_the_two_invite_bots(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $owner = User::query()->where('email', User::MOWAHADA_EMAIL)->firstOrFail();
        $other = ChatbotInstance::factory()->create([
            'user_id' => $owner->id,
            'integration_type' => 'mansour',
            'integration_settings' => ['role' => 'dima'],
        ]);

        $this->seed(InvitationViewerSeeder::class);

        $viewer = User::query()->where('email', InvitationViewerSeeder::EMAIL)->firstOrFail();
        $this->assertSame('invitation', $viewer->name);
        $this->assertTrue($viewer->isChatbotOnly());
        $this->assertSame(0, ChatbotInstance::query()->where('user_id', $viewer->id)->count());

        $grants = ChatbotInstanceUser::query()->where('user_id', $viewer->id)->get();
        $this->assertCount(2, $grants);
        $this->assertFalse($grants->contains(fn (ChatbotInstanceUser $row): bool => (int) $row->instance_id === (int) $other->id));

        $authz = app(ChatbotAuthorizationService::class);
        $this->assertFalse($authz->can($viewer, $other, ChatbotAuthorizationService::ABILITY_VIEW));
        foreach ($grants as $grant) {
            $this->assertSame(ChatbotInstanceUser::ROLE_VIEWER, $grant->role);
            $bot = ChatbotInstance::query()->findOrFail($grant->instance_id);
            $this->assertTrue($bot->isInviteBot());
            $this->assertTrue($authz->can($viewer, $bot, ChatbotAuthorizationService::ABILITY_VIEW));
            $this->assertFalse($authz->can($viewer, $bot, ChatbotAuthorizationService::ABILITY_REPLY));
            $this->assertFalse($authz->can($viewer, $bot, ChatbotAuthorizationService::ABILITY_CONTROL_BOT));
        }
    }
}
