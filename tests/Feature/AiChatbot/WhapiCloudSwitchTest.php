<?php

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotSetting;
use App\Models\AiChatbot\MansourContact;
use App\Models\User;
use App\Jobs\ProcessMansourConversationJob;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\MansourBotService;
use App\Services\AiChatbot\MansourConversationBurstService;
use App\Services\AiChatbot\WhapiCloudClient;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhapiCloudSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);
        config([
            'services.openai.api_key' => 'test-key',
            'whapi.provider' => 'whapi',
            'whapi.token' => 'test-whapi-token',
            'whapi.channel_id' => 'HULKBR-69QST',
            'whapi.base_url' => 'https://gate.whapi.cloud',
        ]);
    }

    public function test_whapi_webhook_replies_through_the_channel_for_every_instance(): void
    {
        $instance = $this->makeInstance('token-whapi');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Reply via Whapi']]],
            ]),
            'gate.whapi.cloud/*' => Http::response([
                'sent' => true,
                'message' => ['id' => 'whapi-sent-1'],
            ], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-whapi']), [
            'messages' => [[
                'id' => 'in-1',
                'from_me' => false,
                'type' => 'text',
                'chat_id' => '972501234567@s.whatsapp.net',
                'timestamp' => time(),
                'source' => 'mobile',
                'text' => ['body' => 'مرحبا'],
                'from_name' => 'Nour',
            ]],
            'event' => ['type' => 'messages', 'event' => 'post'],
            'channel_id' => 'HULKBR-69QST',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'Reply via Whapi');

        $this->assertSame(
            '972501234567@c.us',
            ChatbotConversation::query()->where('instance_id', $instance->id)->value('external_chat_id'),
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://gate.whapi.cloud/messages/text'
                && $request->hasHeader('Authorization', 'Bearer test-whapi-token')
                && $request['to'] === '972501234567'
                && $request['body'] === 'Reply via Whapi'
                && isset($request['typing_time']);
        });

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'greenapi.com'));
    }

    public function test_shared_webhook_continues_the_existing_chat_instance(): void
    {
        $user = $this->user();
        $older = $this->makeInstance('older-token', $user);
        $current = $this->makeInstance('current-token', $user);
        $older->forceFill(['updated_at' => now()->subDay()])->save();

        ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $older->id,
            'title' => 'Existing',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501234567@c.us',
            'contact_phone' => '972501234567',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Still on the first bot']]],
            ]),
            'gate.whapi.cloud/*' => Http::response([
                'sent' => true,
                'message' => ['id' => 'whapi-sent-2'],
            ], 200),
        ]);

        $this->postJson(route('ai-chatbot.whapi.webhook', [
            'token' => app(WhapiCloudClient::class)->webhookToken(),
        ]), $this->textPayload('972501234567@s.whatsapp.net', 'again'))
            ->assertOk()
            ->assertJsonPath('reply', 'Still on the first bot');

        $this->assertSame(1, ChatbotConversation::query()->where('instance_id', $older->id)->count());
        $this->assertSame(0, ChatbotConversation::query()->where('instance_id', $current->id)->count());
    }

    public function test_api_echo_and_other_channels_are_ignored(): void
    {
        $this->makeInstance('token-ignore');

        Http::fake([
            'gate.whapi.cloud/*' => Http::response(['sent' => true, 'message' => ['id' => 'nope']], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-ignore']), [
            'messages' => [[
                'id' => 'echo-1',
                'from_me' => true,
                'type' => 'text',
                'chat_id' => '972501234567@s.whatsapp.net',
                'timestamp' => time(),
                'source' => 'api',
                'text' => ['body' => 'Reply via Whapi'],
            ]],
            'event' => ['type' => 'messages', 'event' => 'post'],
            'channel_id' => 'HULKBR-69QST',
        ])
            ->assertOk()
            ->assertJsonPath('ignored', true);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'token-ignore']), $this->textPayload('972509999999@s.whatsapp.net', 'foreign', 'OTHER-CHANNEL'))
            ->assertOk()
            ->assertJsonPath('ignored', true);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages/text'));
        $this->assertSame(0, ChatbotConversation::query()->count());
    }

    public function test_settings_switch_requires_a_token_and_stores_it_for_all_instances(): void
    {
        config(['whapi.provider' => null, 'whapi.token' => null]);

        $user = $this->user();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
        ]);

        $this->actingAs($user)
            ->put(route('ai-chatbot.instances.update', $instance), [
                'name' => $instance->name,
                'system_prompt' => $instance->system_prompt,
                'greenapi_url' => $instance->greenapi_url,
                'whatsapp_provider' => 'whapi',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('whapi_token');

        $this->assertNull(ChatbotSetting::query()->where('key', 'whatsapp_provider')->value('value'));

        Http::fake([
            'gate.whapi.cloud/settings' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($user)
            ->put(route('ai-chatbot.instances.update', $instance), [
                'name' => $instance->name,
                'system_prompt' => $instance->system_prompt,
                'greenapi_url' => $instance->greenapi_url,
                'whatsapp_provider' => 'whapi',
                'whapi_token' => 'saved-channel-token',
            ])
            ->assertRedirect();

        $this->assertSame('whapi', ChatbotSetting::query()->where('key', 'whatsapp_provider')->value('value'));
        $this->assertSame('saved-channel-token', ChatbotSetting::query()->where('key', 'whapi_token')->value('value'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://gate.whapi.cloud/settings'
                && $request->method() === 'PATCH'
                && ($request['webhooks'][0]['url'] ?? '') === app(WhapiCloudClient::class)->webhookUrl();
        });
    }

    public function test_apps_bot_uses_whapi_without_a_green_api_link(): void
    {
        $other = $this->makeInstance('token-other');
        $other->forceFill(['updated_at' => now()])->save();

        $apps = ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'name' => 'بوت التطبيق',
            'integration_type' => 'mansour',
            'integration_settings' => [
                'role' => 'apps',
                'opening_message' => 'مرحبا من التطبيق',
            ],
            'greenapi_url' => null,
            'greenapi_webhook_token' => 'token-apps',
            'is_active' => true,
            'system_prompt' => 'apps',
        ]);

        $bound = app(WhapiCloudClient::class)->bindAppsBot();
        $this->assertSame($apps->id, $bound?->id);

        Http::fake([
            'gate.whapi.cloud/*' => Http::response([
                'sent' => true,
                'message' => ['id' => 'whapi-open-1'],
            ], 200),
        ]);

        $contact = MansourContact::query()->create([
            'instance_id' => $apps->id,
            'name' => 'نور',
            'phone' => '972501111111',
            'phone_normalized' => '972501111111',
            'chat_id' => '972501111111@c.us',
        ]);

        $result = app(MansourBotService::class)->trigger($apps, [(int) $contact->id]);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://gate.whapi.cloud/messages/text'
                && $request->hasHeader('Authorization', 'Bearer test-whapi-token')
                && $request['to'] === '972501111111'
                && ! array_key_exists('typing_time', $request->data());
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'greenapi.com'));

        $this->postJson(route('ai-chatbot.whapi.webhook', [
            'token' => app(WhapiCloudClient::class)->webhookToken(),
        ]), $this->textPayload('972509999999@s.whatsapp.net', 'مرحبا'))
            ->assertOk()
            ->assertJsonPath('ignored', true)
            ->assertJsonPath('reason', 'Ignored WhatsApp sender that was not triggered.');

        $this->assertSame(0, ChatbotConversation::query()->where('instance_id', $other->id)->count());
        $this->assertSame(1, ChatbotConversation::query()->where('instance_id', $apps->id)->count());
    }

    public function test_apps_reply_is_sent_when_there_is_no_green_api_link(): void
    {
        Http::fake([
            'gate.whapi.cloud/*' => Http::response([
                'sent' => true,
                'message' => ['id' => 'whapi-reply-1'],
            ], 200),
        ]);

        ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'name' => 'بوت التطبيق',
            'integration_type' => 'mansour',
            'integration_settings' => [
                'role' => 'apps',
                'opening_message' => 'مرحبا من التطبيق',
            ],
            'greenapi_url' => null,
            'greenapi_webhook_token' => 'token-apps-reply',
            'is_active' => true,
            'system_prompt' => 'apps',
        ]);

        MansourContact::query()->create([
            'instance_id' => ChatbotInstance::query()->where('greenapi_webhook_token', 'token-apps-reply')->value('id'),
            'name' => 'نور',
            'phone' => '972501111111',
            'phone_normalized' => '972501111111',
            'chat_id' => '972501111111@c.us',
            'triggered_at' => now(),
        ]);

        $response = $this->postJson(
            route('ai-chatbot.greenapi.webhook', ['token' => 'token-apps-reply']),
            $this->textPayload('972501111111@s.whatsapp.net', 'نعم'),
        )->assertOk()->assertJsonPath('listening', true);

        (new ProcessMansourConversationJob(
            (int) $response->json('instance_id'),
            (int) $response->json('conversation_id'),
            (int) $response->json('conversation_version'),
            (string) $response->json('chatId'),
        ))->handle(
            app(AiChatbotService::class),
            app(ChatbotGreenApiService::class),
            app(MansourConversationBurstService::class),
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://gate.whapi.cloud/messages/text'
                && $request['to'] === '972501111111'
                && trim((string) ($request['body'] ?? '')) !== '';
        });
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function makeInstance(string $webhookToken, ?User $user = null): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => ($user ?? $this->user())->id,
            'greenapi_webhook_token' => $webhookToken,
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(string $chatId, string $body, string $channelId = 'HULKBR-69QST'): array
    {
        return [
            'messages' => [[
                'id' => 'in-'.md5($chatId.$body),
                'from_me' => false,
                'type' => 'text',
                'chat_id' => $chatId,
                'timestamp' => time(),
                'source' => 'mobile',
                'text' => ['body' => $body],
            ]],
            'event' => ['type' => 'messages', 'event' => 'post'],
            'channel_id' => $channelId,
        ];
    }
}
