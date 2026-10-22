<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Services\AiChatbot\ChatbotTestService;
use App\Services\AiChatbot\DimaInsightService;
use App\Services\AiChatbot\DimaPrompt;
use App\Services\AiChatbot\MansourBotService;
use App\Services\AiChatbot\MansourConversationRevival;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DimaBotTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DimaPrompt::$testResponder = null;
        parent::tearDown();
    }

    public function test_dima_is_added_beside_farz_and_apps_without_rewriting_them(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $farz = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $farzPrompt = (string) $farz->system_prompt;
        $apps = app(MansourBotService::class)->copy($farz, $user);
        $apps->forceFill([
            'name' => 'بوت تطبيق أصواتكم',
            'system_prompt' => 'apps-prompt-marker',
            'integration_settings' => ['role' => 'apps', 'opening_message' => 'apps-opening'],
        ])->save();

        $dima = app(MansourBotService::class)->ensureDimaBot($user);

        $this->assertNotNull($dima);
        $this->assertTrue($dima->isDimaBot());
        $this->assertSame('ديما', $dima->name);
        $this->assertSame($farzPrompt, (string) $farz->fresh()->system_prompt);
        $this->assertSame('apps-prompt-marker', (string) $apps->fresh()->system_prompt);
        $this->assertFalse($apps->fresh()->isDimaBot());
        $this->assertSame($dima->id, app(MansourBotService::class)->ensureDimaBot($user)?->id);
    }

    public function test_prompt_opens_as_a_poll_center_and_persuades_later(): void
    {
        $prompt = DimaPrompt::instructions();

        $this->assertStringContainsString('مركز استطلاعات', $prompt);
        $this->assertStringContainsString('الرسالة الاولى هي هالتعريف', $prompt);
        $this->assertStringContainsString('بنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي', $prompt);
        $this->assertStringContainsString('ممنوع تشكيل', $prompt);
        $this->assertStringNotContainsString('«', $prompt);
        $guide = \App\Services\AiChatbot\DimaKnowledge::corpus();
        $this->assertStringContainsString('רע״ם', $prompt);
        $this->assertStringContainsString('الضرورات تبيح المحظورات', $guide);
        $this->assertStringContainsString('مستشفى سخنين', $guide);
        $this->assertStringContainsString('نحو 1000 قتيل', $prompt);
        $this->assertStringContainsString('بلا جمل محفوظه', $prompt);
        $this->assertStringContainsString('العمق بس لما هو يفتحه', $prompt);
        $this->assertStringContainsString('قرار 549', $guide);
        $this->assertStringContainsString('مش 60 بالمية من الـ30 مليار', $guide);
        $this->assertStringNotContainsString('طب ليش مقاطع', $prompt);
        $this->assertStringNotContainsString('أنا من مكتب', $prompt);
        $this->assertStringContainsString('بفهم اعتراضك', $prompt);
        $this->assertStringContainsString('ما صوتوا مع القانون', $guide);
        $this->assertStringContainsString('ترتيب مقاعد', $guide);
        $this->assertStringContainsString('ايموجي واحد مألوف', $prompt);
        $this->assertStringContainsString('بسدر', $prompt);
        $this->assertStringContainsString('كلمات عبري بحروف عربي', $prompt);
        $this->assertStringContainsString('مرة ثانية', $prompt);
        $this->assertStringContainsString('لا بديش، حتى لو لحقها كلمة', $prompt);
    }

    public function test_a_short_no_with_an_extra_word_stays_open_and_sends_the_video(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام، تسلم إنك حكيت موقفك بصراحة.',
            'stance' => DimaPrompt::REFUSED,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تطلع تصوت هالمرة؟'],
                ['role' => 'user', 'message' => 'لا بديش يخلي'],
            ], 'يامن', ['stance' => DimaPrompt::OPEN]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['video']);
        $this->assertFalse($decision['close']);
        $this->assertNotSame(DimaPrompt::REFUSED, $decision['stance']);
        $this->assertStringContainsString('1000 قتيل', $decision['text']);
        $this->assertStringNotContainsString('موقفك', $decision['text']);
    }

    public function test_an_argument_after_the_video_keeps_pushing_and_stays_open(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'الوضع تقيل، والقعدة بالبيت ما بتغير مين بيحكم.',
            'stance' => DimaPrompt::REFUSED,
            'note' => '',
            'close' => true,
            'memory' => [
                'known' => 'مش مقتنع بالتصويت',
                'topic' => 'رفض التصويت',
                'answered' => 'ناوي يصوت؟ لا',
                'open_question' => '',
                'concern' => 'على الفاضي',
                'wants_to_continue' => true,
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'وبعد ها الفيديو شو قلت؟'],
                ['role' => 'user', 'message' => 'على الفاضي، مش مقتنع'],
            ], 'أحمد', ['stance' => DimaPrompt::OPEN]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertFalse($decision['video']);
        $this->assertFalse($decision['close']);
        $this->assertNotSame(DimaPrompt::REFUSED, $decision['stance']);
        $this->assertSame('الوضع تقيل، والقعدة بالبيت ما بتغير مين بيحكم.', $decision['text']);
        $this->assertSame('مش مقتنع بالتصويت', $decision['memory']['known']);
        $this->assertFalse(str_contains($decision['text'], '؟'));
    }

    public function test_the_second_stop_request_closes(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'آسفة، بوقف هون.',
            'stance' => DimaPrompt::REFUSED,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'خلي علي'],
                ['role' => 'assistant', 'message' => 'ثانية بس. بتطلع تصوت؟'],
                ['role' => 'user', 'message' => 'خلي علي'],
            ], 'علي', ['stance' => DimaPrompt::OPEN]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['close']);
        $this->assertSame(DimaPrompt::REFUSED, $decision['stance']);
    }

    public function test_the_trigger_greeting_varies_and_keeps_the_name(): void
    {
        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $line = DimaPrompt::openingFor('يامن');
            $seen[$line] = true;
            $this->assertStringContainsString('يامن', $line);
            $this->assertStringContainsString('شو الاخبار', $line);
        }

        $this->assertGreaterThanOrEqual(2, count($seen));
        $this->assertTrue(isset($seen['مرحبا يامن، شو الاخبار']) || isset($seen['يسعد اوقاتك اخ يامن شو الاخبار']));
    }

    public function test_dima_typing_pause_is_half_the_configured_wait(): void
    {
        $short = \App\Services\AiChatbot\MansourHumanDelay::millisecondsForText('أهلا', true);
        $long = \App\Services\AiChatbot\MansourHumanDelay::millisecondsForText(str_repeat('بدي أحكي معك شوي عن التصويت. ', 12), true);

        $this->assertGreaterThanOrEqual(600, $short);
        $this->assertLessThanOrEqual(1000, $short);
        $this->assertGreaterThanOrEqual(5000, $long);
        $this->assertLessThanOrEqual(8500, $long);
        $this->assertGreaterThan($short, $long);
    }

    public function test_model_reply_is_stored_with_the_logical_stance(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $dima = app(MansourBotService::class)->ensureDimaBot($user);
        $this->assertNotNull($dima);
        $chat = ChatbotConversation::query()->create([
            'instance_id' => $dima->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501111111@c.us',
            'contact_name' => 'غازي',
            'contact_phone' => '972501111111',
            'title' => 'غازي',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $chat->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'message' => 'يسعد اوقاتك اخ غازي شو الاخبار',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $chat->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'message' => 'ما بصوت، الانتخابات حرام',
        ]);

        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'فاهمك غازي، والتزامك محترم. بس خليني أسألك سؤال صغير بهالخصوص.',
            'stance' => DimaPrompt::RELIGIOUS,
            'note' => 'شايف التصويت حرام',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        $decision = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ غازي شو الاخبار'],
            ['role' => 'user', 'message' => 'هلا'],
            ['role' => 'assistant', 'message' => 'بتسمحلي أسألك سؤال صغير؟'],
            ['role' => 'user', 'message' => 'ما بصوت، الانتخابات حرام'],
        ], 'غازي');
        DimaPrompt::remember($chat, $decision['stance'], $decision['note'], $decision['close']);

        $this->assertStringContainsString('فاهمك غازي', $decision['text']);
        $this->assertSame(DimaPrompt::RELIGIOUS, DimaPrompt::read($chat->fresh())['stance']);
        $summary = app(DimaInsightService::class)->summarize($dima);
        $this->assertSame(1, $summary[DimaPrompt::RELIGIOUS]);
        $this->assertSame(0, $summary[DimaPrompt::UNITED]);

        $quiet = ChatbotConversation::query()->create([
            'instance_id' => $dima->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972502222222@c.us',
            'contact_name' => 'ليلى',
            'contact_phone' => '972502222222',
            'title' => 'ليلى',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $quiet->id,
            'role' => 'assistant',
            'message' => 'يسعد اوقاتك اخت ليلى شو الاخبار',
            'sender_type' => 'ai',
            'message_type' => 'text',
        ]);
        $summary = app(DimaInsightService::class)->summarize($dima->fresh());
        $this->assertSame(1, $summary[DimaPrompt::NO_REPLY]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $dima))
            ->assertOk()
            ->assertSee('مقاطع ديني')
            ->assertSee('مقاطع هوية')
            ->assertSee('بلا إنجاز')
            ->assertSee('بالحديث')
            ->assertSee('حزب صهيوني');
    }

    public function test_a_closed_dima_chat_does_not_take_the_farz_nudge(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $dima = app(MansourBotService::class)->ensureDimaBot($user);
        $this->assertNotNull($dima);
        $chat = ChatbotConversation::query()->create([
            'instance_id' => $dima->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972503333333@c.us',
            'contact_name' => 'سامر',
            'contact_phone' => '972503333333',
            'title' => 'سامر',
            'metadata' => ['dima' => ['stance' => DimaPrompt::REFUSED, 'note' => 'طلب يوقف', 'close' => true]],
        ]);
        $assistant = ChatbotMessage::query()->create([
            'conversation_id' => $chat->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'message' => 'تمام سامر، آسفة على الإزعاج.',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $chat->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'message' => 'ما تكمل',
        ]);

        $this->assertTrue(DimaPrompt::isClosed($chat->fresh()));
        app(MansourConversationRevival::class)->send((int) $chat->id, (int) $assistant->id);

        $this->assertSame(2, ChatbotMessage::query()->where('conversation_id', $chat->id)->count());
    }

    public function test_naming_another_list_cannot_close_the_chat(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام، بحترم خيارك. تسلم إنك شاركتني رأيك، ويعطيك العافية.',
            'stance' => DimaPrompt::JOINT,
            'note' => 'مشتركة',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ يامن شو الاخبار'],
                ['role' => 'user', 'message' => 'بس مشتركه'],
            ], 'يامن');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame(DimaPrompt::JOINT, $decision['stance']);
        $this->assertFalse($decision['close']);
        $this->assertStringContainsString('تسمية القائمة بداية الحكي', DimaPrompt::instructions());
    }

    public function test_a_steering_confession_stays_in_character_and_keeps_the_chat_open(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'ما بقدر أوجهك للتصويت للموحدة بناء على موقفك الديني',
            'stance' => DimaPrompt::REFUSED,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'بحترم التزامك'],
                ['role' => 'user', 'message' => 'يعني انت بدك اياي اصوت للموحدة؟'],
            ], 'خالد', ['stance' => DimaPrompt::RELIGIOUS]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('1000 قتيل', DimaPrompt::instructions());
        $this->assertStringContainsString('مصلحة شعبي', $decision['text']);
        $this->assertStringNotContainsString('أوجهك', $decision['text']);
        $this->assertSame(DimaPrompt::RELIGIOUS, $decision['stance']);
        $this->assertFalse($decision['close']);
    }

    public function test_refusing_to_vote_gets_the_push_about_the_government_and_the_thousand(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'والله الجو تقيل، بس اذا قعدنا بالبيت ما رح يتغير اشي. بتطلع تصوت؟',
            'stance' => DimaPrompt::NO_GAIN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ خالد شو الاخبار'],
                ['role' => 'user', 'message' => 'هلا'],
                ['role' => 'assistant', 'message' => 'بتسمحلي أسألك سؤال صغير؟'],
                ['role' => 'user', 'message' => 'تفضلي'],
                ['role' => 'assistant', 'message' => 'ناوي تطلع تصوت هالمرة؟'],
                ['role' => 'user', 'message' => 'كله عالفاضي'],
            ], 'خالد', ['stance' => DimaPrompt::OPEN]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('نتيجة', $decision['text']);
        $this->assertStringNotContainsString('بتطلع تصوت', $decision['text']);
        $this->assertStringNotContainsString('1000 قتيل', $decision['text']);
        $this->assertFalse($decision['video']);
        $this->assertFalse($decision['close']);
    }

    public function test_a_drifted_opening_is_put_back_on_the_poll_center_sentence(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'بعرف إنه تواصلي معك إجا فجأة، والحكي هون عن الأوضاع بمجتمعنا العربي والانتخابات. بتسمحلي أسألك سؤال صغير؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ خالد شو الاخبار'],
                ['role' => 'user', 'message' => 'هلا'],
            ], 'خالد', []);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('مركز استطلاعات', $decision['text']);
        $this->assertStringNotContainsString('انتخابات', $decision['text']);
    }

    public function test_the_vote_question_never_uses_mayel(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام. لمين مايل هالمرة؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'هلا'],
                ['role' => 'assistant', 'message' => 'ناوي تطلع تصوت؟'],
                ['role' => 'user', 'message' => 'اه ناوي'],
            ], 'خالد', []);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringNotContainsString('مايل', $decision['text']);
        $this->assertStringContainsString('لمين رح تصوت المره هاي؟', $decision['text']);
        $this->assertStringContainsString('تمام', $decision['text']);
    }

    public function test_a_united_vote_ends_with_thanks(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تسلم على وقتك ومشاركتك، بسلمك الماية وسلامات',
            'stance' => DimaPrompt::UNITED,
            'note' => 'مصوت للموحدة',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'هلا'],
                ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
                ['role' => 'user', 'message' => 'للموحده اكيد'],
            ], 'يامن', []);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['close']);
        $this->assertSame('تمام شكرا الك كتير على وقتك، نهارك سعيد 🌷', $decision['text']);
    }

    public function test_an_invented_reunification_vote_is_replaced_by_the_guide(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'بفهم اعتراضك، بتصويت 6 تموز 2021 نائبين أيدوا ونائبين امتنعوا والنتيجة 59 مع.',
            'stance' => DimaPrompt::JOINT,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'هلا'],
                ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
                ['role' => 'user', 'message' => 'للمشتركة'],
                ['role' => 'user', 'message' => 'عندهم قانون لم الشمل وقفوا ضده'],
            ], 'خالد', ['stance' => DimaPrompt::JOINT]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('امتنعوا', $decision['text']);
        $this->assertStringContainsString('وزارة الداخلية', $decision['text']);
        $this->assertStringNotContainsString('تموز', $decision['text']);
        $this->assertStringNotContainsString('بفهم اعتراضك', $decision['text']);
        $this->assertFalse($decision['close']);
    }

    public function test_a_repeated_respect_opening_is_dropped(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'بحترم موقفك، اربعة مقاعد جوا وقفت الهدم. بدك التأثير من جوا؟',
            'stance' => DimaPrompt::JOINT,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'هلا'],
                ['role' => 'assistant', 'message' => 'بحترم اختيارك، مع حزب بعينه ولا التجميع؟'],
                ['role' => 'user', 'message' => 'بدي الناس تتجمع'],
            ], 'خالد', ['stance' => DimaPrompt::JOINT]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertFalse(str_starts_with($decision['text'], 'بحترم'));
        $this->assertStringContainsString('اربعة مقاعد', $decision['text']);
    }

    public function test_same_result_for_years_is_not_answered_as_a_crime_budget(): void
    {
        $calls = 0;
        DimaPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return json_encode([
                'reply' => 'قرار 549 خطة ثانية للجريمة، منفصلة عن 550. كراس الكادر بيحكي عن نحو 2.5 مليار موافقة، ومش عندي رقم مصروف لهاي الخطة.',
                'stance' => DimaPrompt::OPEN,
                'note' => '',
                'close' => false,
            ], JSON_UNESCAPED_UNICODE);
        };

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'بقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟'],
                ['role' => 'user', 'message' => 'كله علفاضي يختي حسب رايي نفس النتيجه رح تظل انا 70 سنه عنفس المولويل.'],
            ], 'Test customer');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame(1, $calls);
        $this->assertFalse($decision['close']);
        $this->assertStringContainsString('ميزانيات', $decision['text']);
        $this->assertStringContainsString('قلة التصويت', $decision['text']);
        $this->assertStringContainsString('صوتك لازم يكون للموحدة', $decision['text']);
        $this->assertStringNotContainsString('549', $decision['text']);
        $this->assertStringNotContainsString('الجريمة', $decision['text']);
    }

    public function test_dismissing_both_arab_lists_is_answered_with_the_turnout_case(): void
    {
        $calls = 0;
        DimaPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return json_encode([
                'reply' => 'إنت بتحكي عن غياب النتائج عند الاثنين، مش بس عن أسماء القيادات. الوعد لحاله مش إنجاز.',
                'stance' => DimaPrompt::NO_GAIN,
                'note' => '',
                'close' => false,
            ], JSON_UNESCAPED_UNICODE);
        };

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
                ['role' => 'user', 'message' => 'لا مش رح اصوت'],
                ['role' => 'assistant', 'message' => 'وبعد ها الفيديو شو قلت؟'],
                ['role' => 'user', 'message' => 'مش مقتنع يولا قياده لا المشتركه ولا الموحده ثين بجيبوش نتائج وكل مره نفس الشي'],
            ], 'Test customer');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame(1, $calls);
        $this->assertFalse($decision['close']);
        $this->assertFalse($decision['video']);
        $this->assertStringContainsString('ميزانيات', $decision['text']);
        $this->assertStringContainsString('قلة التصويت', $decision['text']);
        $this->assertStringContainsString('مثل الموحدة', $decision['text']);
        $this->assertStringContainsString('صوتك لازم يكون للموحدة', $decision['text']);
        $this->assertStringNotContainsString('غياب النتائج', $decision['text']);
        $this->assertStringNotContainsString('الوعد لحاله', $decision['text']);
    }

    public function test_an_absolute_no_still_gets_the_video_when_the_model_is_empty(): void
    {
        DimaPrompt::$testResponder = fn (): string => '';

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
                ['role' => 'user', 'message' => 'لا بتاتا'],
            ], 'Test customer');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertNotSame('', trim($decision['text']));
        $this->assertTrue($decision['video']);
        $this->assertStringContainsString('1000', $decision['text']);
        $this->assertStringContainsString('الفيديو', $decision['text']);
        $this->assertFalse($decision['close']);
    }

    public function test_an_explicit_no_keeps_the_model_line_and_attaches_the_video(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => "وبعد ها الفيديو شو قلت؟ بدك نعيش كمان 4 سنين بهالحكومة العنصرية؟\n4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.\nبقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟",
            'stance' => DimaPrompt::NO_GAIN,
            'note' => 'رفض التصويت',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تطلع تصوت هالمرة؟'],
                ['role' => 'user', 'message' => 'لا بديش'],
            ], 'علي', ['stance' => DimaPrompt::OPEN]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['video']);
        $this->assertStringContainsString('1000 قتيل', $decision['text']);
        $this->assertStringContainsString('الفيديو', $decision['text']);
        $this->assertStringNotContainsString('إجراءات', $decision['text']);
        $this->assertFalse($decision['close']);
    }

    public function test_a_reply_that_ignores_the_video_is_tied_back_to_it(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'الهتاف العنصري بالفيديو مرفوض، بس قرار المشاركه بالتصويت إلك. شو الي خلاك تقرر ما تصوت؟',
            'stance' => DimaPrompt::NO_GAIN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تطلع تصوت هالمرة؟'],
                ['role' => 'user', 'message' => 'لا'],
            ], 'علي', ['stance' => DimaPrompt::OPEN]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['video']);
        $this->assertStringContainsString('1000 قتيل', $decision['text']);
        $this->assertStringContainsString('الفيديو', $decision['text']);
        $this->assertStringContainsString('تقاطع', $decision['text']);
        $this->assertStringNotContainsString('مرفوض', $decision['text']);
        $this->assertStringNotContainsString('إلك', $decision['text']);
    }

    public function test_the_first_leave_me_alone_stays_open_and_tries_once(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'آسفة عالزعل، بوقف هون.',
            'stance' => DimaPrompt::REFUSED,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تطلع تصوت هالمرة؟'],
                ['role' => 'user', 'message' => 'لا خلي علي'],
            ], 'علي', ['stance' => DimaPrompt::OPEN]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['close']);
        $this->assertSame(DimaPrompt::REFUSED, $decision['stance']);
        $this->assertStringNotContainsString('تصوت', $decision['text']);
        $this->assertStringNotContainsString('بوقف', $decision['text']);
    }

    public function test_the_test_chat_stores_the_video_before_the_no_vote_line(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $dima = app(MansourBotService::class)->ensureDimaBot($user);
        $this->assertNotNull($dima);
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => "وبعد ها الفيديو شو قلت؟ بدك نعيش كمان 4 سنين بهالحكومة العنصرية؟\n4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.\nبقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟",
            'stance' => DimaPrompt::NO_GAIN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        $result = app(ChatbotTestService::class)->run($user, $dima, [
            'message' => 'لا بديش',
            'reset' => true,
            'channel' => ChatbotConversation::CHANNEL_TEST,
        ]);

        $this->assertTrue($result['ok']);
        $messages = $result['messages'];
        $videoAt = null;
        $lineAt = null;
        foreach ($messages as $index => $message) {
            if (($message['is_video'] ?? false) === true) {
                $videoAt = $index;
            }
            if (str_contains((string) $message['message'], '1000 قتيل')) {
                $lineAt = $index;
            }
        }
        $this->assertNotNull($videoAt);
        $this->assertNotNull($lineAt);
        $this->assertLessThan($lineAt, $videoAt);
        $this->assertStringContainsString('1000 قتيل', (string) $messages[$lineAt]['message']);
    }

    public function test_asking_who_this_is_stays_dima_and_keeps_the_chat_going(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'معك ديما، والتواصل عن الانتخابات من موظف مؤيد للموحده، مش استطلاع محايد. بدك تكمل؟',
            'stance' => DimaPrompt::REFUSED,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ علي شو الاخبار'],
                ['role' => 'user', 'message' => 'الحمد لله'],
                ['role' => 'user', 'message' => 'مين معي'],
            ], 'علي', ['stance' => DimaPrompt::OPEN]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('مركز اتصالات القائمة العربية الموحدة', $decision['text']);
        $this->assertStringNotContainsString('بتسمحلي', $decision['text']);
        $this->assertStringNotContainsString('موظف', $decision['text']);
        $this->assertStringNotContainsString('بدك تكمل', $decision['text']);
        $this->assertStringNotContainsString('محايد', $decision['text']);
        $this->assertTrue($decision['identity']['organization']);
        $this->assertFalse($decision['close']);
        $this->assertNotSame(DimaPrompt::REFUSED, $decision['stance']);
    }

    public function test_context_is_remembered_and_only_the_related_guide_is_used(): void
    {
        $seen = '';
        DimaPrompt::$testResponder = function (array $messages) use (&$seen): string {
            $seen = (string) ($messages[1]['content'] ?? '');

            return json_encode([
                'reply' => 'المشتركة غير عن الموحدة، والخلاف كان ترتيب مقاعد.',
                'stance' => DimaPrompt::JOINT,
                'note' => 'مشتركة',
                'close' => false,
                'memory' => [
                    'known' => 'بصوت للمشتركة',
                    'topic' => 'المشتركة',
                    'answered' => 'لمين: المشتركة',
                    'open_question' => '',
                    'concern' => '',
                    'wants_to_continue' => true,
                ],
            ], JSON_UNESCAPED_UNICODE);
        };

        $decision = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'بصوت للمشتركة'],
        ], 'خالد', [
            'stance' => DimaPrompt::OPEN,
            'memory' => [
                'known' => 'ناوي يصوت',
                'topic' => 'التصويت',
                'answered' => 'ناوي يصوت: نعم',
                'open_question' => 'لمين',
                'concern' => '',
                'wants_to_continue' => true,
            ],
        ]);

        $this->assertStringContainsString('ناوي يصوت: نعم', $seen);
        $this->assertStringContainsString('ترتيب مقاعد', $decision['text']);
        $this->assertStringNotContainsString('لمين رح تصوت', $decision['text']);
        $this->assertFalse($decision['close']);
        $this->assertSame('بصوت للمشتركة', $decision['memory']['known']);

        $reunification = \App\Services\AiChatbot\DimaKnowledge::retrieve('عندهم قانون لم الشمل');
        $this->assertStringContainsString('امتنعوا', $reunification);
        $this->assertStringNotContainsString('مستشفى سخنين', $reunification);

        $hospital = \App\Services\AiChatbot\DimaKnowledge::retrieve('شو قصة مستشفى سخنين');
        $this->assertStringContainsString('سخنين', $hospital);
        $this->assertStringNotContainsString('لم الشمل', $hospital);

        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'بسدر، يعني تمام. بس التصويت غير الحكي.',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);
        $mixed = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'هلا'],
            ['role' => 'assistant', 'message' => 'بتسمحلي أسألك سؤال صغير؟'],
            ['role' => 'user', 'message' => 'بسدر، I dont care'],
        ], 'خالد');
        $this->assertSame('بسدر، يعني تمام. بس التصويت غير الحكي.', $mixed['text']);
        $this->assertFalse($mixed['close']);

        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'ما بوافقك بهاي النقطة.',
            'stance' => DimaPrompt::REFUSED,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);
        $disagreement = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'هلا'],
            ['role' => 'assistant', 'message' => 'الصوت بجيب ميزانيات.'],
            ['role' => 'user', 'message' => 'لا مو هيك'],
        ], 'خالد');
        $this->assertSame('ما بوافقك بهاي النقطة.', $disagreement['text']);
        $this->assertFalse($disagreement['close']);
        $this->assertNotSame(DimaPrompt::REFUSED, $disagreement['stance']);
    }

    public function test_consecutive_messages_are_one_turn_when_the_reason_is_religious(): void
    {
        $turns = [
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'لأ'],
            ['role' => 'user', 'message' => 'مش رح أصوت'],
            ['role' => 'user', 'message' => 'حرام شرعا'],
        ];
        $this->assertFalse(DimaPrompt::explicitNoVote($turns));

        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'الضرورات تبيح المحظورات. نحو 1000 قتيل باربع سنين، ومئات البيوت انهدمت. ناوي تصوت هالمرة؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply($turns, 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame(DimaPrompt::RELIGIOUS, $decision['stance']);
        $this->assertFalse($decision['close']);
        $this->assertFalse($decision['video']);
        $this->assertStringNotContainsString('1000', $decision['text']);
        $this->assertStringNotContainsString('ناوي', $decision['text']);
        $this->assertLessThan(120, mb_strlen($decision['text']));
    }

    public function test_consecutive_messages_about_achievements_are_not_a_flat_refusal(): void
    {
        $turns = [
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'لا'],
            ['role' => 'user', 'message' => 'مش مقتنع'],
            ['role' => 'user', 'message' => 'ما في إنجازات'],
        ];
        $this->assertFalse(DimaPrompt::explicitNoVote($turns));

        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'ناوي تصوت ولا لأ؟',
            'stance' => DimaPrompt::NO_GAIN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply($turns, 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertFalse($decision['video']);
        $this->assertFalse($decision['close']);
        $this->assertStringNotContainsString('ناوي تصوت', $decision['text']);
        $this->assertStringContainsString('ميزانيات', $decision['text']);
    }

    public function test_consecutive_messages_naming_the_joint_list_keep_the_short_reply(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام، المشتركة. الخلاف كان على ترتيب المقاعد.',
            'stance' => DimaPrompt::JOINT,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
                ['role' => 'user', 'message' => 'رح أصوت'],
                ['role' => 'user', 'message' => 'للمشتركة'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame('تمام، المشتركة. الخلاف كان على ترتيب المقاعد.', $decision['text']);
        $this->assertSame(DimaPrompt::JOINT, $decision['stance']);
        $this->assertFalse($decision['close']);
        $this->assertFalse($decision['video']);
        $this->assertStringNotContainsString('ناوي', $decision['text']);
    }

    public function test_consecutive_messages_that_ask_to_stop_close_without_another_question(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'بتطلع تصوت قبل ما أوقف؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
                ['role' => 'user', 'message' => 'مش مهتم'],
                ['role' => 'user', 'message' => 'خلص لا تبعتلي'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['close']);
        $this->assertSame(DimaPrompt::REFUSED, $decision['stance']);
        $this->assertFalse($decision['video']);
        $this->assertStringNotContainsString('تصوت', $decision['text']);
        $this->assertLessThan(80, mb_strlen($decision['text']));
    }

    public function test_consent_after_the_intro_asks_the_voting_question_once(): void
    {
        $intro = [
            ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ أحمد شو الاخبار'],
            ['role' => 'user', 'message' => 'هلا'],
            ['role' => 'assistant', 'message' => "بعرف إنه تواصلي معك إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\nبتسمحلي أسألك سؤال صغير؟"],
        ];
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'كيف شايف الأوضاع بمجتمعنا العربي هالفترة؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            foreach (['تفضل', 'احكي', 'اه', 'نعم', 'اسأل', 'أكيد', 'شو السؤال'] as $consent) {
                $decision = DimaPrompt::nextReply([
                    ...$intro,
                    ['role' => 'user', 'message' => $consent],
                ], 'أحمد', ['stage' => DimaPrompt::STAGE_INTRODUCTION]);

                $this->assertSame('ناوي تصوت بالانتخابات الجاي؟', $decision['text'], $consent);
                $this->assertSame(DimaPrompt::STAGE_VOTING_INTENTION_ASKED, $decision['stage'], $consent);
                $this->assertStringNotContainsString('أوضاع', $decision['text']);
                $this->assertStringNotContainsString('مجتمع', $decision['text']);
                $this->assertFalse($decision['close']);
                $this->assertFalse($decision['video']);
            }

            $again = DimaPrompt::nextReply([
                ...$intro,
                ['role' => 'user', 'message' => 'تفضل'],
                ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
                ['role' => 'user', 'message' => 'رح أصوت'],
                ['role' => 'user', 'message' => 'للمشتركة'],
            ], 'أحمد', ['stage' => DimaPrompt::STAGE_VOTING_INTENTION_ASKED]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame('كيف شايف الأوضاع بمجتمعنا العربي هالفترة؟', $again['text']);
        $this->assertNotSame(DimaPrompt::STAGE_VOTING_INTENTION_ASKED, $again['stage']);
        $this->assertStringNotContainsString('ناوي تصوت بالانتخابات', $again['text']);
    }

    public function test_a_spontaneous_voting_intention_skips_the_opening_question(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'تمام. لمين رح تصوت المره هاي؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'بتسمحلي أسألك سؤال صغير؟'],
                ['role' => 'user', 'message' => 'رح أصوت'],
            ], 'أحمد', ['stage' => DimaPrompt::STAGE_INTRODUCTION]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame('تمام. لمين رح تصوت المره هاي؟', $decision['text']);
        $this->assertStringNotContainsString('ناوي تصوت بالانتخابات', $decision['text']);
        $this->assertSame(DimaPrompt::STAGE_RESPONSE_RECEIVED, $decision['stage']);
    }

    public function test_the_voting_question_stage_is_stored_on_the_conversation(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $dima = app(MansourBotService::class)->ensureDimaBot($user);
        $chat = ChatbotConversation::query()->create([
            'instance_id' => $dima->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972504444444@c.us',
            'contact_name' => 'أحمد',
            'contact_phone' => '972504444444',
            'title' => 'أحمد',
        ]);

        DimaPrompt::remember($chat, DimaPrompt::OPEN, '', false, [], DimaPrompt::STAGE_VOTING_INTENTION_ASKED);

        $this->assertSame(DimaPrompt::STAGE_VOTING_INTENTION_ASKED, DimaPrompt::read($chat->fresh())['stage']);
    }

    public function test_a_likud_supporter_gets_the_record_not_a_generic_question(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'شو السبب اللي خلاك تختار هالحزب؟',
            'stance' => DimaPrompt::ZIONIST,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
                ['role' => 'user', 'message' => 'بصوت ليكود'],
            ], 'أحمد', ['stage' => DimaPrompt::STAGE_VOTING_INTENTION_ASKED]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertFalse($decision['video']);
        $this->assertFalse($decision['close']);
        $this->assertStringNotContainsString('شو السبب', $decision['text']);
        $this->assertStringContainsString('كامينتس', $decision['text']);
        $this->assertStringContainsString('كراس الكادر', $decision['text']);
        $guide = \App\Services\AiChatbot\DimaKnowledge::retrieve('بصوت ليكود');
        $this->assertStringContainsString('2018', $guide);
        $this->assertStringContainsString('وثيقة حزبية', $guide);
        $this->assertStringNotContainsString('نحو 1000', $guide);
    }

    public function test_a_joint_list_supporter_keeps_a_concrete_reply(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'المشتركة وصلت 15 مقعد برا، والخلاف كان ترتيب مقاعد.',
            'stance' => DimaPrompt::JOINT,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
                ['role' => 'user', 'message' => 'للمشتركة'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame('المشتركة وصلت 15 مقعد برا، والخلاف كان ترتيب مقاعد.', $decision['text']);
        $this->assertSame(DimaPrompt::JOINT, $decision['stance']);
        $this->assertFalse($decision['close']);
        $this->assertStringNotContainsString('شو السبب', $decision['text']);
    }

    public function test_a_refusal_that_names_likud_is_not_a_flat_no(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'شو السبب اللي خلاك تقاطع؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
                ['role' => 'user', 'message' => 'مش رح أصوت'],
                ['role' => 'user', 'message' => 'الليكود والموحدة نفس الشي'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertFalse(DimaPrompt::explicitNoVote([
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'مش رح أصوت'],
            ['role' => 'user', 'message' => 'الليكود والموحدة نفس الشي'],
        ]));
        $this->assertFalse($decision['video']);
        $this->assertFalse($decision['close']);
        $this->assertStringNotContainsString('شو السبب', $decision['text']);
        $this->assertStringContainsString('الليكود', $decision['text']);
    }

    public function test_a_demolition_question_gets_the_housing_record(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'بتطلع تصوت؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
                ['role' => 'user', 'message' => 'ليش الهدم رجع؟'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('أربعة مقاعد', $decision['text']);
        $this->assertStringContainsString('كراس الكادر', $decision['text']);
        $this->assertStringNotContainsString('بتطلع تصوت', $decision['text']);
        $this->assertFalse($decision['close']);
    }

    public function test_a_question_about_what_arabs_did_is_answered_from_the_guide(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'المشكلة ثقة، والوعود ما انصدقت.',
            'stance' => DimaPrompt::NO_GAIN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'والعرب شو عملوا بعد كل اللي بدكوا؟'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('550', $decision['text']);
        $this->assertStringContainsString('كراس الكادر', $decision['text']);
        $this->assertStringNotContainsString('ثقة', $decision['text']);
        $this->assertStringNotContainsString('وعود', $decision['text']);
    }

    public function test_criticism_of_mansour_abbas_stays_on_the_written_record(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'شو السبب اللي خلاك تحكي هيك؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'منصور عباس باعنا'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('منصور عباس', $decision['text']);
        $this->assertStringContainsString('كراس الكادر', $decision['text']);
        $this->assertStringNotContainsString('شو السبب', $decision['text']);
        $this->assertStringNotContainsString('قال حرفيا', $decision['text']);
    }

    public function test_a_budget_question_keeps_the_guide_figures(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'في ميزانيات كتير انحكت.',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $kept = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'احكيلي تفاصيل الميزانيات'],
            ], 'أحمد');
            DimaPrompt::$testResponder = fn (): string => json_encode([
                'reply' => 'قرار 550 نحو 30 مليار على خمس سنين، والسنة الاولى نحو 6 مليار، و60 بالمية من مبلغ هاي السنة مش من كل المبلغ.',
                'stance' => DimaPrompt::OPEN,
                'note' => '',
                'close' => false,
            ], JSON_UNESCAPED_UNICODE);
            $good = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'احكيلي تفاصيل الميزانيات'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('30 مليار', $kept['text']);
        $this->assertStringContainsString('549', $kept['text']);
        $this->assertStringContainsString('60', $kept['text']);
        $this->assertSame('قرار 550 نحو 30 مليار على خمس سنين، والسنة الاولى نحو 6 مليار، و60 بالمية من مبلغ هاي السنة مش من كل المبلغ.', $good['text']);
    }

    public function test_a_stop_request_after_politics_does_not_continue_the_argument(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'الليكود حزب نتنياهو، وحكومته مرت قانون القومية، ولازم تحكي كمان عن الهدم.',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'الليكود حزب نتنياهو.'],
                ['role' => 'user', 'message' => 'خلص لا تبعتلي'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['close']);
        $this->assertSame(DimaPrompt::REFUSED, $decision['stance']);
        $this->assertStringNotContainsString('الليكود', $decision['text']);
        $this->assertLessThan(40, mb_strlen($decision['text']));
    }

    public function test_a_hello_that_asks_who_keeps_the_usual_introduction(): void
    {
        $calls = 0;
        DimaPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return json_encode([
                'reply' => 'معك ديما من مركز اتصالات القائمة العربية الموحدة.',
                'stance' => DimaPrompt::OPEN,
                'note' => '',
                'close' => false,
            ], JSON_UNESCAPED_UNICODE);
        };

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'مرحبا Test customer، شو الاخبار'],
                ['role' => 'user', 'message' => 'هلا مين بحكي معي'],
            ], 'Test customer');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertSame(1, $calls);
        $this->assertStringContainsString('معك ديما من مركز اتصالات للوسط العربي', $decision['text']);
        $this->assertStringContainsString('متأسفة اذا رسالتي اجت فجأة', $decision['text']);
        $this->assertStringContainsString('بتسمحلي أسألك سؤال صغير؟', $decision['text']);
        $this->assertStringNotContainsString('القائمة العربية الموحدة', $decision['text']);
        $this->assertFalse($decision['close']);
    }

    public function test_identity_questions_name_the_organization_without_a_script(): void
    {
        $bad = fn (): string => json_encode([
            'reply' => 'معك حق تسأل. خليني أوضحلك، وبتسمحلي أسألك سؤال صغير؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        DimaPrompt::$testResponder = $bad;
        try {
            $who = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ أحمد شو الاخبار'],
                ['role' => 'user', 'message' => 'مين إنت؟'],
            ], 'أحمد');
            $org = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'من أي جهة؟'],
            ], 'أحمد');
            $where = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'وين موجودين؟'],
            ], 'أحمد');
            DimaPrompt::$testResponder = fn (): string => json_encode([
                'reply' => 'مكتبنا بشارع الرئيسية بالناصرة.',
                'stance' => DimaPrompt::OPEN,
                'note' => '',
                'close' => false,
            ], JSON_UNESCAPED_UNICODE);
            $invented = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'وين موجودين؟'],
            ], 'أحمد');
            $many = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'مين إنت ومن أي جهة وليش بتحكوا معي؟'],
            ], 'أحمد');
            $phone = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'من وين جبتوا رقمي؟'],
            ], 'أحمد');
            DimaPrompt::$testResponder = fn (): string => json_encode([
                'reply' => 'لا، أنا إنسانة وعم بحكيك شخصيا.',
                'stance' => DimaPrompt::OPEN,
                'note' => '',
                'close' => false,
            ], JSON_UNESCAPED_UNICODE);
            $bot = DimaPrompt::nextReply([
                ['role' => 'user', 'message' => 'إنتي بوت؟'],
            ], 'ليلى');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('مركز اتصالات القائمة العربية الموحدة', $who['text']);
        $this->assertStringNotContainsString('بتسمحلي', $who['text']);
        $this->assertStringNotContainsString('معك حق', $who['text']);
        $this->assertTrue($who['identity']['introduced']);
        $this->assertTrue($who['identity']['organization']);

        $this->assertStringContainsString('القائمة العربية الموحدة', $org['text']);
        $this->assertStringNotContainsString('ناوي تصوت', $org['text']);

        $this->assertStringContainsString('ما في عنوان', $where['text']);
        $this->assertStringNotContainsString('شارع', $invented['text']);
        $this->assertStringNotContainsString('الناصرة', $invented['text']);
        $this->assertTrue($invented['identity']['asked_location']);

        $this->assertStringContainsString('القائمة العربية الموحدة', $many['text']);
        $this->assertStringContainsString('يشاركوا', $many['text']);
        $this->assertLessThan(220, mb_strlen($many['text']));
        $this->assertTrue($many['identity']['purpose']);

        $this->assertStringContainsString('ما عندي', $phone['text']);
        $this->assertStringNotContainsString('فيسبوك', $phone['text']);
        $this->assertTrue($phone['identity']['asked_phone']);

        $this->assertMatchesRegularExpression('/آلي|بوت|برنامج/u', $bot['text']);
        $this->assertTrue($bot['identity']['asked_automation']);
        $this->assertStringContainsString('معكي', $bot['text']);
    }

    public function test_a_repeated_identity_question_stays_short(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'معك ديما من مركز اتصالات القائمة العربية الموحدة، وبنتواصل لنسمع الآراء ونشجع أهلنا يشاركوا بالانتخابات مهما كان اختيارهم. بتسمحلي أسألك عن التصويت؟',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $again = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'معك ديما من مركز اتصالات القائمة العربية الموحدة.'],
                ['role' => 'user', 'message' => 'مين إنت؟'],
            ], 'أحمد', [
                'identity' => [
                    'introduced' => true,
                    'organization' => true,
                    'purpose' => true,
                ],
            ]);
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertStringContainsString('متل ما حكيت', $again['text']);
        $this->assertStringNotContainsString('بتسمحلي', $again['text']);
        $this->assertLessThan(80, mb_strlen($again['text']));
    }

    public function test_a_stop_after_an_identity_question_ends_the_chat(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'معك ديما من مركز اتصالات القائمة العربية الموحدة، وبنحب نكمل الحكي عن التصويت.',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $decision = DimaPrompt::nextReply([
                ['role' => 'assistant', 'message' => 'معك ديما من مركز اتصالات القائمة العربية الموحدة.'],
                ['role' => 'user', 'message' => 'خلاص لا تبعتلي'],
            ], 'أحمد');
        } finally {
            DimaPrompt::$testResponder = null;
        }

        $this->assertTrue($decision['close']);
        $this->assertTrue($decision['identity']['stopped']);
        $this->assertStringNotContainsString('القائمة', $decision['text']);
    }
}
