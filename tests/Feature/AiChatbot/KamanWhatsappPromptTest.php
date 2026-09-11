<?php

namespace Tests\Feature\AiChatbot;

use App\Jobs\ProcessKamanConversationJob;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Services\AiChatbot\ChatbotTestService;
use App\Services\AiChatbot\GreenApiIncomingMessage;
use App\Services\AiChatbot\KamanConversationCloser;
use App\Services\AiChatbot\KamanHumanDelay;
use App\Services\AiChatbot\KamanLeadScorer;
use App\Services\AiChatbot\KamanOriginFact;
use App\Services\AiChatbot\KamanPosDemoVideoService;
use App\Services\AiChatbot\KamanVisaDeviceFact;
use Database\Seeders\KamanWhatsappChatbotInstanceSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KamanWhatsappPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_kaman_whatsapp_prompt_is_sales_conversion_engine(): void
    {
        $path = database_path('seeders/prompts/kaman_whatsapp_system_prompt.txt');
        $this->assertFileExists($path);

        $prompt = trim((string) file_get_contents($path));

        $this->assertStringContainsString('KAMAN SALES CONVERSION ENGINE', $prompt);
        $this->assertStringContainsString('HUMAN SALES AGENT V2', $prompt);
        $this->assertStringContainsString('إعلان إنستغرام أو فيسبوك', $prompt);
        $this->assertStringContainsString('يا هلا فيك', $prompt);
        $this->assertStringContainsString('الاسم والبلد', $prompt);
        $this->assertStringContainsString('السوخن (סוכן) تبعنا بالمنطقة عندك', $prompt);
        $this->assertStringContainsString('HOT LEAD', $prompt);
        $this->assertStringContainsString('HAAT DaaS', $prompt);
        $this->assertStringContainsString('بدون عمولة هات على الطلب', $prompt);
        $this->assertStringContainsString('Soft Close', $prompt);
        $this->assertStringContainsString('زيكوي', $prompt);
        $this->assertStringContainsString('SMS كل 3 ساعات', $prompt);
        $this->assertStringContainsString('299', $prompt);
        $this->assertStringContainsString('مع Warm/Hot Lead تجنّبي جمل مثل', $prompt);
        $this->assertStringContainsString('ممنوع التشكيل نهائيا', $prompt);
        $this->assertStringContainsString('الرسائل الصوتية', $prompt);
        $this->assertStringContainsString('المنيو الرقمي', $prompt);
        $this->assertStringContainsString('رقم الطاولة', $prompt);
        $this->assertStringContainsString('بدون ربط بطاولة', $prompt);
        $this->assertStringContainsString('تيك اوي', $prompt);
        $this->assertStringContainsString('اسم المطعم ونوعه', $prompt);
        $this->assertStringContainsString('نكتني خلص', $prompt);
        $this->assertStringContainsString('توكل بلله تمام', $prompt);
        $this->assertStringContainsString('على راسي', $prompt);
        $this->assertStringContainsString('ممنوع DaaS', $prompt);
        $this->assertStringContainsString('דוח X', $prompt);
        $this->assertStringContainsString('من الأ للياء', $prompt);
        $this->assertStringContainsString('بنكون عالتواصل', $prompt);
        $this->assertStringContainsString('لا تنهي', $prompt);
        $this->assertStringContainsString('ممنوع تكرري ميزة شرحتيها بهالمحادثة', $prompt);
        $this->assertStringContainsString('رسائل الزبون المتتالية', $prompt);
        $this->assertStringContainsString('تسيود ايلي', $prompt);
        $this->assertStringContainsString('إذا عندك تسيود، احنا بنقدر نستخدمه مع النظام', $prompt);
        $this->assertStringContainsString('إذا قال "كيف"', $prompt);
        $this->assertStringContainsString('وضّحي جملتك السابقة', $prompt);
        $this->assertStringContainsString('مدبيست مدبكوت', $prompt);
        $this->assertStringContainsString('מדפסת מדבקות', $prompt);
        $this->assertStringContainsString('المجشيم', $prompt);
        $this->assertStringContainsString('معريخت كوبوت', $prompt);
        $this->assertStringContainsString('يرجى ملء النموذج', $prompt);
        $this->assertStringContainsString('إذا بدك تفاصيل خبرني', $prompt);
        $this->assertStringContainsString('العثلي صورة', $prompt);
        $this->assertStringContainsString('ما بقدر أرسل صور', $prompt);
        $this->assertStringContainsString('فما بقدر أجاوبك على هيك سؤال', $prompt);
        $this->assertStringContainsString('مخشير فيزا', $prompt);
        $this->assertStringContainsString('4G', $prompt);
        $this->assertStringContainsString('كفر قاسم.', $prompt);
        $this->assertStringContainsString('أصل الشركة', $prompt);
        $this->assertStringContainsString('ما بعرفها 100%', $prompt);
        $this->assertStringContainsString('لما يقعد معك السوخن', $prompt);
        $this->assertStringContainsString('{{SWE001}}', $prompt);
        $this->assertStringContainsString('فقط إذا سأل', $prompt);
        $this->assertStringContainsString('ممنوع ترميها بالبداية', $prompt);
        $this->assertStringNotContainsString('جاوبِي فوراً حتى لو البروفايل ناقص', $prompt);
        $this->assertStringContainsString('ابليكشن دفع', $prompt);
        $this->assertStringContainsString('بس توصيل', $prompt);
        $this->assertStringContainsString('بنركبها على أي جهاز عندك', $prompt);
        $this->assertStringContainsString('يا هلا فيك" مرة واحدة', $prompt);
        $this->assertStringContainsString('تمام عمي [الاسم]" مرة واحدة', $prompt);
        $this->assertStringContainsString('بلا أي سؤال وراها', $prompt);
        $this->assertStringContainsString('ستيكرات واتساب', $prompt);
        $this->assertStringContainsString('تسلمي', $prompt);
        $this->assertStringContainsString('تفاصيل إضافية', $prompt);
        $this->assertStringContainsString('أنا جاهزة', $prompt);
    }

    public function test_seeder_persists_sales_conversion_prompt_on_kaman_instance(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);

        $instance = ChatbotInstance::query()
            ->where('name', KamanWhatsappChatbotInstanceSeeder::INSTANCE_NAME)
            ->where('integration_type', 'kaman_whatsapp')
            ->firstOrFail();

        $prompt = (string) $instance->system_prompt;
        $this->assertStringContainsString('KAMAN SALES CONVERSION ENGINE', $prompt);
        $this->assertStringContainsString('HUMAN SALES AGENT V2', $prompt);
        $this->assertStringContainsString('HAAT DaaS', $prompt);
        $this->assertStringContainsString('OUTPUT RULE:', $prompt);
        $this->assertStringContainsString('لا Lead Score', $prompt);
        $this->assertStringContainsString('يا هلا فيك', $prompt);
        $this->assertStringContainsString('المنيو الرقمي', $prompt);
        $this->assertStringContainsString('رقم الطاولة', $prompt);
        $this->assertStringContainsString('بدون ربط بطاولة', $prompt);
        $this->assertStringContainsString('نكتني خلص', $prompt);
        $this->assertStringContainsString('ممنوع DaaS', $prompt);
    }

    public function test_burst_ephemeral_prompt_does_not_volunteer_visa(): void
    {
        $message = new ChatbotMessage(['message' => '{{SWE001}}']);
        $prompt = app(\App\Services\AiChatbot\KamanConversationBurstService::class)
            ->buildBurstEphemeralPrompt([$message]);

        $this->assertStringContainsString('{{SWE001}}', $prompt);
        $this->assertStringContainsString('Do NOT mention visa', $prompt);
        $this->assertStringContainsString('are NOT a visa question', $prompt);
        $this->assertStringContainsString('كفر قاسم', $prompt);
        $this->assertStringNotContainsString('Visa /', $prompt);
    }

    public function test_burst_ephemeral_prompt_handles_stickers_and_thanks(): void
    {
        $message = new ChatbotMessage(['message' => GreenApiIncomingMessage::STICKER_LABEL]);
        $prompt = app(\App\Services\AiChatbot\KamanConversationBurstService::class)
            ->buildBurstEphemeralPrompt([$message]);

        $this->assertStringContainsString('[WhatsApp sticker]', $prompt);
        $this->assertStringContainsString('تسلمي', $prompt);
        $this->assertStringContainsString('تفاصيل إضافية', $prompt);
        $this->assertStringContainsString('أنا جاهزة', $prompt);
    }

    public function test_kaman_human_delay_buckets_stay_within_spec(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $short = KamanHumanDelay::secondsForText(str_repeat('ا', 20));
            $this->assertGreaterThanOrEqual(2.8, $short);
            $this->assertLessThanOrEqual(4.8, $short);

            $mid = KamanHumanDelay::secondsForText(str_repeat('ا', 150));
            $this->assertGreaterThanOrEqual(7.0, $mid);
            $this->assertLessThanOrEqual(11.0, $mid);

            $long = KamanHumanDelay::secondsForText(str_repeat('ا', 500));
            $this->assertGreaterThanOrEqual(11.0, $long);
            $this->assertLessThanOrEqual(16.0, $long);
        }
    }

    public function test_kaman_split_bubbles_caps_at_two(): void
    {
        $bubbles = KamanHumanDelay::splitBubbles("اه فهمت عليك\n\nطب اسمع هاي مهمة\n\nسطر ثالث");

        $this->assertCount(2, $bubbles);
        $this->assertSame('اه فهمت عليك', $bubbles[0]);
        $this->assertStringContainsString('طب اسمع هاي مهمة', $bubbles[1]);
    }

    public function test_lead_scorer_raises_score_for_multi_branch_competitor(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);

        $instance = ChatbotInstance::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->firstOrFail();

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $instance->user_id,
            'instance_id' => $instance->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'title' => 'lead',
        ]);

        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message' => 'عندي 3 مطاعم وانا مع لينكوبوت',
        ]);

        $snapshot = app(KamanLeadScorer::class)->refresh($conversation->fresh());

        $this->assertGreaterThanOrEqual(50, $snapshot['lead_score']);
        $this->assertContains($snapshot['lead_status'], [
            KamanLeadScorer::STATUS_POSSIBLE,
            KamanLeadScorer::STATUS_QUALIFIED,
            KamanLeadScorer::STATUS_HOT,
        ]);
        $this->assertSame('QUALIFIED', $snapshot['conversation_stage']);
        $this->assertArrayHasKey('kaman_sales', $conversation->fresh()->metadata);
        $this->assertSame($snapshot['lead_score'], $conversation->fresh()->metadata['kaman_sales']['lead_score']);
    }

    public function test_kaman_webhook_waits_for_burst_then_splits_blank_line_reply(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Queue::fake();

        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);

        $instance = ChatbotInstance::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->firstOrFail();

        $instance->forceFill([
            'greenapi_webhook_token' => 'kaman-token-split',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'is_active' => true,
        ])->save();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => "اه فهمت عليك\n\nطب اسمع هاي ممكن تهمك"]]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-1'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'kaman-token-split']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972501234567@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'عندي 3 مطاعم و'],
                'idMessage' => 'msg-kaman-1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('listening', true)
            ->assertJsonPath('reply', null);

        Queue::assertPushed(ProcessKamanConversationJob::class);

        $job = new ProcessKamanConversationJob(
            (int) $instance->id,
            (int) ChatbotConversation::query()->where('instance_id', $instance->id)->value('id'),
            1,
            '972501234567@c.us',
        );
        $job->handle(
            app(\App\Services\AiChatbot\AiChatbotService::class),
            app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
            app(\App\Services\AiChatbot\KamanConversationBurstService::class),
        );

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'message' => 'اه فهمت عليك',
            'role' => 'assistant',
        ]);
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'message' => 'طب اسمع هاي ممكن تهمك',
            'role' => 'assistant',
        ]);
    }

    public function test_kaman_webhook_collects_split_customer_lines_into_one_reply(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Queue::fake();

        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);

        $instance = ChatbotInstance::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->firstOrFail();

        $instance->forceFill([
            'greenapi_webhook_token' => 'kaman-token-burst',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'is_active' => true,
        ])->save();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'اه فهمت، عندكم قعدة وكiosk وتوصيل']]],
            ]),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sent-burst'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'kaman-token-burst']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972503207877@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'محل برغر'],
                'idMessage' => 'msg-burst-1',
            ],
        ])->assertOk()->assertJsonPath('listening', true);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'kaman-token-burst']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972503207877@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'في عنا עמדת קיוסק'],
                'idMessage' => 'msg-burst-2',
            ],
        ])->assertOk()->assertJsonPath('conversation_version', 2);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'kaman-token-burst']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972503207877@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'قعده بل محل'],
                'idMessage' => 'msg-burst-3',
            ],
        ])->assertOk()->assertJsonPath('conversation_version', 3);

        $this->assertSame(3, ChatbotMessage::query()->where('role', 'user')->count());
        $this->assertSame(0, ChatbotMessage::query()->where('role', 'assistant')->count());

        $conversationId = (int) ChatbotConversation::query()->where('instance_id', $instance->id)->value('id');

        (new ProcessKamanConversationJob((int) $instance->id, $conversationId, 1, '972503207877@c.us'))
            ->handle(
                app(\App\Services\AiChatbot\AiChatbotService::class),
                app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
                app(\App\Services\AiChatbot\KamanConversationBurstService::class),
            );

        $this->assertSame(0, ChatbotMessage::query()->where('role', 'assistant')->count());

        (new ProcessKamanConversationJob((int) $instance->id, $conversationId, 3, '972503207877@c.us'))
            ->handle(
                app(\App\Services\AiChatbot\AiChatbotService::class),
                app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
                app(\App\Services\AiChatbot\KamanConversationBurstService::class),
            );

        $this->assertSame(1, ChatbotMessage::query()->where('role', 'assistant')->where('delivery_status', 'sent')->count());
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'role' => 'assistant',
            'message' => 'اه فهمت، عندكم قعدة وكiosk وتوصيل',
        ]);
    }

    public function test_visa_device_fact_matches_only_when_customer_asked(): void
    {
        $fact = new KamanVisaDeviceFact;

        $this->assertTrue($fact->customerAsked('في عندكم مخشير فيزا סלולري'));
        $this->assertTrue($fact->customerAsked('مخشير فيزا 4G'));
        $this->assertTrue($fact->customerAsked('جهاز فيزا سلولري'));
        $this->assertFalse($fact->customerAsked('{{SWE001}}'));
        $this->assertFalse($fact->customerAsked('مرحبا'));
        $this->assertFalse($fact->customerAsked('يامن كفر قاسم'));
        $this->assertFalse($fact->customerAsked('انتو كوبوت؟'));
        $this->assertFalse($fact->customerAsked('اا'));
    }

    public function test_origin_fact_matches_only_when_customer_asked(): void
    {
        $fact = new KamanOriginFact;

        $this->assertTrue($fact->customerAsked('من وين كمان؟'));
        $this->assertTrue($fact->customerAsked('كمان من وين'));
        $this->assertTrue($fact->customerAsked('من وين الشركة'));
        $this->assertTrue($fact->customerAsked('وين اصلكم'));
        $this->assertTrue($fact->customerAsked('מאיפה קמאן'));
        $this->assertTrue($fact->customerAsked('where is kaman from'));
        $this->assertFalse($fact->customerAsked('{{SWE001}}'));
        $this->assertFalse($fact->customerAsked('مرحبا'));
        $this->assertFalse($fact->customerAsked('يامن كفر قاسم'));
        $this->assertFalse($fact->customerAsked('انا من كفر قاسم'));
        $this->assertFalse($fact->customerAsked('انتو كوبوت؟'));
        $this->assertFalse($fact->customerAsked('اا'));
    }

    public function test_test_chat_origin_question_skips_openai(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'should-not-run']]]], 200),
        ]);

        $user = \App\Models\User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'kaman_whatsapp',
            'system_prompt' => 'x',
            'is_active' => true,
        ]);

        $result = app(ChatbotTestService::class)->run($user, $instance, [
            'message' => 'من وين كمان؟',
            'reset' => true,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(KamanOriginFact::REPLY, $result['assistant_response']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai'));
    }

    public function test_test_chat_visa_question_skips_openai(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'should-not-run']]]], 200),
        ]);

        $user = \App\Models\User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'kaman_whatsapp',
            'system_prompt' => 'x',
            'is_active' => true,
        ]);

        $result = app(ChatbotTestService::class)->run($user, $instance, [
            'message' => 'في عندكم مخشير فيزا סלולרי',
            'reset' => true,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(KamanVisaDeviceFact::REPLY, $result['assistant_response']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai'));
    }

    public function test_visual_intent_detector_matches_photo_requests_not_acks(): void
    {
        $demo = app(KamanPosDemoVideoService::class);

        $this->assertTrue($demo->customerAskedForVisual('العثلي صورة'));
        $this->assertTrue($demo->customerAskedForVisual("ممكن توديلي نموذج\nصورة؟"));
        $this->assertTrue($demo->customerAskedForVisual('كيف شكل الكوباه'));
        $this->assertFalse($demo->customerAskedForVisual('اا'));
        $this->assertFalse($demo->customerAskedForVisual('اا ولله علوا'));
        $this->assertFalse($demo->customerAskedForVisual('بس توصيل'));
    }

    public function test_photo_request_sends_pos_demo_video_without_openai(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Storage::fake('local');
        Queue::fake();

        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);

        $instance = ChatbotInstance::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->firstOrFail();

        $path = 'kaman-pos-demo/'.$instance->id.'/demo.mp4';
        Storage::disk('local')->put($path, 'fake-mp4');
        $token = str_repeat('b', 48);
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $settings['pos_demo'] = [
            'disk' => 'local',
            'path' => $path,
            'token' => $token,
            'file_name' => 'kaman-pos.mp4',
            'mime' => 'video/mp4',
            'original_name' => 'demo.mp4',
        ];

        $instance->forceFill([
            'greenapi_webhook_token' => 'kaman-token-demo',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'is_active' => true,
            'integration_settings' => $settings,
        ])->save();

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'should-not-run']]]], 200),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'vid-1'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'kaman-token-demo']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972584648430@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'العثلي صورة'],
                'idMessage' => 'msg-demo-1',
            ],
        ])->assertOk()->assertJsonPath('listening', true);

        $conversationId = (int) ChatbotConversation::query()->where('instance_id', $instance->id)->value('id');

        (new ProcessKamanConversationJob((int) $instance->id, $conversationId, 1, '972584648430@c.us'))
            ->handle(
                app(\App\Services\AiChatbot\AiChatbotService::class),
                app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
                app(\App\Services\AiChatbot\KamanConversationBurstService::class),
            );

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'role' => 'assistant',
            'message_type' => 'video',
            'message' => KamanPosDemoVideoService::CAPTION,
            'delivery_status' => 'sent',
        ]);

        Http::assertSent(function ($request): bool {
            return str_contains((string) $request->url(), '/sendFileByUrl/')
                && str_contains((string) ($request['urlFile'] ?? ''), '/ai-chatbot/public/pos-demo/')
                && str_contains((string) ($request['caption'] ?? ''), 'شكل الكوباه');
        });
        Http::assertNotSent(function ($request): bool {
            return str_contains((string) $request->url(), 'api.openai.com');
        });
    }

    public function test_thanks_after_explanation_closes_without_openai(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Queue::fake();

        $this->seed(WorkspaceUserSeeder::class);
        $user = \App\Models\User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'kaman_whatsapp',
            'system_prompt' => 'x',
            'greenapi_webhook_token' => 'kaman-token-thanks',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'is_active' => true,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $instance->user_id,
            'instance_id' => $instance->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501111222@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'title' => 'thanks-close',
        ]);

        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message' => 'احنا نظام كوباه وإدارة للمطعم',
            'delivery_status' => 'sent',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'should-not-run']]]], 200),
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'thanks-1'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'kaman-token-thanks']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972501111222@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'تسلمي'],
                'idMessage' => 'msg-thanks-1',
            ],
        ])->assertOk()->assertJsonPath('listening', true);

        (new ProcessKamanConversationJob(
            (int) $instance->id,
            (int) $conversation->id,
            1,
            '972501111222@c.us',
        ))->handle(
            app(\App\Services\AiChatbot\AiChatbotService::class),
            app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
            app(\App\Services\AiChatbot\KamanConversationBurstService::class),
        );

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => KamanConversationCloser::THANKS_REPLY_AR,
            'delivery_status' => 'sent',
        ]);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'api.openai.com'));
    }

    public function test_sticker_webhook_is_labeled_and_queued_as_burst(): void
    {
        Queue::fake();

        $this->seed(WorkspaceUserSeeder::class);
        $user = \App\Models\User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'kaman_whatsapp',
            'system_prompt' => 'x',
            'greenapi_webhook_token' => 'kaman-token-sticker',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'is_active' => true,
        ]);

        Http::fake([
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'sticker-1'], 200),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'kaman-token-sticker']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972503333444@c.us'],
            'messageData' => [
                'typeMessage' => 'stickerMessage',
                'fileMessageData' => [
                    'mimeType' => 'image/webp',
                    'fileName' => 'smile.webp',
                ],
                'quotedMessage' => [
                    'textMessage' => 'إذا حابب أشرح لك أكثر عن النظام',
                ],
                'idMessage' => 'msg-sticker-1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('listening', true);

        $stored = ChatbotMessage::query()->where('role', 'user')->latest('id')->first();
        $this->assertNotNull($stored);
        $this->assertStringContainsString('ستيكر واتساب', (string) $stored->message);
        $this->assertStringContainsString('إذا حابب أشرح لك أكثر عن النظام', (string) $stored->message);
        $this->assertTrue((bool) ($stored->metadata['whatsapp_sticker'] ?? false));
    }

    public function test_test_chat_thanks_closes_without_openai(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'should-not-run']]]], 200),
        ]);

        $user = \App\Models\User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'kaman_whatsapp',
            'system_prompt' => 'x',
            'is_active' => true,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'channel' => ChatbotConversation::CHANNEL_TEST,
            'title' => 'test-thanks',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message' => 'شرحت النظام',
            'delivery_status' => 'sent',
        ]);

        $result = app(ChatbotTestService::class)->run($user, $instance, [
            'message' => 'تسلمي',
            'conversation_id' => $conversation->id,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(KamanConversationCloser::THANKS_REPLY_AR, $result['assistant_response']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai'));
    }
}
