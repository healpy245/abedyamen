<?php

namespace Tests\Feature\AiChatbot;

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
            return $request->url() === 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret'
                && $request['chatId'] === '972501234567@c.us'
                && $request['message'] === 'Hello from Sally';
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
}
