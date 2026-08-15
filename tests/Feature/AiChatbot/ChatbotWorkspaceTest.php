<?php

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationInstruction;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\PromptCompiler;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatbotWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
        config(['services.openai.api_key' => 'test-key']);
    }

    private function owner(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function agentUser(): User
    {
        return User::factory()->create([
            'is_admin' => false,
            'projects' => ['ai-chatbot'],
        ]);
    }

    private function malanInstance(User $owner): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $owner->id,
            'name' => 'سالي — ملان انترنت',
            'integration_type' => 'malan',
            'system_prompt' => 'You are Sally.',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance1/sendMessage/key1',
            'greenapi_webhook_token' => 'workspace-token',
            'is_active' => true,
        ]);
    }

    public function test_malan_user_sees_only_authorized_instances(): void
    {
        $owner = $this->owner();
        $malan = $this->malanInstance($owner);
        $other = ChatbotInstance::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Other Bot',
            'integration_type' => 'kaman_whatsapp',
        ]);

        $agent = $this->agentUser();
        app(ChatbotAuthorizationService::class)->grantAccess($malan, $agent, ChatbotInstanceUser::ROLE_AGENT);

        $authz = app(ChatbotAuthorizationService::class);
        $ids = $authz->instancesForUser($agent)->pluck('id')->all();

        $this->assertContains($malan->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_user_cannot_access_another_instance_by_url(): void
    {
        $owner = $this->owner();
        $malan = $this->malanInstance($owner);
        $other = ChatbotInstance::factory()->create([
            'user_id' => $owner->id,
            'integration_type' => 'kaman_whatsapp',
        ]);

        $agent = $this->agentUser();
        app(ChatbotAuthorizationService::class)->grantAccess($malan, $agent, ChatbotInstanceUser::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('ai-chatbot.workspace.conversations', $other))
            ->assertForbidden();
    }

    public function test_whatsapp_external_chat_id_maps_permanently(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'أهلا']]]], 200),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'out-1'], 200),
        ]);

        $instance = $this->malanInstance($this->owner());
        $chatId = '972501111111@c.us';

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'workspace-token']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => $chatId, 'senderName' => 'Ahmad'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'مرحبا'],
                'idMessage' => 'msg-a',
            ],
        ])->assertOk();

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'workspace-token']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => $chatId, 'senderName' => 'Ahmad'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'ثاني'],
                'idMessage' => 'msg-b',
            ],
        ])->assertOk();

        $conversations = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->where('channel', 'whatsapp')
            ->where('external_chat_id', $chatId)
            ->get();

        $this->assertCount(1, $conversations);
        $this->assertSame('Ahmad', $conversations->first()->contact_name);
        $this->assertSame('972501111111', $conversations->first()->contact_phone);
    }

    public function test_duplicate_webhooks_do_not_create_duplicate_messages(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'out-1'], 200),
        ]);

        $instance = $this->malanInstance($this->owner());
        $payload = [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972502222222@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'مرة'],
                'idMessage' => 'dup-1',
            ],
        ];

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'workspace-token']), $payload)->assertOk();
        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'workspace-token']), $payload)
            ->assertOk()
            ->assertJsonPath('ignored', true);

        $this->assertSame(1, ChatbotMessage::query()->where('external_message_id', 'dup-1')->count());
        $this->assertSame(1, ChatbotConversation::query()->where('instance_id', $instance->id)->where('channel', 'whatsapp')->count());
    }

    public function test_global_bot_deactivation_stores_without_ai_reply(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'SHOULD NOT']]]], 200),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'out-1'], 200),
        ]);

        $instance = $this->malanInstance($this->owner());
        $instance->update([
            'is_active' => false,
            'disabled_message' => 'البوت متوقف مؤقتاً',
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'workspace-token']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972503333333@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'مساعدة'],
                'idMessage' => 'off-1',
            ],
        ])->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));

        $conversation = ChatbotConversation::query()->where('external_chat_id', '972503333333@c.us')->first();
        $this->assertNotNull($conversation);
        $this->assertTrue($conversation->messages()->where('role', 'user')->exists());
        $this->assertTrue($conversation->messages()->where('message', 'البوت متوقف مؤقتاً')->exists());
    }

    public function test_paused_and_human_takeover_store_without_ai(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'NO']]]], 200),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'out-1'], 200),
        ]);

        $instance = $this->malanInstance($this->owner());

        foreach ([
            ['mode' => 'paused', 'chat' => '972504444444@c.us', 'msg' => 'pause-1'],
            ['mode' => 'human_takeover', 'chat' => '972505555555@c.us', 'msg' => 'human-1'],
        ] as $case) {
            $conversation = ChatbotConversation::query()->create([
                'user_id' => $instance->user_id,
                'instance_id' => $instance->id,
                'channel' => 'whatsapp',
                'external_chat_id' => $case['chat'],
                'bot_mode' => $case['mode'],
            ]);

            $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'workspace-token']), [
                'typeWebhook' => 'incomingMessageReceived',
                'senderData' => ['chatId' => $case['chat']],
                'messageData' => [
                    'textMessageData' => ['textMessage' => 'hello'],
                    'idMessage' => $case['msg'],
                ],
            ])->assertOk()->assertJsonPath('stored_without_reply', true);

            $this->assertSame(0, $conversation->messages()->where('role', 'assistant')->where('reply_source', 'ai')->count());
            $this->assertTrue($conversation->messages()->where('role', 'user')->exists());
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_manual_staff_reply_persists_and_sends_greenapi(): void
    {
        Http::fake([
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'staff-out'], 200),
        ]);

        $owner = $this->owner();
        $instance = $this->malanInstance($owner);
        $conversation = ChatbotConversation::query()->create([
            'user_id' => $owner->id,
            'instance_id' => $instance->id,
            'channel' => 'whatsapp',
            'external_chat_id' => '972506666666@c.us',
            'contact_phone' => '972506666666',
            'bot_mode' => 'human_takeover',
        ]);

        $this->actingAs($owner)
            ->postJson(route('ai-chatbot.workspace.conversations.reply', [$instance, $conversation]), [
                'message' => 'مرحبا من الموظف',
            ])
            ->assertOk();

        $message = $conversation->messages()->latest('id')->first();
        $this->assertSame('assistant', $message->role);
        $this->assertSame('human', $message->sender_type);
        $this->assertSame('human', $message->reply_source);
        $this->assertSame($owner->id, $message->sent_by_user_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request['message'] ?? null) === 'مرحبا من الموظف');
    }

    public function test_viewer_cannot_send_messages(): void
    {
        $owner = $this->owner();
        $instance = $this->malanInstance($owner);
        $viewer = $this->agentUser();
        app(ChatbotAuthorizationService::class)->grantAccess($instance, $viewer, ChatbotInstanceUser::ROLE_VIEWER);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $owner->id,
            'instance_id' => $instance->id,
            'channel' => 'whatsapp',
            'external_chat_id' => '972507777777@c.us',
        ]);

        $this->actingAs($viewer)
            ->postJson(route('ai-chatbot.workspace.conversations.reply', [$instance, $conversation]), [
                'message' => 'should fail',
            ])
            ->assertForbidden();
    }

    public function test_structured_settings_compile_and_preserve_system_prompt_fallback(): void
    {
        $compiler = app(PromptCompiler::class);
        $sections = $compiler->normalize([
            'identity' => [
                'bot_name' => 'سالي',
                'company_name' => 'ملان',
                'role' => 'دعم فني',
                'languages' => ['ar', 'he'],
            ],
            'business' => [
                'description' => 'إنترنت منزلي',
            ],
        ]);

        $compiled = $compiler->compile($sections);
        $this->assertStringContainsString('سالي', $compiled);
        $this->assertStringContainsString('ملان', $compiled);
        $this->assertStringContainsString('إنترنت منزلي', $compiled);

        $fallback = $compiler->compile($compiler->normalize([]), 'Legacy prompt remains');
        $this->assertSame('Legacy prompt remains', $fallback);

        $owner = $this->owner();
        $instance = $this->malanInstance($owner);
        $legacy = $instance->system_prompt;

        $this->actingAs($owner)
            ->put(route('ai-chatbot.workspace.settings.update', $instance), [
                'name' => $instance->name,
                'is_active' => 1,
                'prompt_sections' => $sections,
            ])
            ->assertRedirect();

        $instance->refresh();
        $this->assertNotSame($legacy, $instance->system_prompt);
        $this->assertStringContainsString('سالي', $instance->system_prompt);
        $this->assertSame(1, $instance->settings_schema_version);
    }

    public function test_test_form_never_sends_greenapi_or_mutates_malan(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'محاكاة فقط']]],
            ], 200),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'should-not'], 200),
            'www.malan.app/*' => Http::response([[
                'result' => true,
                'data' => [
                    'client' => [
                        'id' => '1',
                        'client_name' => 'Test',
                        'client_phone' => '0599999999',
                        'status' => 'ACTIVE',
                    ],
                    'financial_summary' => ['balance' => 0],
                ],
            ]], 200),
        ]);

        $owner = $this->owner();
        $instance = $this->malanInstance($owner);

        $this->actingAs($owner)
            ->postJson(route('ai-chatbot.workspace.test', $instance), [
                'message' => 'بدي أبلغ عن عطل',
                'channel' => 'whatsapp',
                'phone' => '0599999999',
            ])
            ->assertOk()
            ->assertJsonPath('simulation', true)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assistant_response', 'محاكاة فقط');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'greenapi.com'));

        $this->assertDatabaseCount('malan_support_reports', 0);
        $this->assertTrue(
            ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->where('channel', 'test')
                ->exists()
        );
        $this->assertSame(
            0,
            ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->customerFacing()
                ->where('channel', 'test')
                ->count()
        );
    }

    public function test_sandbox_reset_reuses_existing_test_conversation(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'أهلاً']]]], 200)
                ->push(['choices' => [['message' => ['content' => 'مرحبا من جديد']]]], 200),
        ]);

        $owner = $this->owner();
        $instance = $this->malanInstance($owner);

        $first = $this->actingAs($owner)
            ->postJson(route('ai-chatbot.workspace.test', $instance), [
                'message' => 'مرحبا',
                'channel' => 'test',
                'phone' => '0533046830',
                'reset' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json();

        $conversationId = (int) $first['conversation_id'];
        $this->assertDatabaseHas('ai_chatbot_conversations', [
            'id' => $conversationId,
            'instance_id' => $instance->id,
            'channel' => 'test',
            'external_chat_id' => '972533046830@c.us',
        ]);
        $this->assertSame(1, ChatbotConversation::query()->where('instance_id', $instance->id)->where('channel', 'test')->count());

        $second = $this->actingAs($owner)
            ->postJson(route('ai-chatbot.workspace.test', $instance), [
                'message' => 'مرحبا مرة ثانية',
                'channel' => 'test',
                'phone' => '0533046830',
                'reset' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('conversation_id', $conversationId)
            ->json();

        $this->assertSame(1, ChatbotConversation::query()->where('instance_id', $instance->id)->where('channel', 'test')->count());
        $this->assertSame(
            2,
            ChatbotMessage::query()->where('conversation_id', $conversationId)->count(),
            'Fresh reset should wipe prior turns, then store the new user+assistant pair only.'
        );
        $this->assertSame('مرحبا مرة ثانية', $second['user_message']);
        $this->assertSame('مرحبا من جديد', $second['assistant_response']);
    }

    public function test_sandbox_can_upload_image_into_test_conversation(): void
    {
        Storage::fake('local');

        $owner = $this->owner();
        $instance = $this->malanInstance($owner);

        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBAQEBAVFRUVFRUVFRUVFRUWFxUVFRUYHSggGBolGxUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAADBAECBQYAB//EADkQAAIBAgQDBgUEAwEBAAAAAAECAwQRAAUSITFBBhMiUWEycYGRobHB0fAVQlLhFjNikqLx/8QAGQEAAwEBAQAAAAAAAAAAAAAAAAECAwQF/8QAIxEAAgICAgMAAwEAAAAAAAAAAAECEQMhEjFBBQYiQRNh/9oADAMBAAIRAxEAPwD1SgAoAKACgD//2Q=='
        );
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('receipt.jpg', $bytes ?: 'fake-jpeg');

        $response = $this->actingAs($owner)
            ->post(route('ai-chatbot.workspace.test.image', $instance), [
                'image' => $file,
                'caption' => 'صورة التحويل',
                'channel' => 'test',
                'phone' => '0533046830',
                'reset' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('simulation', true)
            ->json();

        $conversationId = (int) $response['conversation_id'];
        $this->assertDatabaseHas('ai_chatbot_conversations', [
            'id' => $conversationId,
            'instance_id' => $instance->id,
            'channel' => 'test',
            'external_chat_id' => '972533046830@c.us',
        ]);
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversationId,
            'role' => 'user',
            'message' => 'صورة التحويل',
        ]);
        $this->assertNotEmpty($response['messages'][0]['attachment_url'] ?? null);
        $this->assertTrue($response['messages'][0]['is_image'] ?? false);
    }

    public function test_next_reply_instruction_affects_exactly_one_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'رد قصير']]]], 200),
        ]);

        $owner = $this->owner();
        $instance = $this->malanInstance($owner);
        $conversation = ChatbotConversation::query()->create([
            'user_id' => $owner->id,
            'instance_id' => $instance->id,
            'channel' => 'web',
        ]);

        $instruction = ChatbotConversationInstruction::query()->create([
            'conversation_id' => $conversation->id,
            'created_by' => $owner->id,
            'instruction' => 'Keep the next reply short.',
            'scope' => 'next_reply',
            'priority' => 100,
            'is_active' => true,
        ]);

        $service = app(\App\Services\AiChatbot\AiChatbotService::class);
        $first = $service->sendMessage($owner, $instance, 'مرحبا', $conversation->id);
        $this->assertSame('ai_instructed', $first['assistant_message']->reply_source);
        $this->assertContains($instruction->id, $first['assistant_message']->metadata['instruction_ids'] ?? []);

        $instruction->refresh();
        $this->assertFalse($instruction->is_active);

        $second = $service->sendMessage($owner, $instance, 'كمان سؤال', $conversation->id);
        $this->assertSame('ai', $second['assistant_message']->reply_source);
    }

    public function test_reply_count_decrements_and_expired_ignored(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
        ]);

        $owner = $this->owner();
        $instance = $this->malanInstance($owner);
        $conversation = ChatbotConversation::query()->create([
            'user_id' => $owner->id,
            'instance_id' => $instance->id,
            'channel' => 'web',
        ]);

        $counted = ChatbotConversationInstruction::query()->create([
            'conversation_id' => $conversation->id,
            'created_by' => $owner->id,
            'instruction' => 'Be brief.',
            'scope' => 'reply_count',
            'remaining_uses' => 2,
            'is_active' => true,
        ]);

        ChatbotConversationInstruction::query()->create([
            'conversation_id' => $conversation->id,
            'created_by' => $owner->id,
            'instruction' => 'Expired.',
            'scope' => 'until_time',
            'expires_at' => now()->subHour(),
            'is_active' => true,
        ]);

        $service = app(\App\Services\AiChatbot\AiChatbotService::class);
        $service->sendMessage($owner, $instance, 'one', $conversation->id);
        $counted->refresh();
        $this->assertSame(1, $counted->remaining_uses);
        $this->assertTrue($counted->is_active);

        $service->sendMessage($owner, $instance, 'two', $conversation->id);
        $counted->refresh();
        $this->assertSame(0, $counted->remaining_uses);
        $this->assertFalse($counted->is_active);
    }

    public function test_single_access_user_lands_in_workspace_not_instance_list(): void
    {
        $owner = $this->owner();
        $malan = $this->malanInstance($owner);
        // Remove any other instances for this agent path by using agent with only malan access
        $agent = $this->agentUser();
        app(ChatbotAuthorizationService::class)->grantAccess($malan, $agent, ChatbotInstanceUser::ROLE_MANAGER);

        // Agent has no owned instances — only granted malan
        $this->actingAs($agent)
            ->get(route('ai-chatbot.index'))
            ->assertRedirect(route('ai-chatbot.workspace.conversations', $malan));
    }
}
