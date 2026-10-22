<?php

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KamanBotAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_kaman_bot_chats_page_shows_live_analytics_sidebar(): void
    {
        $owner = $this->owner();
        $instance = $this->kamanInstance($owner);
        $this->seedChats($instance);

        $this->actingAs($owner)
            ->get(route('ai-chatbot.workspace.conversations', $instance))
            ->assertOk()
            ->assertSee('id="kaman-analytics-desktop"', false)
            ->assertSee('data-analytics="leads"', false)
            ->assertSee(__('chatbot.workspace.analytics.live'))
            ->assertSee(__('chatbot.workspace.analytics.leads'));

        $this->actingAs($owner)
            ->getJson(route('ai-chatbot.workspace.analytics', $instance))
            ->assertOk()
            ->assertJsonPath('analytics.leads', 1)
            ->assertJsonPath('analytics.no_response', 1)
            ->assertJsonPath('analytics.rejected', 1)
            ->assertJsonPath('analytics.engaged', 1)
            ->assertJsonPath('analytics.messages', 4)
            ->assertJsonPath('analytics.contacts', 5);

        $rejected = ChatbotConversation::query()->where('title', 'rejected')->firstOrFail();

        $this->actingAs($owner)
            ->getJson(route('ai-chatbot.workspace.conversations.poll', [
                'instance' => $instance,
                'insight' => 'rejected',
            ]))
            ->assertOk()
            ->assertJsonPath('insight', 'rejected')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $rejected->id);

        $export = $this->actingAs($owner)
            ->get(route('ai-chatbot.workspace.analytics.export', [$instance, 'rejected']));
        $export->assertOk();
        $this->assertStringContainsString('972509998877', $export->streamedContent());

        $lead = ChatbotConversation::query()->where('title', 'lead')->firstOrFail();
        $this->actingAs($owner)
            ->get(route('ai-chatbot.workspace.conversations.show', [$instance, $lead]))
            ->assertOk()
            ->assertSee('id="kaman-analytics-desktop"', false)
            ->assertSee('id="analytics-downloads-modal"', false);

        $first = $this->actingAs($owner)
            ->get(route('ai-chatbot.workspace.analytics.export', [$instance, 'rejected']).'?save=1');
        $first->assertOk();
        $this->assertStringContainsString('972509998877', $first->streamedContent());
        $this->assertStringContainsString('-1.csv', (string) $first->headers->get('content-disposition'));

        $this->actingAs($owner)
            ->getJson(route('ai-chatbot.workspace.analytics.downloads', [$instance, 'rejected']))
            ->assertOk()
            ->assertJsonPath('downloads.0.sequence', 1)
            ->assertJsonPath('downloads.0.count', 1)
            ->assertJsonPath('next', 2)
            ->assertJsonPath('fresh_count', 0);

        $later = $this->chat($instance, 'rejected-later', '972503333333@c.us', '972503333333');
        $this->message($later, 'assistant', 'مرحبا');
        $this->message($later, 'user', 'ما بدي');

        $history = $this->actingAs($owner)
            ->getJson(route('ai-chatbot.workspace.analytics.downloads', [$instance, 'rejected']))
            ->assertOk()
            ->assertJsonPath('fresh_count', 1)
            ->assertJsonCount(1, 'downloads');

        $second = $this->actingAs($owner)
            ->get(route('ai-chatbot.workspace.analytics.export', [$instance, 'rejected']).'?fresh=1');
        $second->assertOk();
        $secondBody = $second->streamedContent();
        $this->assertStringContainsString('972503333333', $secondBody);
        $this->assertStringNotContainsString('972509998877', $secondBody);
        $this->assertStringContainsString('-2.csv', (string) $second->headers->get('content-disposition'));

        $replay = $this->actingAs($owner)
            ->get(route('ai-chatbot.workspace.analytics.export', [
                $instance,
                'rejected',
                'download' => $history->json('downloads.0.id'),
            ]));
        $replay->assertOk();
        $replayBody = $replay->streamedContent();
        $this->assertStringContainsString('972509998877', $replayBody);
        $this->assertStringNotContainsString('972503333333', $replayBody);
    }

    public function test_positive_long_chats_count_as_stored_leads_without_sending(): void
    {
        $owner = $this->owner();
        $instance = $this->kamanInstance($owner);

        $long = $this->chat($instance, 'long-positive', '972506666666@c.us', '972506666666');
        foreach ([
            'مرحبا',
            'عندي مطعم برجر في حيفا',
            'بدي نظام كاشير',
            'قديش السعر؟',
            'تمام بدي مندوب يجي يشرح',
            '0526666666',
        ] as $text) {
            $this->message($long, 'user', $text);
        }
        $this->message($long, 'assistant', 'اهلا');

        $job = $this->chat($instance, 'job', '972507777777@c.us', '972507777777');
        foreach (['مرحبا', 'بدور على شغيلة', 'في شغل؟', 'تمام', 'شكرا', 'بكرا'] as $text) {
            $this->message($job, 'user', $text);
        }

        $support = $this->chat($instance, 'support', '972508888888@c.us', '972508888888');
        foreach ([
            'مرحبا',
            'احنا مشتركين معكم',
            'الطابعة مش شغالة',
            'في ناقص ورق',
            'من متى هيك',
            'بدي اصلحها',
        ] as $text) {
            $this->message($support, 'user', $text);
        }

        $short = $this->chat($instance, 'short', '972509999999@c.us', '972509999999');
        $this->message($short, 'user', 'كم السعر؟');

        $this->actingAs($owner)
            ->getJson(route('ai-chatbot.workspace.analytics', $instance))
            ->assertOk()
            ->assertJsonPath('analytics.leads', 1)
            ->assertJsonPath('analytics.engaged', 3)
            ->assertJsonPath('analytics.rejected', 0)
            ->assertJsonPath('analytics.contacts', 4);

        $leadIds = $this->actingAs($owner)
            ->getJson(route('ai-chatbot.workspace.analytics', $instance))
            ->json('analytics.conversation_ids.leads');
        $this->assertSame([$long->id], $leadIds);
    }

    public function test_other_bots_do_not_get_the_kaman_analytics_sidebar(): void
    {
        $owner = $this->owner();
        $malan = ChatbotInstance::factory()->create([
            'user_id' => $owner->id,
            'integration_type' => 'malan',
            'name' => 'Malan bot',
        ]);

        $this->actingAs($owner)
            ->get(route('ai-chatbot.workspace.conversations', $malan))
            ->assertOk()
            ->assertDontSee('id="kaman-analytics-desktop"', false);

        $this->actingAs($owner)
            ->getJson(route('ai-chatbot.workspace.analytics', $malan))
            ->assertNotFound();
    }

    private function owner(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function kamanInstance(User $owner): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Kaman WhatsApp',
            'integration_type' => 'kaman_whatsapp',
            'is_active' => true,
        ]);
    }

    private function seedChats(ChatbotInstance $instance): void
    {
        $lead = $this->chat($instance, 'lead', '972501111111@c.us', '972501111111', [
            'kaman_agents_lead' => ['posted_at' => now()->toIso8601String()],
        ]);
        $this->message($lead, 'assistant', 'مرحبا');
        $this->message($lead, 'user', 'بدي نظام');

        $silent = $this->chat($instance, 'silent', '972502222222@c.us', '972502222222');
        $this->message($silent, 'assistant', 'مرحبا');

        $rejected = $this->chat($instance, 'rejected', '972509998877@c.us', '972509998877');
        $this->message($rejected, 'assistant', 'مرحبا');
        $this->message($rejected, 'user', 'ما بدي');

        $engaged = $this->chat($instance, 'engaged', '972504444444@c.us', '972504444444');
        $this->message($engaged, 'assistant', 'مرحبا');
        $this->message($engaged, 'user', 'كم السعر؟');

        $this->chat($instance, 'empty', '972505555555@c.us', '972505555555');

        $group = $this->chat($instance, 'group', '120363@g.us', null);
        $this->message($group, 'user', 'مجموعة');

        $test = $this->chat($instance, 'sandbox', 'test-chat', '972500000000', [], ChatbotConversation::CHANNEL_TEST);
        $this->message($test, 'user', 'تجربة');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function chat(
        ChatbotInstance $instance,
        string $title,
        string $externalChatId,
        ?string $phone,
        array $metadata = [],
        string $channel = ChatbotConversation::CHANNEL_WHATSAPP,
    ): ChatbotConversation {
        return ChatbotConversation::query()->create([
            'user_id' => $instance->user_id,
            'instance_id' => $instance->id,
            'title' => $title,
            'channel' => $channel,
            'external_chat_id' => $externalChatId,
            'contact_phone' => $phone,
            'contact_name' => $title,
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => $metadata,
        ]);
    }

    private function message(ChatbotConversation $conversation, string $role, string $text): void
    {
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => $role,
            'message' => $text,
        ]);
    }
}
