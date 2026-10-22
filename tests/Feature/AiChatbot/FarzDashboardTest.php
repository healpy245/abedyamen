<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\AiChatbot\MansourContact;
use App\Models\User;
use App\Services\AiChatbot\AppsPrompt;
use App\Services\AiChatbot\MansourBotService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarzDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_farz_user_lands_on_the_dashboard_with_every_bot(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $first = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $second = app(MansourBotService::class)->copy($first, $user);

        MansourContact::query()->create([
            'instance_id' => $first->id,
            'name' => 'سارة',
            'phone' => '0501111111',
            'phone_normalized' => '972501111111',
            'chat_id' => '972501111111@c.us',
            'triggered_at' => now(),
        ]);

        $voter = $this->conversation($first, '972501111111', 'سارة', [
            ['assistant', 'يسعد اوقاتك اخت سارة شو الاخبار'],
            ['user', 'بصوت للموحدة'],
        ]);
        $this->conversation($second, '972502222222', 'سامر', [
            ['assistant', 'يسعد اوقاتك اخ سامر شو الاخبار'],
        ]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.index'))
            ->assertRedirect(route('ai-chatbot.farz.dashboard'));

        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.dashboard'))
            ->assertOk()
            ->assertSee('لوحة الموحدة')
            ->assertSee('رسائل الذكاء')
            ->assertDontSee('farz-compare', false)
            ->assertSee('ديما')
            ->assertDontSee('farz-tile--locked', false)
            ->assertSee($first->name)
            ->assertSee($second->name)
            ->assertSee('data-farz-open="farz-bot-'.$first->id.'"', false)
            ->assertSee('id="farz-bot-'.$second->id.'"', false)
            ->assertSee(route('ai-chatbot.workspace.analytics.export', [$first, 'certain']), false)
            ->assertSee(route('ai-chatbot.workspace.conversations.show', [$first->id, $voter->id]), false)
            ->assertViewHas('data', function (array $data): bool {
                return $data['totals']['certain'] === 1
                    && $data['totals']['no_reply'] === 1
                    && $data['sent'] === 1
                    && $data['ai_messages'] === 2
                    && $data['replied'] === 1
                    && $data['certain_share'] === 100
                    && count($data['bots']) === 4
                    && array_sum($data['activity']['hours']) === 1;
            });

        $this->actingAs($user)
            ->get(route('ai-chatbot.index', ['list' => 1]))
            ->assertRedirect(route('ai-chatbot.farz.dashboard'));
    }

    public function test_mowahada_cannot_open_kaman_world_or_other_bots(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $owner = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $foreign = ChatbotInstance::query()->create([
            'user_id' => $owner->id,
            'name' => 'Kaman POS — WhatsApp',
            'system_prompt' => 'test',
            'stores_members' => false,
            'integration_type' => 'kaman_whatsapp',
        ]);
        app(\App\Services\AiChatbot\ChatbotAuthorizationService::class)->grantAccess(
            $foreign,
            $user,
            \App\Models\AiChatbot\ChatbotInstanceUser::ROLE_MANAGER,
        );

        $this->actingAs($user)
            ->get(route('ai-chatbot.index'))
            ->assertRedirect(route('ai-chatbot.farz.dashboard'));

        $this->actingAs($user)
            ->get(route('form.index'))
            ->assertRedirect(route('ai-chatbot.farz.dashboard'));

        $this->actingAs($user)
            ->get(route('downloads.kaman-support'))
            ->assertRedirect(route('ai-chatbot.farz.dashboard'));

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $foreign))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.dashboard'))
            ->assertOk()
            ->assertSee('لوحة الموحدة')
            ->assertSee('ديما')
            ->assertDontSee('قريباً', false)
            ->assertDontSee('Kaman POS', false)
            ->assertDontSee('kaman-sidebar', false);
    }

    public function test_farz_bot_pages_use_the_green_layout(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $chat = $this->conversation($bot, '972503333333', 'رامي', [
            ['assistant', 'يسعد اوقاتك اخ رامي شو الاخبار'],
            ['user', 'المشتركة'],
        ]);

        foreach ([
            route('ai-chatbot.workspace.conversations', $bot),
            route('ai-chatbot.workspace.conversations.show', [$bot, $chat]),
            route('ai-chatbot.workspace.test.page', $bot),
            route('ai-chatbot.workspace.settings', $bot),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('farz-theme.css', $html, $url);
            $this->assertSame(1, substr_count($html, 'id="mansour-trigger-open"'), $url);
            $this->assertStringContainsString('id="mansour-trigger-modal"', $html, $url);
            $this->assertStringContainsString('id="mansour-live-stop"', $html, $url);
            $this->assertStringContainsString('id="mansour-live-resume"', $html, $url);
            $this->assertStringContainsString('hideModal', $html, $url);
        }
    }

    public function test_other_bots_keep_the_kaman_layout(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'kaman@kaman.rest')->firstOrFail();
        $bot = ChatbotInstance::query()->create([
            'user_id' => $user->id,
            'name' => 'Kaman POS — WhatsApp',
            'system_prompt' => 'test',
            'stores_members' => false,
            'integration_type' => 'kaman_whatsapp',
        ]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $bot))
            ->assertOk()
            ->assertDontSee('farz-theme.css', false)
            ->assertDontSee('mansour-trigger-open', false);
    }

    public function test_dashboard_counts_both_apps_and_locks_persuasion(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'name' => 'بوت تطبيق أصواتكم',
            'integration_type' => 'mansour',
            'integration_settings' => ['role' => 'apps', 'opening_message' => AppsPrompt::DEFAULT_OPENING],
        ])->save();

        $this->conversation($bot, '972501111111', 'هند', [
            ['assistant', 'عندك تطبيق أصواتكم؟'],
            ['user', 'نعم نزلته'],
            ['assistant', "وبالنسبة لتطبيق «الموحدة»، مطلوب تحميله عن طريق الرابط التالي:\nhttps://example.com/muw"],
        ]);
        $this->conversation($bot, '972502222222', 'سامر', [
            ['assistant', 'عندك تطبيق أصواتكم؟'],
            ['user', 'نعم'],
            ['assistant', 'للدخول للتطبيق انت بحاجة لاسم مستخدم، في معك اسم مستخدم؟'],
            ['user', 'لا'],
        ]);
        $this->conversation($bot, '972503333333', 'رامي', [
            ['assistant', 'عندك تطبيق أصواتكم؟'],
            ['user', 'لا ما بدي'],
            ['assistant', 'سأمرر رقمك ليتواصل معك مندوب من الحزب.'],
        ]);
        $this->conversation($bot, '972504444444', 'ليان', [
            ['assistant', 'عندك تطبيق أصواتكم؟'],
        ]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.dashboard'))
            ->assertOk()
            ->assertSee('نزّلوا')
            ->assertSee('المتفاعلين')
            ->assertSee('بانتظار التسجيل')
            ->assertSee('بانتظار المندوب')
            ->assertSee('تطبيق أصواتكم')
            ->assertSee('تطبيق الموحدة')
            ->assertSee('ديما')
            ->assertSee('مقاطع ديني')
            ->assertDontSee('قريباً', false)
            ->assertDontSee('data-farz-open="farz-bot-persuasion"', false)
            ->assertViewHas('data', function (array $data): bool {
                $apps = $data['apps'];

                return $apps['contacted'] === 4
                    && $apps['aswatkum']['downloaded'] === 2
                    && $apps['aswatkum']['interacted'] === 3
                    && $apps['aswatkum']['waiting_registration'] === 1
                    && $apps['aswatkum']['waiting_delegate'] === 1
                    && $apps['muwahada']['downloaded'] === 1
                    && $apps['muwahada']['interacted'] === 1
                    && $apps['muwahada']['waiting_registration'] === 1
                    && $apps['muwahada']['waiting_delegate'] === 1
                    && count($data['bots']) === 3
                    && collect($data['bots'])->contains(fn (array $row): bool => (bool) ($row['dima'] ?? false));
            });
    }

    public function test_users_without_farz_bots_cannot_open_the_dashboard(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'kaman@kaman.rest')->firstOrFail();

        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.dashboard'))
            ->assertNotFound();
    }

    /**
     * @param  list<array{0:string,1:string}>  $turns
     */
    private function conversation(ChatbotInstance $bot, string $phone, string $name, array $turns): ChatbotConversation
    {
        $conversation = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $bot->user_id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => $phone.'@c.us',
            'contact_name' => $name,
            'contact_phone' => $phone,
            'title' => $name,
        ]);
        foreach ($turns as [$role, $message]) {
            ChatbotMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => $role,
                'message' => $message,
            ]);
        }

        return $conversation;
    }
}
