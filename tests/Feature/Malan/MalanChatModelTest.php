<?php

declare(strict_types=1);

namespace Tests\Feature\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Services\AiChatbot\AiChatbotService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MalanChatModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
        config([
            'services.openai.api_key' => 'test-openai',
            'openai.malan_chat_model' => 'gpt-6-sol',
        ]);
    }

    public function test_base_malan_bot_uses_gpt6_sol_with_tool_compatible_params(): void
    {
        $payload = $this->captureChatPayload($this->malanInstance(), null);

        $this->assertSame('gpt-6-sol', $payload['model'] ?? null);
        $this->assertSame('none', $payload['reasoning_effort'] ?? null);
        $this->assertArrayHasKey('max_completion_tokens', $payload);
        $this->assertArrayNotHasKey('max_tokens', $payload);
        $this->assertNotEmpty($payload['tools'] ?? []);
    }

    public function test_campaign_bot_uses_the_same_malan_model(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance();
        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'title' => 'Campaign',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        $payload = $this->captureChatPayload($instance, (int) $conversation->id);

        $this->assertSame('gpt-6-sol', $payload['model'] ?? null);
        $this->assertSame('none', $payload['reasoning_effort'] ?? null);
        $this->assertSame('create_malan_lead', $payload['tools'][0]['function']['name'] ?? null);
    }

    public function test_speedcom_bot_uses_the_latest_tool_calling_model(): void
    {
        config(['openai.speedcom_chat_model' => 'gpt-6-sol']);

        $instance = ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'integration_type' => 'speedcom',
            'integration_settings' => ['company_slug' => 'speedcom'],
            'system_prompt' => 'أنت ممدوح من سبيدكوم.',
        ]);

        $payload = $this->captureChatPayload($instance, null);

        $this->assertSame('gpt-6-sol', $payload['model'] ?? null);
        $this->assertSame('none', $payload['reasoning_effort'] ?? null);
        $this->assertArrayHasKey('max_completion_tokens', $payload);
        $this->assertNotEmpty($payload['tools'] ?? []);
    }

    public function test_non_malan_bot_keeps_the_settings_model(): void
    {
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'integration_type' => null,
            'system_prompt' => 'Generic assistant.',
        ]);

        $payload = $this->captureChatPayload($instance, null);

        $this->assertSame('gpt-4o-mini', $payload['model'] ?? null);
        $this->assertArrayNotHasKey('reasoning_effort', $payload);
        $this->assertArrayHasKey('max_tokens', $payload);
        $this->assertArrayNotHasKey('tools', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function captureChatPayload(ChatbotInstance $instance, ?int $conversationId): array
    {
        $captured = [];
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$captured) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $captured = $request->data();

                return Http::response([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'تمام.',
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        app(AiChatbotService::class)->sendMessage(
            $this->user(),
            $instance,
            'مرحبا',
            $conversationId,
            ['channel' => ChatbotConversation::CHANNEL_WHATSAPP],
        );

        return $captured;
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function malanInstance(): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'integration_type' => 'malan',
            'system_prompt' => 'You are Sally for Malan.',
        ]);
    }
}
