<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\AiChatbot\MansourContact;
use App\Models\User;
use App\Services\AiChatbot\AppsCatchupService;
use App\Services\AiChatbot\AppsInsightService;
use App\Services\AiChatbot\AppsPrompt;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\MansourBotService;
use App\Services\AiChatbot\MowahadaOpenAi;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppsBotTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        AppsPrompt::$testResponder = null;
        parent::tearDown();
    }

    public function test_mowahada_bots_use_their_own_openai_key(): void
    {
        config([
            'services.openai.api_key' => 'shared-key',
            'services.openai.mowahada_api_key' => 'mowahada-key',
            'services.openai.dima_api_key' => 'dima-key',
            'services.openai.aswatkum_api_key' => 'aswatkum-key',
        ]);

        $this->assertSame('dima-key', MowahadaOpenAi::key(MowahadaOpenAi::DIMA));
        $this->assertSame('aswatkum-key', MowahadaOpenAi::key(MowahadaOpenAi::APPS));
        $this->assertSame('mowahada-key', MowahadaOpenAi::key());

        config([
            'services.openai.dima_api_key' => '',
            'services.openai.aswatkum_api_key' => '',
        ]);

        $this->assertSame('mowahada-key', MowahadaOpenAi::key(MowahadaOpenAi::DIMA));
        $this->assertSame('mowahada-key', MowahadaOpenAi::key(MowahadaOpenAi::APPS));

        config(['services.openai.mowahada_api_key' => '']);

        $this->assertSame('shared-key', MowahadaOpenAi::key());
    }

    public function test_mansour_two_becomes_the_applications_bot(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $farz = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $second = app(MansourBotService::class)->copy($farz, $user);
        $second->forceFill(['name' => 'منصور 2'])->save();

        $apps = app(MansourBotService::class)->ensureAppsBot($user);

        $this->assertNotNull($apps);
        $this->assertTrue($apps->isAppsBot());
        $this->assertSame('بوت تطبيق أصواتكم', $apps->name);
        $this->assertSame($second->id, $apps->id);
        $this->assertFalse($farz->fresh()->isAppsBot());

        $apps->forceFill(['name' => 'بوت التطبيقات'])->save();
        $renamed = app(MansourBotService::class)->ensureAppsBot($user);
        $this->assertSame('بوت تطبيق أصواتكم', $renamed->name);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $apps))
            ->assertOk()
            ->assertSee('id="system_prompt"', false)
            ->assertSee('id="opening_message"', false)
            ->assertDontSee('id="aswatkum_url"', false)
            ->assertDontSee('id="delegate_name"', false)
            ->assertDontSee('id="muwahada_url"', false)
            ->assertDontSee('id="guide_video_url"', false);

        $kept = is_array($apps->integration_settings) ? $apps->integration_settings : [];
        $kept['muwahada_url'] = 'https://example.com/muw';
        $kept['delegate_name'] = 'سامي';
        $apps->forceFill(['integration_settings' => $kept])->save();

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $apps), [
                'name' => 'بوت تطبيق أصواتكم',
                'system_prompt' => 'تعليمات التجربة.',
                'opening_message' => 'مرحبا من التجربة.',
                'greenapi_url' => (string) ($apps->greenapi_url ?? ''),
            ])
            ->assertRedirect();

        $apps->refresh();
        $this->assertSame('تعليمات التجربة.', $apps->system_prompt);
        $this->assertSame('مرحبا من التجربة.', $apps->integration_settings['opening_message']);
        $this->assertSame('https://example.com/muw', $apps->integration_settings['muwahada_url']);
        $this->assertSame('سامي', $apps->integration_settings['delegate_name']);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $apps))
            ->assertOk()
            ->assertSee('نزّلوا التطبيق')
            ->assertSee('ينتظرون حساب')
            ->assertSee('لم يرد')
            ->assertSee('ما بدهم يكملوا')
            ->assertDontSee('عنده لائحة')
            ->assertDontSee('حزب صهيوني');
    }

    public function test_outbound_hold_blocks_whatsapp_sends_and_triggers(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $farz = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $second = app(MansourBotService::class)->copy($farz, $user);
        $second->forceFill(['name' => 'منصور 2'])->save();
        $apps = app(MansourBotService::class)->ensureAppsBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $apps, 'copied name: '.(string) $second->fresh()?->name);
        $url = 'https://7107.api.greenapi.com/waInstance710722761590/sendMessage/test-token';
        $settings = is_array($apps->integration_settings) ? $apps->integration_settings : [];
        $settings['outbound_hold'] = true;
        $settings['greenapi_lines'] = [[
            'url' => $url,
            'status' => 'live',
        ]];
        $apps->forceFill([
            'greenapi_url' => $url,
            'is_active' => true,
            'integration_settings' => $settings,
        ])->save();

        Http::fake();

        $sent = app(ChatbotGreenApiService::class)->sendMessage($url, '972500000000@c.us', 'لا تبعث');
        $this->assertSame(0, $sent['status']);
        $this->assertSame('outbound_hold', $sent['body']);
        Http::assertNothingSent();

        $trigger = app(MansourBotService::class)->trigger($apps->fresh(), [1]);
        $this->assertSame(['sent' => 0, 'failed' => 0], $trigger);
        Http::assertNothingSent();
    }

    public function test_catchup_stores_the_reply_without_sending_it(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', User::MOWAHADA_EMAIL)->firstOrFail();
        $farz = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $second = app(MansourBotService::class)->copy($farz, $user);
        $second->forceFill(['name' => 'منصور 2'])->save();
        $apps = app(MansourBotService::class)->ensureAppsBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $apps);
        $url = 'https://7107.api.greenapi.com/waInstance710722761590/sendMessage/test-token';
        $settings = is_array($apps->integration_settings) ? $apps->integration_settings : [];
        $settings['outbound_hold'] = true;
        $settings['greenapi_lines'] = [['url' => $url, 'status' => 'blocked']];
        $apps->forceFill([
            'greenapi_url' => $url,
            'is_active' => false,
            'integration_settings' => $settings,
        ])->save();

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $apps->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501234567@c.us',
            'contact_phone' => '972501234567',
            'contact_name' => 'محمود',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'message' => AppsPrompt::greetingFor('محمود'),
            'delivery_status' => 'sent',
        ]);

        AppsPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام. في عندك حساب على أصواتكم؟',
            'pause' => false,
            'memory' => ['facts' => [], 'open' => [], 'links_sent' => []],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'getStateInstance')) {
                return Http::response(['stateInstance' => 'authorized'], 200);
            }

            return Http::response([[
                'type' => 'incoming',
                'idMessage' => 'in-1',
                'typeMessage' => 'textMessage',
                'textMessage' => 'نزلت التطبيق',
            ]], 200);
        });

        $result = app(AppsCatchupService::class)->batch($apps->fresh(), 0, 2);
        $this->assertTrue($result['ok']);
        $this->assertSame('replied', $result['rows'][0]['action']);

        $stored = $conversation->messages()->orderBy('id')->pluck('message')->all();
        $this->assertTrue(collect($stored)->contains(fn (string $text): bool => str_contains($text, 'هل نزّلت')));
        $this->assertTrue(collect($stored)->contains(fn (string $text): bool => str_contains($text, 'في عندك حساب')));
        $this->assertSame(
            0,
            $conversation->messages()->where('metadata->not_sent', true)->where('delivery_status', '!=', 'local')->count(),
        );
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sendMessage'));
    }

    public function test_sync_copies_whatsapp_history_without_an_ai_reply(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', User::MOWAHADA_EMAIL)->firstOrFail();
        $farz = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $second = app(MansourBotService::class)->copy($farz, $user);
        $second->forceFill(['name' => 'منصور 2'])->save();
        $apps = app(MansourBotService::class)->ensureAppsBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $apps);
        $url = 'https://7107.api.greenapi.com/waInstance710722761590/sendMessage/test-token';
        $settings = is_array($apps->integration_settings) ? $apps->integration_settings : [];
        $settings['outbound_hold'] = true;
        $settings['greenapi_lines'] = [['url' => $url, 'status' => 'live']];
        $apps->forceFill([
            'greenapi_url' => $url,
            'is_active' => false,
            'integration_settings' => $settings,
        ])->save();

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $apps->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501234567@c.us',
            'contact_phone' => '972501234567',
            'contact_name' => 'زهير',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'attention_status' => ChatbotConversation::ATTENTION_NORMAL,
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'message' => AppsPrompt::greetingFor('زهير'),
            'delivery_status' => 'sent',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'message' => 'رد مخترع ما انبعث',
            'delivery_status' => 'local',
            'metadata' => ['not_sent' => true, 'catchup' => true],
        ]);

        AppsPrompt::$testResponder = function (): string {
            throw new \RuntimeException('model must not run');
        };
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'getStateInstance')) {
                return Http::response(['stateInstance' => 'authorized'], 200);
            }
            if (str_contains($request->url(), 'sendMessage')) {
                return Http::response(['sent' => true], 200);
            }

            return Http::response([
                [
                    'type' => 'incoming',
                    'idMessage' => 'in-real',
                    'typeMessage' => 'textMessage',
                    'textMessage' => 'نزلت التطبيق',
                    'timestamp' => 200,
                ],
                [
                    'type' => 'outgoing',
                    'idMessage' => 'out-greet',
                    'typeMessage' => 'textMessage',
                    'textMessage' => AppsPrompt::greetingFor('زهير'),
                    'timestamp' => 100,
                ],
            ], 200);
        });

        $result = app(AppsCatchupService::class)->syncBatch($apps->fresh(), 0, 2);

        $this->assertTrue($result['ok']);
        $this->assertSame('synced', $result['rows'][0]['action']);
        $this->assertSame(1, $result['rows'][0]['removed']);
        $texts = $conversation->messages()->orderBy('id')->pluck('message')->all();
        $this->assertNotContains('رد مخترع ما انبعث', $texts);
        $this->assertContains('نزلت التطبيق', $texts);
        $this->assertCount(2, $texts);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sendMessage'));
    }

    public function test_the_model_reply_is_sent_without_a_scripted_override(): void
    {
        $seen = [];
        AppsPrompt::$testResponder = function (array $messages) use (&$seen): string {
            $seen = $messages;

            return json_encode([
                'reply' => 'ضروري، بس مش هلق. خلّص اللي بين إيديك وارجع.',
                'pause' => false,
                'memory' => ['facts' => ['سأل إذا المهمة مستعجلة'], 'open' => [], 'links_sent' => []],
            ], JSON_UNESCAPED_UNICODE);
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => AppsPrompt::DEFAULT_OPENING],
            ['role' => 'user', 'message' => 'ضروري هسه؟'],
        ], 'فتحي', $this->links());

        $this->assertSame('ضروري، بس مش هلق. خلّص اللي بين إيديك وارجع.', $reply['text']);
        $this->assertFalse($reply['pause']);
        $this->assertStringNotContainsString('http', $reply['text']);
        $this->assertSame(['سأل إذا المهمة مستعجلة'], $reply['memory']['facts']);
        $this->assertSame('ضروري هسه؟', $seen[array_key_last($seen)]['content']);
        $system = $this->systemText($seen);
        $this->assertStringNotContainsString(AppsPrompt::ASWATKUM_APPLE_URL, $system);
        $this->assertStringNotContainsString(AppsPrompt::ASWATKUM_ANDROID_URL, $system);
        $this->assertStringNotContainsString(AppsPrompt::GUIDE_VIDEO_URL, $system);
        $this->assertStringContainsString(AppsPrompt::MUWAHADA_URL, $system);
        $this->assertStringContainsString(AppsPrompt::MUWAHADA_APPLE_URL, $system);
        $this->assertStringContainsString('נכון', $system);
        $this->assertStringContainsString('ضروري هسه؟', $system);
        $this->assertStringContainsString('يبعت تم', $system);
        $this->assertStringNotContainsString('لما يفضى', $system);
        $this->assertStringContainsString('كمل', $system);
        $this->assertStringContainsString('اندرويد', $system);
        $this->assertStringContainsString('ولا تسأل آيفون ولا أندرويد', $system);
        $this->assertStringContainsString('منزلش', $system);
        $this->assertStringContainsString('كيف الأحوال', $system);
        $this->assertStringContainsString('تنزيل تطبيق «الموحدة» فقط', $system);
        $this->assertStringNotContainsString('ثلاث خطوات', $system);
        $this->assertStringContainsString('ممنوع لائحة الناخبين', $system);
        $this->assertStringNotContainsString('ما في يوزر', $system);
    }

    public function test_the_guide_video_url_is_sent_as_a_file_not_left_in_the_caption(): void
    {
        $url = AppsPrompt::GUIDE_VIDEO_URL;
        $plain = AppsPrompt::videoDelivery('تمام، خلصنا.', $url);
        $this->assertNull($plain['video']);
        $this->assertSame('تمام، خلصنا.', $plain['text']);

        $withVideo = AppsPrompt::videoDelivery("بشرحلك كيف تدخل الأسماء.\n".$url, $url);
        $this->assertSame($url, $withVideo['video']);
        $this->assertSame('بشرحلك كيف تدخل الأسماء.', $withVideo['text']);
        $this->assertStringNotContainsString('http', $withVideo['text']);
    }

    public function test_a_screenshot_is_sent_to_the_model_as_an_image(): void
    {
        $seen = [];
        AppsPrompt::$testResponder = function (array $messages) use (&$seen): string {
            $seen = $messages;

            return json_encode([
                'reply' => 'الصورة بتقول إن رقم الهوية مش موجود. ابعتلي اسمك الكامل والبلدة ورقم الهوية.',
                'pause' => false,
                'memory' => ['facts' => ['ما معه حساب'], 'open' => ['الاسم والبلدة ورقم الهوية'], 'links_sent' => []],
            ], JSON_UNESCAPED_UNICODE);
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'شو الرسالة اللي بتطلعلك؟'],
            [
                'role' => 'user',
                'message' => '[أرسل الزبون صورة/ملف عبر WhatsApp]',
                'image' => 'data:image/jpeg;base64,abc',
            ],
        ], 'فتحي', $this->links());

        $last = $seen[array_key_last($seen)]['content'];
        $this->assertIsArray($last);
        $this->assertSame('image_url', $last[1]['type']);
        $this->assertStringContainsString('تنزيل تطبيق الموحدة', $last[0]['text']);
        $this->assertStringNotContainsString('اضافة لائحة', $last[0]['text']);
        $this->assertStringNotContainsString('اسم مستخدم', $reply['text']);
        $this->assertStringContainsString('اسمك الكامل', $reply['text']);
    }

    public function test_a_written_missing_id_message_is_marked_as_no_user(): void
    {
        $seen = [];
        AppsPrompt::$testResponder = function (array $messages) use (&$seen): string {
            $seen = $messages;

            return json_encode([
                'reply' => 'تمام عمر، ابعتلي اسمك الكامل والبلدة ورقم الهوية.',
                'pause' => false,
                'memory' => ['facts' => ['ما في يوزر'], 'open' => ['الاسم والبلدة ورقم الهوية'], 'links_sent' => []],
            ], JSON_UNESCAPED_UNICODE);
        };

        AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'خبرني لما تخلّص التنزيل.'],
            ['role' => 'user', 'message' => 'نزلته بيكتب لي رقم الهوية مش موجوده بقاعدة البيانات'],
        ], 'عمر', $this->links());

        $last = $seen[array_key_last($seen)]['content'];
        $this->assertIsString($last);
        $this->assertStringNotContainsString(AppsPrompt::MISSING_ACCOUNT_CUE, $last);
        $this->assertStringNotContainsString('ما في يوزر', $last);
    }

    public function test_mixed_message_stays_intact_and_login_is_not_asked_again_by_php(): void
    {
        $captured = '';
        AppsPrompt::$testResponder = function (array $messages) use (&$captured): string {
            $captured = $messages[array_key_last($messages)]['content'];

            return json_encode([
                'reply' => 'اللائحة من قائمة التطبيق بعد ما سجلت دخولك.',
                'pause' => false,
                'memory' => ['facts' => ['سجل دخوله'], 'open' => ['وين اللائحة'], 'links_sent' => []],
            ], JSON_UNESCAPED_UNICODE);
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'في عندك حساب داخل التطبيق ولا كيف؟'],
            ['role' => 'user', 'message' => 'تمام سجلت دخولي بس مش عارف وين اللائحة'],
        ], 'سامر', $this->links());

        $this->assertSame('تمام سجلت دخولي بس مش عارف وين اللائحة', $captured);
        $this->assertSame('اللائحة من قائمة التطبيق بعد ما سجلت دخولك.', $reply['text']);
        $this->assertStringNotContainsString('هل قمت بتسجيل الدخول', $reply['text']);
        $this->assertFalse($reply['pause']);
    }

    public function test_confirmation_words_are_not_stored_as_a_name(): void
    {
        AppsPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام، معك حساب.',
            'pause' => false,
            'memory' => ['facts' => ['נכון', 'نكون', 'نعم', 'الاسم يامن عامر', '123456789'], 'open' => [], 'links_sent' => []],
        ], JSON_UNESCAPED_UNICODE);

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'في عندك حساب داخل التطبيق ولا كيف؟'],
            ['role' => 'user', 'message' => 'נכון'],
        ], 'يامن', $this->links());

        $this->assertSame(['الاسم يامن عامر'], $reply['memory']['facts']);
        $this->assertStringNotContainsString('نكون', $reply['text']);
    }

    public function test_earlier_facts_and_memory_are_sent_with_the_latest_turn(): void
    {
        $system = '';
        $payload = '';
        AppsPrompt::$testResponder = function (array $messages) use (&$system, &$payload): string {
            $system = $this->systemText($messages);
            $payload = json_encode($messages, JSON_UNESCAPED_UNICODE) ?: '';

            return '{"reply":"منكمّل من اللائحة.","pause":false,"memory":{"facts":["اللائحة على ورقة"],"open":[],"links_sent":["aswatkum"]}}';
        };

        $turns = [
            ['role' => 'user', 'message' => 'اللائحة على ورقة'],
            ['role' => 'assistant', 'message' => 'انقل الأسماء للتطبيق.'],
        ];
        for ($i = 0; $i < 20; $i++) {
            $turns[] = ['role' => 'user', 'message' => 'متابعة '.$i];
            $turns[] = ['role' => 'assistant', 'message' => 'حاضر '.$i];
        }
        $turns[] = ['role' => 'user', 'message' => 'كمل'];

        $first = AppsPrompt::nextReply($turns, 'ليلى', $this->links(), [
            'facts' => ['نزل أصواتكم'],
            'open' => ['وين الفيديو'],
            'links_sent' => ['aswatkum'],
        ]);

        $this->assertStringContainsString('اللائحة على ورقة', $payload);
        $this->assertStringContainsString('متابعة 0', $payload);
        $this->assertStringContainsString('نزل أصواتكم', $system);
        $this->assertStringContainsString('وين الفيديو', $system);
        $this->assertStringContainsString('اقرأها كلها قبل الرد', $system);
        $this->assertSame('كمل', $turns[array_key_last($turns)]['message']);
        $this->assertContains('اللائحة على ورقة', $first['memory']['facts']);

        AppsPrompt::$testResponder = function (array $messages) use (&$system): string {
            $system = $this->systemText($messages);

            return '{"reply":"لسا على الورقة.","pause":false,"memory":{"facts":["اللائحة على ورقة"],"open":[],"links_sent":["aswatkum"]}}';
        };
        AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'كمل'],
        ], 'ليلى', $this->links(), $first['memory']);
        $this->assertStringContainsString('اللائحة على ورقة', $system);
    }

    public function test_duplicate_user_lines_are_sent_once(): void
    {
        $userLines = [];
        AppsPrompt::$testResponder = function (array $messages) use (&$userLines): string {
            foreach ($messages as $message) {
                if ($message['role'] === 'user') {
                    $userLines[] = $message['content'];
                }
            }

            return '{"reply":"تمام، كمّلنا.","pause":false,"memory":{"facts":[],"open":[],"links_sent":[]}}';
        };

        AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => AppsPrompt::DEFAULT_OPENING],
            ['role' => 'user', 'message' => 'اندرويد'],
            ['role' => 'user', 'message' => 'اندرويد'],
        ], 'هند', $this->links());

        $this->assertSame(['اندرويد', 'اندرويد'], $userLines);
    }

    public function test_dialect_is_sent_to_the_model_and_its_reply_is_kept(): void
    {
        $seen = null;
        AppsPrompt::$testResponder = function (array $messages) use (&$seen): string {
            $seen = $messages;

            return json_encode([
                'reply' => 'لسا ما نزّلت إشي. آيفون: '.AppsPrompt::ASWATKUM_APPLE_URL.' أندرويد: '.AppsPrompt::ASWATKUM_ANDROID_URL,
                'pause' => false,
                'memory' => ['facts' => ['ما نزّل أصواتكم'], 'open' => [], 'links_sent' => ['aswatkum']],
            ], JSON_UNESCAPED_UNICODE);
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => AppsPrompt::DEFAULT_OPENING],
            ['role' => 'user', 'message' => 'لعا منزلش اشي'],
        ], 'فتحي', $this->links());

        $blob = json_encode($seen, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('لعا منزلش اشي', $blob);
        $this->assertStringContainsString('منزلش', $blob);
        $this->assertStringContainsString('لسا ما نزّلت إشي', $reply['text']);
        $this->assertStringNotContainsString(AppsPrompt::ACCOUNT_QUESTION, $reply['text']);
        $this->assertStringNotContainsString('هل نزّلت تطبيق', $reply['text']);
    }

    public function test_a_failed_model_does_not_advance_the_task(): void
    {
        $calls = 0;
        AppsPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return '';
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'منين اليوزر والسيسما؟'],
        ], 'هند', $this->links());

        $this->assertSame(2, $calls);
        $this->assertSame(AppsPrompt::FALLBACK, $reply['text']);
        $this->assertFalse($reply['pause']);
        $this->assertStringNotContainsString('http', $reply['text']);
        $this->assertStringNotContainsString('هل نزّلت', $reply['text']);
    }

    public function test_one_successful_call_is_not_repeated(): void
    {
        $calls = 0;
        AppsPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return $calls === 1
                ? ''
                : '{"reply":"بنجهّزلك الحساب احنا.","pause":false,"memory":{"facts":[],"open":[],"links_sent":[]}}';
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'منين اليوزر والسيسما؟'],
        ], 'هند', $this->links());

        $this->assertSame(2, $calls);
        $this->assertSame('بنجهّزلك الحساب احنا.', $reply['text']);
    }

    public function test_links_and_secrets_are_checked_after_the_model(): void
    {
        AppsPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'رابط الموحدة مش متوفر هلق.',
            'pause' => false,
            'memory' => ['facts' => [], 'open' => [], 'links_sent' => []],
        ], JSON_UNESCAPED_UNICODE);
        $missing = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'ابعث رابط الموحدة'],
        ], 'هند', $this->links());
        $this->assertStringContainsString(AppsPrompt::MUWAHADA_URL, $missing['text']);
        $this->assertStringContainsString(AppsPrompt::MUWAHADA_APPLE_URL, $missing['text']);
        $this->assertStringNotContainsString('مش متوفر', $missing['text']);

        AppsPrompt::$testResponder = fn (): string => '{"reply":"هذا رابط الموحدة https://play.google.com/store/apps/details?id=com.fake","pause":false,"memory":{"facts":[],"open":[],"links_sent":[]}}';
        $fake = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'اندرويد'],
        ], 'هند', $this->links());
        $this->assertStringNotContainsString('com.fake', $fake['text']);
        $this->assertStringContainsString('almowahada', $fake['text']);
        $this->assertStringContainsString(AppsPrompt::MUWAHADA_APPLE_URL, $fake['text']);

        AppsPrompt::$testResponder = fn (): string => '{"reply":"شكرا، هيك خلصنا.","pause":false,"memory":{"facts":[],"open":[],"links_sent":[]}}';
        $thanks = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'نزلت الموحدة'],
        ], 'هند', $this->links());
        $this->assertStringNotContainsString('id6790904899', $thanks['text']);

        AppsPrompt::$testResponder = fn (): string => '{"reply":"ابعتلي الباسورد تبع الحساب.","pause":false,"memory":{"facts":["الباسورد 1234"],"open":[],"links_sent":[]}}';
        $secret = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'منين اليوزر والسيسما؟'],
        ], 'هند', $this->links());
        $this->assertStringNotContainsString('باسورد', $secret['text']);
        $this->assertStringNotContainsString('رقم الهوية', $secret['text']);
        $this->assertSame([], $secret['memory']['facts']);

        AppsPrompt::$testResponder = fn (): string => '{"reply":"تمام، وصلت الاسم. تم تسجيلك داخل النظام.","pause":true,"memory":{"facts":[],"open":[],"links_sent":[]}}';
        $unverified = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'هند شفاعمرو'],
        ], 'هند', $this->links());
        $this->assertStringContainsString('وصلت الاسم', $unverified['text']);
        $this->assertStringNotContainsString('تم تسجيلك', $unverified['text']);
        $this->assertStringNotContainsString('رقم الهوية', $unverified['text']);
        $this->assertFalse($unverified['pause']);
    }

    public function test_the_account_step_asks_for_the_id_and_never_refuses_it_for_privacy(): void
    {
        AppsPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام، ابعت اسمك الكامل والبلدة عشان نجهّز طلب الحساب. حفاظاً على خصوصيتك، ما تبعت رقم الهوية هون على الواتساب.',
            'pause' => true,
            'memory' => ['facts' => [], 'open' => [], 'links_sent' => []],
        ], JSON_UNESCAPED_UNICODE);

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'في عندك حساب داخل التطبيق؟'],
            ['role' => 'user', 'message' => 'لا'],
        ], 'تامر', $this->links());

        $this->assertStringContainsString('اسمك الكامل', $reply['text']);
        $this->assertStringNotContainsString('رقم الهوية', $reply['text']);
        $this->assertStringNotContainsString('خصوص', $reply['text']);
        $this->assertStringNotContainsString('ما تبعت', $reply['text']);
        $this->assertFalse($reply['pause']);

        AppsPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'معك حق، بعتذر عن اللخبطة. لا تبعت رقم الهوية هون على الواتساب، حفاظاً على خصوصيتك. هالجزء بحاجة لموظف مختص يوضّحلك طريقة آمنة لاستكمال طلب الحساب.',
            'pause' => true,
            'memory' => ['facts' => [], 'open' => [], 'links_sent' => []],
        ], JSON_UNESCAPED_UNICODE);

        $blocked = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'ابعث ولا مابعتش الهوية'],
        ], 'تامر', $this->links());

        $this->assertStringNotContainsString('خصوص', $blocked['text']);
        $this->assertStringNotContainsString('لا تبعت', $blocked['text']);
        $this->assertStringNotContainsString('موظف', $blocked['text']);
        $this->assertStringNotContainsString('رقم الهوية', $blocked['text']);
        $this->assertFalse($blocked['pause']);
    }

    public function test_handoff_pauses_and_a_link_alone_does_not(): void
    {
        AppsPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تفضل رابط الموحدة: '.AppsPrompt::MUWAHADA_URL,
            'pause' => false,
            'memory' => ['facts' => [], 'open' => [], 'links_sent' => ['muwahada']],
        ], JSON_UNESCAPED_UNICODE);
        $link = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'ابعث الموحدة'],
        ], 'هند', $this->links());
        $this->assertFalse($link['pause']);
        $this->assertStringContainsString(AppsPrompt::MUWAHADA_URL, $link['text']);
        $this->assertStringContainsString(AppsPrompt::MUWAHADA_APPLE_URL, $link['text']);

        AppsPrompt::$testResponder = fn (): string => json_encode([
            'reply' => AppsPrompt::HANDOFF,
            'pause' => false,
            'memory' => ['facts' => [], 'open' => [], 'links_sent' => []],
        ], JSON_UNESCAPED_UNICODE);
        $handoff = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'بدي أحكي مع شخص'],
        ], 'هند', $this->links());
        $this->assertTrue($handoff['pause']);
        $this->assertStringContainsString(AppsPrompt::HANDOFF, $handoff['text']);
    }

    public function test_unit_tests_do_not_call_the_provider(): void
    {
        Http::fake();

        $reply = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'مرحبا'],
        ], 'هند', $this->links());

        $this->assertSame(AppsPrompt::FALLBACK, $reply['text']);
        $this->assertFalse($reply['pause']);
        Http::assertNothingSent();
    }

    public function test_dashboard_groups_follow_what_the_person_said(): void
    {
        $downloaded = [
            ['role' => 'assistant', 'message' => 'عندك تطبيق أصواتكم؟'],
            ['role' => 'user', 'message' => 'نعم نزلته'],
            ['role' => 'assistant', 'message' => "وبالنسبة لتطبيق «الموحدة»، مطلوب تحميله عن طريق الرابط التالي:\nhttps://example.com/muw"],
        ];
        $waiting = [
            ['role' => 'assistant', 'message' => 'عندك تطبيق أصواتكم؟'],
            ['role' => 'user', 'message' => 'نعم'],
            ['role' => 'assistant', 'message' => 'للدخول للتطبيق انت بحاجة لاسم مستخدم، في معك اسم مستخدم؟'],
            ['role' => 'user', 'message' => 'لا'],
        ];
        $delegate = [
            ['role' => 'assistant', 'message' => 'عندك تطبيق أصواتكم؟'],
            ['role' => 'user', 'message' => 'لا ما بدي'],
            ['role' => 'assistant', 'message' => AppsPrompt::HANDOFF],
        ];
        $silent = [
            ['role' => 'assistant', 'message' => 'عندك تطبيق أصواتكم؟'],
        ];

        $this->assertTrue(AppsPrompt::profile($downloaded)['aswatkum_downloaded']);
        $this->assertTrue(AppsPrompt::profile($downloaded)['muwahada_downloaded']);
        $this->assertTrue(AppsPrompt::profile($waiting)['waiting_registration']);
        $this->assertTrue(AppsPrompt::profile($waiting)['aswatkum_downloaded']);
        $this->assertFalse(AppsPrompt::profile($delegate)['aswatkum_downloaded']);
        $this->assertTrue(AppsPrompt::profile($delegate)['waiting_delegate']);
        $this->assertTrue(AppsPrompt::profile($silent)['no_reply']);
        $this->assertSame(AppsPrompt::GROUP_NO_REPLY, AppsPrompt::result($silent)['group']);
        $this->assertContains(AppsPrompt::GROUP_WAITING, AppsPrompt::result($waiting)['groups']);
        $this->assertContains(AppsPrompt::GROUP_FOLLOW_UP, AppsPrompt::result($delegate)['groups']);
        $this->assertNotContains(AppsPrompt::GROUP_WAITING, AppsPrompt::result([
            ...$waiting,
            ['role' => 'assistant', 'message' => AppsPrompt::ACCOUNT_CREATED],
        ])['groups']);
        $this->assertContains(AppsPrompt::GROUP_WAITING, AppsPrompt::result([
            ...$waiting,
            ['role' => 'assistant', 'message' => 'تم تسجيلك داخل النظام.'],
        ])['groups']);
        $this->assertNotContains(AppsPrompt::GROUP_WAITING, AppsPrompt::result($waiting, ['account' => true])['groups']);
        $this->assertFalse(AppsPrompt::profile([
            ['role' => 'assistant', 'message' => 'عندك تطبيق أصواتكم؟'],
            ['role' => 'user', 'message' => AppsPrompt::SCREENSHOT_MARK],
        ])['waiting_registration']);
        $this->assertTrue(AppsPrompt::isFinished($downloaded));
        $this->assertFalse(AppsPrompt::isFinished($waiting));
        $held = [
            ['role' => 'assistant', 'message' => 'تمام عمر، وصلنا اسمك. شكرا على وقتك، رح نجهزلك الحساب وبنرجعلك.'],
            ['role' => 'user', 'message' => 'تمام شكرا'],
            ['role' => 'assistant', 'message' => 'العفو عمر، بنرجعلك بخصوص الحساب.'],
        ];
        $this->assertTrue(AppsPrompt::waitingOnStaff($held));
        $this->assertTrue(AppsPrompt::isFinished($held));
        $this->assertTrue(AppsPrompt::isFinished([
            ...$held,
            ['role' => 'assistant', 'message' => '؟'],
        ]));
        $this->assertSame('no', AppsPrompt::read($waiting)['username']);
        $this->assertSame('', AppsPrompt::nudge($waiting));
        $this->assertSame('؟', AppsPrompt::nudge([
            ['role' => 'user', 'message' => 'تمام'],
            ['role' => 'assistant', 'message' => 'هل نزّلت تطبيق «أصواتكم» على هاتفك؟'],
        ]));
        $this->assertSame('', AppsPrompt::nudge([
            ['role' => 'user', 'message' => 'أنا بالطريق'],
            ['role' => 'assistant', 'message' => 'تمام عماد، عندك حساب بأصواتكم؟ هلّا ركّز بالسواقة، ولما توقف بمكان آمن بنكمل.'],
        ]));
        $this->assertSame('', AppsPrompt::nudge([
            ['role' => 'user', 'message' => 'عندي بقال'],
            ['role' => 'assistant', 'message' => 'تمام عماد، فهمت إن عندك حساب، خليّك مركّز بالسواقة، وبنكمل لما توقف بمكان آمن.'],
        ]));
    }

    public function test_memory_is_stored_apart_from_the_whatsapp_transcript(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $conversation = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501111111@c.us',
            'contact_name' => 'هند',
            'contact_phone' => '972501111111',
            'title' => 'هند',
            'metadata' => ['mansour_revival_count' => 1],
        ]);

        AppsPrompt::storeMemory($conversation, [
            'facts' => ['نزل أصواتكم', '123456789'],
            'open' => ['وين اللائحة'],
            'links_sent' => ['aswatkum', 'nope'],
        ]);

        $conversation->refresh();
        $this->assertSame(1, $conversation->metadata['mansour_revival_count']);
        $this->assertSame(['نزل أصواتكم'], AppsPrompt::memoryOf($conversation)['facts']);
        $this->assertSame(['aswatkum'], AppsPrompt::memoryOf($conversation)['links_sent']);
        $this->assertFalse(AppsPrompt::memoryOf($conversation)['verified']['account']);
        $this->assertSame(1, AppsPrompt::memoryVersion($conversation));
        $this->assertFalse(AppsPrompt::storeMemory($conversation, [
            'facts' => ['نسخة قديمة'],
            'open' => [],
            'links_sent' => [],
            'preferences' => [],
        ], 0));
        $conversation->refresh();
        $this->assertSame(['نزل أصواتكم'], AppsPrompt::memoryOf($conversation)['facts']);
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'message' => 'نزل أصواتكم',
        ]);
    }

    public function test_chats_are_grouped_by_the_checklist(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'name' => 'بوت تطبيق أصواتكم',
            'integration_settings' => ['role' => 'apps', 'opening_message' => AppsPrompt::DEFAULT_OPENING],
        ])->save();

        $this->chat($bot, [
            ['assistant', 'عندك تطبيق أصواتكم؟'],
            ['user', 'نعم نزلته'],
        ]);
        $this->chat($bot, [
            ['assistant', 'عندك تطبيق أصواتكم؟'],
            ['user', 'لا'],
        ]);
        $this->chat($bot, [
            ['assistant', 'السلام عليكم'],
        ]);
        $this->chat($bot, [
            ['assistant', 'عندك تطبيق أصواتكم؟'],
            ['user', 'نعم'],
            ['assistant', 'عندك اسم مستخدم؟'],
            ['user', 'لا'],
            ['assistant', 'ابعتلي الاسم، البلدة، ورقم الهوية.'],
            ['user', 'يامن عامر'],
        ]);

        $summary = app(AppsInsightService::class)->summarize($bot->fresh());
        $this->assertSame(2, $summary['downloaded']);
        $this->assertSame(1, $summary['waiting_account']);
        $this->assertSame(1, $summary['no_reply']);
        $this->assertArrayNotHasKey('ready', $summary);
    }

    public function test_marking_a_waiting_number_sends_the_created_account_message(): void
    {
        $before = [
            ['role' => 'assistant', 'message' => 'عندك تطبيق أصواتكم؟'],
            ['role' => 'user', 'message' => 'نعم'],
            ['role' => 'assistant', 'message' => 'للدخول للتطبيق انت بحاجة لاسم مستخدم، في معك اسم مستخدم؟'],
            ['role' => 'user', 'message' => 'لا'],
            ['role' => 'assistant', 'message' => 'إذا بتكرم، بحتاج منك الاسم والبلدة ورقم الهوية لنجهّزلك الحساب.'],
            ['role' => 'user', 'message' => 'هند شفاعمرو 201233373'],
        ];
        $this->assertSame('201233373', AppsPrompt::identityNumber($before));
        $this->assertSame('', AppsPrompt::identityNumber([
            ['role' => 'user', 'message' => '525674181'],
        ]));
        $details = [
            ['role' => 'assistant', 'message' => 'هل نزّلت تطبيق «أصواتكم» على هاتفك؟'],
            ['role' => 'user', 'message' => "اه نزلتو بس في مشكله اني بسجل رقم الهوية وبقبلش يفوت عالتطبيق"],
            ['role' => 'user', 'message' => "محمود اسعيد\n208863480\nرمانة البطوف"],
        ];
        $this->assertContains(AppsPrompt::GROUP_WAITING, AppsPrompt::result($details)['groups']);
        $this->assertContains(AppsPrompt::GROUP_DOWNLOADED, AppsPrompt::result($details)['groups']);
        $this->assertSame('208863480', AppsPrompt::identityNumber($details));
        $omar = [
            ['role' => 'user', 'message' => "عمر خليل ابوكف\nام بطين\n325669273"],
            ['role' => 'assistant', 'message' => 'تمام عمر، وصلني اسمك الكامل. شكرا على وقتك، رح نجهزلك الحساب وبنرجعلك.'],
        ];
        $this->assertContains(AppsPrompt::GROUP_WAITING, AppsPrompt::result($omar)['groups']);
        $this->assertSame('325669273', AppsPrompt::identityNumber($omar));
        $this->assertContains(AppsPrompt::GROUP_WAITING, AppsPrompt::result($before)['groups']);
        $this->assertNotContains(AppsPrompt::GROUP_WAITING, AppsPrompt::result([
            ...$before,
            ['role' => 'assistant', 'message' => AppsPrompt::ACCOUNT_CREATED],
        ])['groups']);

        config(['whapi.provider' => 'greenapi']);
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'name' => 'بوت تطبيق أصواتكم',
            'integration_type' => 'mansour',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance1/sendMessage/key1',
            'integration_settings' => ['role' => 'apps', 'opening_message' => AppsPrompt::DEFAULT_OPENING],
        ])->save();
        $conversation = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972504533716@c.us',
            'contact_name' => 'هند',
            'contact_phone' => '972504533716',
            'title' => 'هند',
            'bot_mode' => ChatbotConversation::BOT_MODE_PAUSED,
        ]);
        foreach ($before as $turn) {
            ChatbotMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => $turn['role'],
                'sender_type' => $turn['role'] === 'assistant' ? 'ai' : 'customer',
                'message_type' => 'text',
                'message' => $turn['message'],
            ]);
        }

        Http::fake([
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'abc'], 200),
        ]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $bot))
            ->assertOk()
            ->assertSee('972504533716')
            ->assertSee('تم إنشاء المستخدم');

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.conversations.user-created', [$bot, $conversation]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertStringContainsString('جربي', AppsPrompt::accountCreatedFor('هند'));
        $this->assertStringContainsString('جرب هلأ', AppsPrompt::accountCreatedFor('فتحي'));

        $conversation->refresh();
        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $conversation->bot_mode);
        $this->assertTrue((bool) data_get($conversation->metadata, 'apps_verified.account'));
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => AppsPrompt::accountCreatedFor('هند'),
        ]);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'sendMessage')
                && ($request['message'] ?? '') === AppsPrompt::accountCreatedFor('هند');
        });
    }

    public function test_opening_and_configured_links_stay_on_the_instance(): void
    {
        $this->assertStringContainsString('بتواصل معك', AppsPrompt::DEFAULT_OPENING);
        $this->assertSame(1, mb_substr_count(AppsPrompt::DEFAULT_OPENING, '🙏'));

        $instance = new ChatbotInstance([
            'integration_settings' => [
                'aswatkum_url' => 'https://example.com/aswat',
                'muwahada_url' => 'https://example.com/muw',
                'guide_video_url' => 'https://example.com/video',
                'delegate_name' => 'سامي',
            ],
        ]);
        $links = AppsPrompt::links($instance);
        $this->assertSame('https://example.com/aswat', $links['aswatkum']);
        $this->assertSame('https://example.com/muw', $links['muwahada']);
        $opening = AppsPrompt::openingFor($instance, 'ليلى');
        $this->assertStringNotContainsString('السلام عليكم', $opening);
        $this->assertStringNotContainsString('ليلى', $opening);
        $this->assertStringContainsString('سامي', $opening);
        $this->assertSame('مرحبا محمود، كيف الأحوال.', AppsPrompt::greetingFor('محمود ناضم ريان'));
        $this->assertSame('مرحبا ابو محمد، كيف الأحوال.', AppsPrompt::greetingFor('ابو محمد'));
        $this->assertSame('مرحبا أبو محمد حسن، كيف الأحوال.', AppsPrompt::greetingFor('أبو محمد حسن'));
        $this->assertSame('مرحبا ابو محمد، كيف الأحوال.', AppsPrompt::greetingFor('ابومحمد'));
        $this->assertSame(AppsPrompt::GREETING, AppsPrompt::greetingFor('ابو'));
        $this->assertSame(AppsPrompt::GREETING, AppsPrompt::greetingFor('Fahed'));
        $this->assertSame('يسعد اوقاتك اخ ابو محمد شو الاخبار', AppsPrompt::followUpGreeting('ابو محمد'));
        $this->assertSame('يسعد اوقاتك اخ أبو محمد شو الاخبار', AppsPrompt::followUpGreeting('د. أبو محمد'));
        $this->assertSame('يسعد اوقاتك اخ أحمد شو الاخبار', AppsPrompt::followUpGreeting('د. أحمد علي'));
        $this->assertSame('يسعد اوقاتك اخ أحمد شو الاخبار', AppsPrompt::followUpGreeting('د.أحمد'));
        $this->assertSame('يسعد اوقاتك اخت ليلى شو الاخبار', AppsPrompt::followUpGreeting('د. ليلى حسن'));
        $this->assertSame('يسعد اوقاتك اخت ليلى شو الاخبار', AppsPrompt::followUpGreeting('د.ليلى'));
        $this->assertSame('يسعد اوقاتك اخ محمد شو الاخبار', AppsPrompt::followUpGreeting('الدكتور محمد علي'));
        $this->assertSame('يسعد اوقاتك اخت سارة شو الاخبار', AppsPrompt::followUpGreeting('دكتورة سارة'));
        $this->assertSame('يسعد اوقاتك اخت ام احمد شو الاخبار', AppsPrompt::followUpGreeting('ام احمد'));
        $this->assertSame(AppsPrompt::FOLLOW_UP_GREETING, AppsPrompt::followUpGreeting('ابو'));
        $this->assertSame(AppsPrompt::FOLLOW_UP_GREETING, AppsPrompt::followUpGreeting('د.'));
        $this->assertSame(AppsPrompt::FOLLOW_UP_GREETING, AppsPrompt::followUpGreeting('د. Ahmad'));
        $this->assertSame('يسعد اوقاتك اخ أبو محمد شو الاخبار', AppsPrompt::followUpGreeting('אבו מחמד'));
        $this->assertStringNotContainsString('ثلاث خطوات', AppsPrompt::greetingFor('محمود'));
        $this->assertStringContainsString('سامي', $opening);
        $this->assertSame(
            [AppsPrompt::ASWATKUM_ANDROID_URL, AppsPrompt::MUWAHADA_URL, AppsPrompt::GUIDE_VIDEO_URL],
            array_values(AppsPrompt::links(new ChatbotInstance)),
        );
    }

    public function test_a_reply_to_the_greeting_gets_the_opening_before_the_task(): void
    {
        AppsPrompt::$testResponder = function (): string {
            $this->fail('The model must not run before the opening is sent.');
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'مرحبا محمود، كيف الأحوال.'],
            ['role' => 'assistant', 'message' => '؟'],
            ['role' => 'user', 'message' => 'الحمد لله، تمام'],
        ], 'محمود ناضم ريان', $this->links());

        $this->assertStringNotContainsString('السلام عليكم', $reply['text']);
        $this->assertStringNotContainsString('أخي محمود', $reply['text']);
        $this->assertStringContainsString('بتواصل معك', $reply['text']);
        $this->assertStringContainsString('هل نزّلت تطبيق «الموحدة»', $reply['text']);
        $this->assertStringNotContainsString('أصواتكم', $reply['text']);
        $this->assertStringNotContainsString('لائحة', $reply['text']);
        $this->assertFalse($reply['pause']);

        $continued = false;
        AppsPrompt::$testResponder = function () use (&$continued): string {
            $continued = true;

            return json_encode([
                'reply' => 'تمام، في عندك حساب داخل التطبيق؟',
                'pause' => false,
                'memory' => ['facts' => [], 'open' => [], 'links_sent' => []],
            ], JSON_UNESCAPED_UNICODE);
        };

        $next = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'مرحبا محمود، كيف الأحوال.'],
            ['role' => 'user', 'message' => 'تمام'],
            ['role' => 'assistant', 'message' => $reply['text']],
            ['role' => 'user', 'message' => 'نزلته'],
        ], 'محمود', $this->links());

        $this->assertTrue($continued);
        $this->assertSame('تمام، في عندك حساب داخل التطبيق؟', $next['text']);
    }

    public function test_invalid_json_is_retried_once_and_plain_text_is_not_accepted(): void
    {
        $calls = 0;
        AppsPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return $calls === 1
                ? 'hello'
                : '{"reply":"منكمّل من نفس النقطة.","pause":false,"memory":{"facts":[],"open":[],"links_sent":[],"preferences":[]}}';
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'كمل'],
        ], 'هند', $this->links());

        $this->assertSame(2, $calls);
        $this->assertSame('منكمّل من نفس النقطة.', $reply['text']);
    }

    public function test_timeout_and_client_errors_do_not_advance_the_task(): void
    {
        $calls = 0;
        AppsPrompt::$testResponder = function () use (&$calls): array {
            $calls++;

            return ['error' => 'timeout'];
        };
        $timeout = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'كمل'],
        ], 'هند', $this->links());
        $this->assertSame(2, $calls);
        $this->assertSame(AppsPrompt::FALLBACK, $timeout['text']);
        $this->assertFalse($timeout['pause']);

        $calls = 0;
        AppsPrompt::$testResponder = function () use (&$calls): array {
            $calls++;

            return ['error' => 'http', 'status' => 400];
        };
        $rejected = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => AppsPrompt::ACCOUNT_QUESTION],
            ['role' => 'user', 'message' => 'لا'],
        ], 'هند', $this->links());
        $this->assertSame(1, $calls);
        $this->assertSame(AppsPrompt::FALLBACK, $rejected['text']);
    }

    public function test_opt_out_pauses_without_an_extra_question(): void
    {
        AppsPrompt::$testResponder = fn (): string => '{"reply":"تمام، بنوقف هون.","pause":true,"memory":{"facts":[],"open":[],"links_sent":[],"preferences":["بدّه يوقف"]}}';

        $reply = AppsPrompt::nextReply([
            ['role' => 'user', 'message' => 'وقف'],
        ], 'هند', $this->links());

        $this->assertSame('تمام، بنوقف هون.', $reply['text']);
        $this->assertTrue($reply['pause']);
        $this->assertSame(['بدّه يوقف'], $reply['memory']['preferences']);
    }

    public function test_duplicate_webhook_ids_are_stored_once_and_repeat_text_is_kept(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Http::fake([
            '7107.api.greenapi.com/*' => Http::response(['idMessage' => 'out-1'], 200),
        ]);
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'integration_type' => 'mansour',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance1/sendMessage/key1',
            'greenapi_webhook_token' => 'apps-webhook',
            'integration_settings' => ['role' => 'apps'],
            'is_active' => true,
        ])->save();
        \App\Models\AiChatbot\MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'هند',
            'phone' => '972509999999',
            'phone_normalized' => '972509999999',
            'chat_id' => '972509999999@c.us',
            'triggered_at' => now(),
        ]);

        $payload = [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972509999999@c.us', 'senderName' => 'هند'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'اندرويد'],
                'idMessage' => 'apps-dup-1',
            ],
        ];
        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'apps-webhook']), $payload)->assertOk();
        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'apps-webhook']), $payload)
            ->assertOk()
            ->assertJsonPath('ignored', true);
        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'apps-webhook']), [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => '972509999999@c.us', 'senderName' => 'هند'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'اندرويد'],
                'idMessage' => 'apps-dup-2',
            ],
        ])->assertOk();

        $this->assertSame(1, ChatbotMessage::query()->where('external_message_id', 'apps-dup-1')->count());
        $this->assertSame(2, ChatbotMessage::query()->where('message', 'اندرويد')->where('role', 'user')->count());
    }

    public function test_a_reply_to_the_follow_up_greeting_gets_the_opening(): void
    {
        AppsPrompt::$testResponder = function (): string {
            $this->fail('The model must not run before the opening is sent.');
        };

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ ابو محمد شو الاخبار'],
            ['role' => 'user', 'message' => 'الحمد لله'],
        ], 'ابو محمد', $this->links());

        $this->assertStringContainsString('هل نزّلت تطبيق «الموحدة»', $reply['text']);
        $this->assertStringNotContainsString('أصواتكم', $reply['text']);
        $this->assertStringNotContainsString('يسعد اوقاتك', $reply['text']);
    }

    public function test_a_number_source_question_returns_to_the_muwahada_download(): void
    {
        AppsPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام، بحوّلك لشخص من المركز يتابع معك بخصوص مصدر رقمك.',
            'pause' => true,
            'memory' => ['facts' => [], 'open' => [], 'links_sent' => [], 'preferences' => []],
        ], JSON_UNESCAPED_UNICODE);

        $reply = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => AppsPrompt::DEFAULT_OPENING],
            ['role' => 'user', 'message' => 'مين اعطاك رقمي'],
        ], 'أبو صالح', $this->links());

        $this->assertSame(AppsPrompt::NUMBER_SOURCE_REPLY, $reply['text']);
        $this->assertStringContainsString('تنزيل تطبيق «الموحدة»', $reply['text']);
        $this->assertStringNotContainsString('بحوّلك', $reply['text']);
        $this->assertFalse($reply['pause']);

        $accepted = AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'إذا بتحب، بحوّلك لشخص من المركز بخصوص هالموضوع.'],
            ['role' => 'user', 'message' => 'تفضل'],
        ], 'أبو صالح', $this->links());

        $this->assertSame(AppsPrompt::NUMBER_SOURCE_REPLY, $accepted['text']);
        $this->assertFalse($accepted['pause']);
    }

    public function test_a_new_trigger_ignores_the_old_aswatkum_thread(): void
    {
        $seen = '';
        AppsPrompt::$testResponder = function (array $messages) use (&$seen): string {
            $seen = json_encode($messages, JSON_UNESCAPED_UNICODE) ?: '';

            return '{"reply":"ابعتلي تم بس تنزّل الموحدة.","pause":false,"memory":{"facts":[],"open":[],"links_sent":[],"preferences":[]}}';
        };

        AppsPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'مرحبا محمود، كيف الأحوال.'],
            ['role' => 'user', 'message' => 'تمام'],
            ['role' => 'assistant', 'message' => 'هل نزّلت تطبيق «أصواتكم» على هاتفك؟'],
            ['role' => 'user', 'message' => 'نزلت أصواتكم وجهزت اللائحة'],
            ['role' => 'assistant', 'message' => 'مرحبا محمود، كيف الأحوال.'],
            ['role' => 'user', 'message' => 'اهلا'],
            ['role' => 'assistant', 'message' => AppsPrompt::DEFAULT_OPENING],
            ['role' => 'user', 'message' => 'لا'],
        ], 'محمود', $this->links());

        $this->assertStringNotContainsString('نزلت أصواتكم وجهزت اللائحة', $seen);
        $this->assertStringContainsString('لا', $seen);
        $this->assertStringContainsString('تنزيل تطبيق «الموحدة»', $seen);
    }

    public function test_silent_numbers_get_a_separate_follow_up_trigger(): void
    {
        Http::fake(['*' => Http::response(['idMessage' => '1'], 200)]);
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $farz = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $second = app(MansourBotService::class)->copy($farz, $user);
        $second->forceFill(['name' => 'منصور 2'])->save();
        $apps = app(MansourBotService::class)->ensureAppsBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $apps);
        $apps->forceFill([
            'greenapi_url' => 'https://7103.api.greenapi.com/waInstance1/sendMessage/token',
        ])->save();

        $silent = MansourContact::query()->create([
            'instance_id' => $apps->id,
            'name' => 'ابو محمد',
            'phone' => '0501234567',
            'phone_normalized' => '972501234567',
            'chat_id' => '972501234567@c.us',
        ]);
        $answered = MansourContact::query()->create([
            'instance_id' => $apps->id,
            'name' => 'ليلى',
            'phone' => '0507654321',
            'phone_normalized' => '972507654321',
            'chat_id' => '972507654321@c.us',
        ]);

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.trigger', $apps), [
                'contact_ids' => [$silent->id, $answered->id],
            ])
            ->assertRedirect();

        $answeredChat = $apps->conversations()->where('contact_phone', '0507654321')->firstOrFail();
        $answeredChat->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'message' => 'تمام',
        ]);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.mansour.followups', $apps))
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.id', $silent->id)
            ->assertJsonPath('contacts.0.preview', 'يسعد اوقاتك اخ ابو محمد شو الاخبار')
            ->assertJsonPath('contacts.0.nudged', false);

        $this->actingAs($user)
            ->withHeader('X-Mansour-Live', '1')
            ->postJson(route('ai-chatbot.workspace.mansour.trigger', $apps), [
                'contact_ids' => [$silent->id],
                'kind' => 'followup',
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.ok', true);

        $silent->refresh();
        $chat = $apps->conversations()->where('contact_phone', '0501234567')->firstOrFail();
        $this->assertSame(
            ['يسعد اوقاتك اخ ابو محمد شو الاخبار'],
            $chat->messages()->orderBy('id')->pluck('message')->all(),
        );
        $this->assertNotNull(data_get($chat->metadata, 'apps_followup_at'));

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.mansour.followups', $apps))
            ->assertOk()
            ->assertJsonPath('contacts.0.nudged', true);

        $this->actingAs($user)
            ->withHeader('X-Mansour-Live', '1')
            ->postJson(route('ai-chatbot.workspace.mansour.trigger', $apps), [
                'contact_ids' => [$answered->id],
                'kind' => 'followup',
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.ok', false)
            ->assertJsonPath('rows.0.code', 'followup_replied');

        $appsPage = $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $apps))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="apps-followup-open"', $appsPage);
        $this->assertStringContainsString('id="apps-followup-modal"', $appsPage);
        $this->assertStringContainsString('id="apps-followup-custom"', $appsPage);
        $this->assertStringContainsString('يسعد اوقاتك اخ أو اخت', $appsPage);

        $farzPage = $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $farz))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('id="apps-followup-open"', $farzPage);
    }

    public function test_a_typed_number_joins_the_no_reply_follow_up(): void
    {
        Http::fake(['*' => Http::response(['idMessage' => '1'], 200)]);
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $farz = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $second = app(MansourBotService::class)->copy($farz, $user);
        $second->forceFill(['name' => 'منصور 2'])->save();
        $apps = app(MansourBotService::class)->ensureAppsBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $apps);
        $apps->forceFill([
            'greenapi_url' => 'https://7103.api.greenapi.com/waInstance1/sendMessage/token',
        ])->save();

        $added = $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.contacts.custom', $apps), [
                'phone' => '0501111111',
                'name' => 'ابو علي',
                'followup' => 1,
            ])
            ->assertOk()
            ->json('contact.id');

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.mansour.followups', $apps))
            ->assertOk()
            ->assertJsonPath('contacts.0.id', $added)
            ->assertJsonPath('contacts.0.nudged', false);

        $this->actingAs($user)
            ->withHeader('X-Mansour-Live', '1')
            ->postJson(route('ai-chatbot.workspace.mansour.trigger', $apps), [
                'contact_ids' => [$added],
                'kind' => 'followup',
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.ok', true);

        $chat = $apps->conversations()->where('contact_phone', '972501111111')->firstOrFail();
        $this->assertStringContainsString('ابو علي', (string) $chat->messages()->value('message'));
        $this->assertStringContainsString('شو الاخبار', (string) $chat->messages()->value('message'));

        $silent = MansourContact::query()->create([
            'instance_id' => $apps->id,
            'name' => 'ليلى',
            'phone' => '0507654321',
            'phone_normalized' => '972507654321',
            'chat_id' => '972507654321@c.us',
            'triggered_at' => now(),
        ]);
        $answered = $apps->conversations()->create([
            'user_id' => $user->id,
            'title' => 'ليلى',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972507654321@c.us',
            'contact_phone' => '0507654321',
            'contact_name' => 'ليلى',
        ]);
        $silent->forceFill(['conversation_id' => $answered->id])->save();
        $answered->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'message' => 'تمام',
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.contacts.custom', $apps), [
                'phone' => '0507654321',
                'followup' => 1,
            ])
            ->assertStatus(422);
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     */
    private function systemText(array $messages): string
    {
        $chunks = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $chunks[] = (string) $message['content'];
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * @return array{aswatkum:string,muwahada:string,video:string}
     */
    private function links(): array
    {
        return [
            'aswatkum' => AppsPrompt::ASWATKUM_ANDROID_URL,
            'muwahada' => AppsPrompt::MUWAHADA_URL,
            'video' => AppsPrompt::GUIDE_VIDEO_URL,
        ];
    }

    /**
     * @param  list<array{0:string,1:string}>  $lines
     */
    private function chat(ChatbotInstance $bot, array $lines): void
    {
        $conversation = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $bot->user_id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '97250'.random_int(1000000, 9999999).'@c.us',
            'contact_name' => 'كادر',
            'contact_phone' => '972501234567',
            'title' => 'كادر',
        ]);
        foreach ($lines as [$role, $text]) {
            ChatbotMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => $role,
                'sender_type' => $role === 'assistant' ? 'ai' : 'customer',
                'message_type' => 'text',
                'message' => $text,
            ]);
        }
    }
}
