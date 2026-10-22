<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Jobs\ProcessKamanConversationJob;
use App\Jobs\StoreKamanWhatsappLeadJob;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Services\AiChatbot\KamanWhatsappLeadStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KamanWhatsappLeadStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kaman_agents.base_url' => 'https://kamanagents-production.up.railway.app',
            'services.kaman_agents.username' => 'yamen',
            'services.kaman_agents.password' => 'yamen123',
            'services.kaman_agents.lead_source' => 'whatsapp',
            'services.kaman_agents.lead_idle_minutes' => 30,
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.verify_ssl' => false,
        ]);
    }

    public function test_thanks_close_dispatches_lead_store_job(): void
    {
        Queue::fake();

        $conversation = $this->makeClosingConversation();

        Http::fake([
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'thanks-lead'], 200),
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"fullName":"Yamen","restName":"unknown","city":"unknown","notes":"الزبون سلّم المحادثة."}'],
                ]],
            ], 200),
            'kamanagents-production.up.railway.app/api/auth/login' => Http::response(['accessToken' => 'lead-token'], 200),
            'kamanagents-production.up.railway.app/api/leads*' => Http::response(['id' => 'lead-closed'], 201),
        ]);

        (new ProcessKamanConversationJob(
            (int) $conversation->instance_id,
            (int) $conversation->id,
            1,
            '972533046830@c.us',
        ))->handle(
            app(\App\Services\AiChatbot\AiChatbotService::class),
            app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
            app(\App\Services\AiChatbot\KamanConversationBurstService::class),
        );

        $conversation->refresh();
        $this->assertNotEmpty($conversation->metadata['kaman_agents_lead']['posted_at'] ?? null);
        Queue::assertNotPushed(StoreKamanWhatsappLeadJob::class);
    }

    public function test_regular_reply_stores_lead_when_customer_engages(): void
    {
        Queue::fake();

        $instance = $this->makeInstance();
        $conversation = $this->makeConversation($instance, [
            'contact_phone' => '972533046830',
            'contact_name' => 'Yamen',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'في عندكم مخشير فيزا سلولري',
            'delivery_status' => 'sent',
        ]);
        app(\App\Services\AiChatbot\KamanConversationBurstService::class)
            ->registerInboundMessage($conversation->fresh() ?? $conversation);

        Http::fake([
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'idle-1'], 200),
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"fullName":"Yamen","restName":"unknown","city":"unknown","notes":"يسأل عن جهاز فيزا."}'],
                ]],
            ], 200),
            'kamanagents-production.up.railway.app/api/auth/login' => Http::response(['accessToken' => 'lead-token'], 200),
            'kamanagents-production.up.railway.app/api/leads*' => Http::response(['id' => 'lead-engaged'], 201),
        ]);

        (new ProcessKamanConversationJob(
            (int) $instance->id,
            (int) $conversation->id,
            1,
            '972533046830@c.us',
        ))->handle(
            app(\App\Services\AiChatbot\AiChatbotService::class),
            app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
            app(\App\Services\AiChatbot\KamanConversationBurstService::class),
        );

        $conversation->refresh();
        $this->assertNotEmpty($conversation->metadata['kaman_agents_lead']['posted_at'] ?? null);
        $this->assertSame('engaged', $conversation->metadata['kaman_agents_lead']['reason'] ?? null);
        $this->assertSame('0533046830', $conversation->metadata['kaman_agents_lead']['phoneNumber'] ?? null);
        $this->assertSame('lead-engaged', $conversation->metadata['kaman_agents_lead']['lead_id'] ?? null);
        Queue::assertNotPushed(StoreKamanWhatsappLeadJob::class);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST' && str_contains($request->url(), '/api/leads/');
        });
    }

    public function test_later_reply_updates_the_stored_lead(): void
    {
        $conversation = $this->makeConversation($this->makeInstance(), [
            'contact_phone' => '972587816661',
            'contact_name' => 'أسيد',
            'metadata' => [
                'kaman_agents_lead' => [
                    'posted_at' => now()->subHour()->toIso8601String(),
                    'reason' => 'engaged',
                    'lead_id' => 'lead-aseed',
                    'fullName' => 'أسيد',
                    'restName' => 'unknown',
                    'city' => 'unknown',
                    'phoneNumber' => '0587816661',
                    'notes' => 'ما في طلب واضح غير متابعة المحادثة.',
                ],
            ],
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'كانون',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'بيت حنينا',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'كلهم',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'fullName' => 'أسيد',
                            'restName' => 'كانون',
                            'city' => 'بيت حنينا',
                            'notes' => 'شغله قعدة جوا وتيك أواي وتوصيل.',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
            'kamanagents-production.up.railway.app/api/auth/login' => Http::response(['accessToken' => 'lead-token'], 200),
            'kamanagents-production.up.railway.app/api/leads*' => Http::response(['id' => 'lead-aseed'], 200),
        ]);

        $result = app(KamanWhatsappLeadStoreService::class)
            ->storeFromConversation($conversation, KamanWhatsappLeadStoreService::REASON_ENGAGED);

        $this->assertTrue($result['stored']);
        $this->assertTrue($result['updated'] ?? false);
        $conversation->refresh();
        $this->assertSame('كانون', $conversation->metadata['kaman_agents_lead']['restName'] ?? null);
        $this->assertSame('بيت حنينا', $conversation->metadata['kaman_agents_lead']['city'] ?? null);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/api/leads/lead-aseed')
                && $request['fullName'] === 'أسيد'
                && $request['restName'] === 'كانون'
                && $request['city'] === 'بيت حنينا'
                && $request['phoneNumber'] === '0587816661'
                && $request['notes'] === 'شغله قعدة جوا وتيك أواي وتوصيل.';
        });
    }

    public function test_stores_lead_with_extracted_fields_and_arabic_notes(): void
    {
        $conversation = $this->makeConversation($this->makeInstance(), [
            'contact_phone' => '972533046830',
            'contact_name' => 'Yamen Test',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'أنا يامن من كفر كنا، عندي مطعم الشاورما بدي نظام كاشير وسعر شهري ومندوب يتواصل معي',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message' => 'يا هلا، النظام 299 شهري وبخلي المندوب يتواصل',
            'delivery_status' => 'sent',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'fullName' => 'يامن',
                            'restName' => 'مطعم الشاورما',
                            'city' => 'كفر كنا',
                            'notes' => 'الزبون يحتاج نظام كاشير لمطعمه، ينتظر مندوب يتواصل معه، ويهدف لمعرفة السعر الشهري وتشغيل النظام.',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
            'kamanagents-production.up.railway.app/api/auth/login' => Http::response([
                'accessToken' => 'test-access-token',
                'refreshToken' => 'test-refresh',
            ], 200),
            'kamanagents-production.up.railway.app/api/leads*' => Http::response([
                'id' => 'lead-1',
            ], 201),
        ]);

        $result = app(KamanWhatsappLeadStoreService::class)
            ->storeFromConversation($conversation, KamanWhatsappLeadStoreService::REASON_CLOSED);

        $this->assertTrue($result['stored']);
        $conversation->refresh();
        $this->assertNotEmpty($conversation->metadata['kaman_agents_lead']['posted_at'] ?? null);
        $this->assertSame('يامن', $conversation->metadata['kaman_agents_lead']['fullName'] ?? null);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/auth/login')
                && $request['username'] === 'yamen'
                && $request['password'] === 'yamen123';
        });
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/api/leads/')) {
                return false;
            }

            $this->assertSame('يامن', $request['fullName']);
            $this->assertSame('مطعم الشاورما', $request['restName']);
            $this->assertSame('whatsapp', $request['source']);
            $this->assertSame('كفر كنا', $request['city']);
            $this->assertSame('0533046830', $request['phoneNumber']);
            $this->assertStringContainsString('نظام كاشير', (string) $request['notes']);
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', (string) $request['notes']);
            $this->assertSame('Bearer test-access-token', $request->header('Authorization')[0] ?? null);

            return true;
        });
    }

    public function test_voice_transcript_is_sent_for_the_summary_and_unclear_audio_is_marked(): void
    {
        $conversation = $this->makeConversation($this->makeInstance(), [
            'contact_phone' => '972525026825',
            'contact_name' => 'مجد الكروم',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'بدي افتح food truck مطبخ ايطالي',
            'metadata' => [
                'whatsapp_voice' => true,
                'voice_unclear' => false,
                'transcript' => 'بدي افتح food truck مطبخ ايطالي',
            ],
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'رسالة صوتية (غير واضحة)',
            'metadata' => [
                'whatsapp_voice' => true,
                'voice_unclear' => true,
                'voice_best_guess' => 'مدفست بونيم',
            ],
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'fullName' => 'مجد الكروم',
                            'restName' => 'unknown',
                            'city' => 'unknown',
                            'notes' => 'مهتم يفتح فود ترك بمطبخ إيطالي.',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
            'kamanagents-production.up.railway.app/api/auth/login' => Http::response(['accessToken' => 'lead-token'], 200),
            'kamanagents-production.up.railway.app/api/leads*' => Http::response(['id' => 'lead-voice'], 201),
        ]);

        $result = app(KamanWhatsappLeadStoreService::class)
            ->storeFromConversation($conversation, KamanWhatsappLeadStoreService::REASON_CLOSED);

        $this->assertTrue($result['stored']);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'api.openai.com')) {
                return false;
            }
            $content = (string) ($request['messages'][0]['content'] ?? '');
            $user = (string) ($request['messages'][1]['content'] ?? '');
            $this->assertStringContainsString('لا تكرر في notes الاسم', $content);
            $this->assertStringContainsString('[رسالة صوتية] بدي افتح food truck مطبخ ايطالي', $user);
            $this->assertStringContainsString('[رسالة صوتية غير واضحة] مدفست بونيم', $user);

            return true;
        });
    }

    public function test_unknown_fields_are_sent_as_unknown_when_missing(): void
    {
        $conversation = $this->makeConversation($this->makeInstance(), [
            'contact_phone' => null,
            'contact_name' => null,
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'تسلمي',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'disabled']], 500),
            'kamanagents-production.up.railway.app/api/auth/login' => Http::response([
                'accessToken' => 'test-access-token',
            ], 200),
            'kamanagents-production.up.railway.app/api/leads*' => Http::response(['id' => 'lead-2'], 201),
        ]);

        $result = app(KamanWhatsappLeadStoreService::class)
            ->storeFromConversation($conversation, KamanWhatsappLeadStoreService::REASON_CLOSED);

        $this->assertTrue($result['stored']);
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/api/leads/')) {
                return false;
            }

            $this->assertSame('unknown', $request['fullName']);
            $this->assertSame('unknown', $request['restName']);
            $this->assertSame('unknown', $request['city']);
            $this->assertSame('unknown', $request['phoneNumber']);
            $this->assertSame('whatsapp', $request['source']);
            $this->assertNotSame('unknown', $request['notes']);
            $this->assertStringNotContainsString('053', (string) $request['notes']);
            $this->assertStringContainsString('طلب واضح', (string) $request['notes']);

            return true;
        });
    }

    public function test_does_not_store_lead_twice(): void
    {
        $conversation = $this->makeConversation($this->makeInstance(), [
            'contact_phone' => '0533046830',
            'contact_name' => 'Yamen',
            'metadata' => [
                'kaman_agents_lead' => [
                    'posted_at' => now()->toIso8601String(),
                    'lead_id' => 'existing',
                ],
            ],
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'شكرا',
        ]);

        Http::fake();

        $result = app(KamanWhatsappLeadStoreService::class)
            ->storeFromConversation($conversation, KamanWhatsappLeadStoreService::REASON_CLOSED);

        $this->assertFalse($result['stored']);
        $this->assertSame('already_stored', $result['skipped'] ?? null);
        Http::assertNothingSent();
    }

    public function test_idle_store_skips_active_conversation(): void
    {
        $conversation = $this->makeConversation($this->makeInstance(), [
            'contact_phone' => '0533046830',
            'last_message_at' => now(),
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'مرحبا',
        ]);

        Http::fake();

        $result = app(KamanWhatsappLeadStoreService::class)
            ->storeFromConversation($conversation, KamanWhatsappLeadStoreService::REASON_IDLE);

        $this->assertFalse($result['stored']);
        $this->assertSame('still_active', $result['skipped'] ?? null);
        Http::assertNothingSent();
    }

    public function test_skips_when_agents_api_is_not_configured(): void
    {
        config([
            'services.kaman_agents.base_url' => '',
            'services.kaman_agents.username' => '',
            'services.kaman_agents.password' => '',
        ]);

        $conversation = $this->makeConversation($this->makeInstance(), [
            'contact_phone' => '0533046830',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'تسلمي',
        ]);

        $result = app(KamanWhatsappLeadStoreService::class)
            ->storeFromConversation($conversation, KamanWhatsappLeadStoreService::REASON_CLOSED);

        $this->assertFalse($result['stored']);
        $this->assertSame('not_configured', $result['skipped'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeConversation(ChatbotInstance $instance, array $attributes = []): ChatbotConversation
    {
        return ChatbotConversation::query()->create(array_merge([
            'user_id' => $instance->user_id,
            'instance_id' => $instance->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972533046830@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'title' => 'lead-test',
        ], $attributes));
    }

    private function makeClosingConversation(): ChatbotConversation
    {
        $instance = $this->makeInstance();
        $conversation = $this->makeConversation($instance, [
            'contact_phone' => '972533046830',
            'contact_name' => 'Yamen Test',
        ]);

        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message' => 'احنا نظام كوباه وإدارة للمطعم',
            'delivery_status' => 'sent',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'تسلمي',
            'delivery_status' => 'sent',
        ]);

        $burst = app(\App\Services\AiChatbot\KamanConversationBurstService::class);
        $burst->registerInboundMessage($conversation->fresh() ?? $conversation);

        return $conversation->fresh() ?? $conversation;
    }

    private function makeInstance(): ChatbotInstance
    {
        $user = User::factory()->create();

        return ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'kaman_whatsapp',
            'system_prompt' => 'x',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'is_active' => true,
        ]);
    }
}
