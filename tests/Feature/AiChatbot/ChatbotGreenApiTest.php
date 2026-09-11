<?php

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotGreenApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    public function test_instance_settings_show_greenapi_webhook_url(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'greenapi_webhook_token' => 'test-webhook-token-123',
        ]);

        $response = $this->actingAs($user)
            ->get(route('ai-chatbot.instances.edit', $instance));

        $response->assertOk();
        $response->assertSee(route('ai-chatbot.greenapi.webhook', ['token' => 'test-webhook-token-123']), false);
    }

    public function test_instance_settings_can_save_greenapi_url(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create(['user_id' => $user->id]);

        $sendUrl = 'https://7107.api.greenapi.com/waInstance123/sendMessage/abc123';

        $this->actingAs($user)
            ->put(route('ai-chatbot.instances.update', $instance), [
                'name' => $instance->name,
                'system_prompt' => $instance->system_prompt,
                'greenapi_url' => $sendUrl,
            ])
            ->assertRedirect(route('ai-chatbot.instances.show', $instance));

        $instance->refresh();
        $this->assertSame($sendUrl, $instance->greenapi_url);
    }

    public function test_new_instance_autogenerates_webhook_token(): void
    {
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
        ]);

        $this->assertNotNull($instance->greenapi_webhook_token);
        $this->assertGreaterThan(20, strlen((string) $instance->greenapi_webhook_token));
    }

    public function test_webhook_returns_404_for_unknown_token(): void
    {
        $this->postJson('/ai-chatbot/webhook/greenapi/unknown-token')
            ->assertNotFound();
    }

    public function test_webhook_ignores_non_incoming_events(): void
    {
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'greenapi_webhook_token' => 'token-abc',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance1/sendMessage/key1',
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-abc']), [
            'typeWebhook' => 'outgoingMessageStatus',
        ])
            ->assertOk()
            ->assertJsonPath('ignored', true);
    }

    public function test_webhook_processes_incoming_message_and_replies_via_green_api(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'greenapi_webhook_token' => 'token-reply',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hello from Sally']]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-1'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-reply']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972501234567@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'مرحبا'],
                'idMessage' => 'msg-1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'Hello from Sally');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendTyping/')
                && $request['chatId'] === '972501234567@c.us'
                && ($request['typingTime'] ?? null) === 18000;
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret'
                && $request['chatId'] === '972501234567@c.us'
                && $request['message'] === 'Hello from Sally'
                && isset($request['typingTime'])
                && $request['typingTime'] >= 1000
                && $request['typingTime'] <= 20000;
        });
    }

    public function test_webhook_skips_auto_reply_when_sender_not_in_allowlist(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'greenapi_webhook_token' => 'token-allowlist',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'integration_settings' => [
                'allowed_reply_phones' => ['0533046830', '0524060606'],
            ],
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Should not send']]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-blocked'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-allowlist']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972501111111@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'مرحبا'],
                'idMessage' => 'msg-blocked',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored_without_reply', true)
            ->assertJsonPath('sender_allowed', false)
            ->assertJsonPath('reply', null);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'greenapi.com'));
    }

    public function test_webhook_skips_auto_reply_when_sender_in_ignore_list(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'greenapi_webhook_token' => 'token-ignore',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'integration_settings' => [
                'ignored_reply_phones' => ['0533046830'],
            ],
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Should not send']]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-ignored'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-ignore']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972533046830@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'مرحبا'],
                'idMessage' => 'msg-ignored',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stored_without_reply', true)
            ->assertJsonPath('sender_ignored', true)
            ->assertJsonPath('reply', null);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'greenapi.com'));
    }

    public function test_webhook_replies_when_sender_matches_allowlist_local_format(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'greenapi_webhook_token' => 'token-allow-ok',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'integration_settings' => [
                'allowed_reply_phones' => ['0533046830'],
            ],
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Allowed hello']]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-ok'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-allow-ok']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972533046830@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'hi'],
                'idMessage' => 'msg-allowed',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'Allowed hello');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'greenapi.com'));
    }

    public function test_webhook_ignores_messages_sent_before_bot_activation(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'bot_activated_at' => now(),
            'greenapi_webhook_token' => 'token-stale',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Should not send']]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-stale'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-stale']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->subHours(12)->timestamp,
            'senderData' => ['chatId' => '972522569068@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'Hello! Can I get more info on this?'],
                'idMessage' => 'msg-old-hello',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ignored', true)
            ->assertJsonPath('reason', 'Ignored message sent before the bot was activated.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai.com')
            || str_contains($request->url(), 'sendMessage'));
    }

    public function test_webhook_replies_to_messages_sent_after_bot_activation(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'bot_activated_at' => now()->subMinute(),
            'greenapi_webhook_token' => 'token-fresh',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Fresh hello']]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-fresh'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-fresh']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972522569068@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'مرحبا'],
                'idMessage' => 'msg-fresh',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'Fresh hello');
    }

    public function test_activating_bot_records_activation_cutoff(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
            'bot_activated_at' => now()->subDay(),
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        Http::fake([
            '7107.api.greenapi.com/*' => Http::response([], 200),
        ]);

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.bot-active', $instance), [
                'is_active' => '1',
            ])
            ->assertRedirect();

        $instance->refresh();
        $this->assertTrue($instance->is_active);
        $this->assertNotNull($instance->bot_activated_at);
        $this->assertTrue($instance->bot_activated_at->greaterThan(now()->subMinute()));
    }

    public function test_phone_dot_toggles_bot_only_on_that_conversation(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'greenapi_webhook_token' => 'token-dot',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        $paused = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972503207877@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'title' => 'paused chat',
        ]);
        $other = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501111111@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'title' => 'other chat',
        ]);

        Http::fake();

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-dot']), [
            'typeWebhook' => 'outgoingMessageReceived',
            'senderData' => ['chatId' => '972503207877@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => '..'],
                'idMessage' => 'dot-stop-1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('phone_dot_toggle', true)
            ->assertJsonPath('bot_mode', ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER)
            ->assertJsonPath('bot_stopped', true);

        $this->assertSame(ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER, $paused->fresh()->bot_mode);
        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $other->fresh()->bot_mode);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-dot']), [
            'typeWebhook' => 'outgoingMessageReceived',
            'senderData' => ['chatId' => '972503207877@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => '..'],
                'idMessage' => 'dot-resume-1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('bot_mode', ChatbotConversation::BOT_MODE_ACTIVE)
            ->assertJsonPath('bot_stopped', false);

        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $paused->fresh()->bot_mode);
        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $other->fresh()->bot_mode);
    }

    public function test_phone_from_me_incoming_dots_toggle_bot(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'greenapi_webhook_token' => 'token-dot-fromme',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972503207877@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'title' => 'fromMe chat',
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-dot-fromme']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972503207877@c.us', 'fromMe' => true],
            'messageData' => [
                'fromMe' => true,
                'textMessageData' => ['textMessage' => '..'],
                'idMessage' => 'dot-fromme-1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('phone_dot_toggle', true)
            ->assertJsonPath('bot_mode', ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER);

        $this->assertSame(ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER, $conversation->fresh()->bot_mode);
    }

    public function test_customer_incoming_dots_do_not_toggle_bot(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'greenapi_webhook_token' => 'token-dot-in',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hello']]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-dot'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-dot-in']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972509999999@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => '..'],
                'idMessage' => 'customer-dots',
            ],
        ])
            ->assertOk()
            ->assertJsonMissing(['phone_dot_toggle' => true]);

        $conversation = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->where('external_chat_id', '972509999999@c.us')
            ->firstOrFail();

        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $conversation->bot_mode);
    }

    public function test_api_outgoing_dots_do_not_toggle_bot(): void
    {
        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'greenapi_webhook_token' => 'token-dot-api',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972503207877@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'title' => 'api dots',
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-dot-api']), [
            'typeWebhook' => 'outgoingAPIMessageReceived',
            'senderData' => ['chatId' => '972503207877@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => '..'],
                'idMessage' => 'api-dots',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ignored', true);

        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $conversation->fresh()->bot_mode);
    }
}
