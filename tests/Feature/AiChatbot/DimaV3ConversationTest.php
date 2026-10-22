<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\User;
use App\Services\AiChatbot\ChatbotTestService;
use App\Services\AiChatbot\DimaConversationService;
use App\Services\AiChatbot\DimaEngine;
use App\Services\AiChatbot\DimaPrompt;
use App\Services\AiChatbot\DimaV3\ConversationEngine;
use App\Services\AiChatbot\DimaV3\MediaLibrary;
use App\Services\AiChatbot\DimaV3\TurnLogger;
use App\Services\AiChatbot\MansourBotService;
use App\Services\AiChatbot\MansourConversationRevival;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DimaV3ConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['dima.engine' => 'v3']);
    }

    protected function tearDown(): void
    {
        ConversationEngine::$testResponder = null;
        DimaPrompt::$testResponder = null;
        DimaConversationService::$testResponder = null;
        parent::tearDown();
    }

    public function test_v3_dispatch_does_not_use_v1_or_v2(): void
    {
        $v3Calls = 0;
        ConversationEngine::$testResponder = function () use (&$v3Calls): string {
            $v3Calls++;

            return $this->payload(['stage' => 'introduction', 'reply' => 'أنا ديما من مركز اتصالات القائمة العربية الموحدة.']);
        };
        DimaPrompt::$testResponder = fn (): string => $this->payload();
        DimaConversationService::$testResponder = fn (): string => $this->v2Payload();

        $decision = DimaEngine::nextReply([
            ['role' => 'user', 'message' => 'مرحبا'],
        ], 'سامي', []);

        $this->assertSame(1, $v3Calls);
        $this->assertSame('introduction', $decision['stage']);
        $this->assertFalse($decision['video']);
        Http::assertNothingSent();
    }

    public function test_unset_engine_stays_on_v1_and_v2_stays_on_v2(): void
    {
        config(['dima.engine' => 'v1']);
        $v1Calls = 0;
        DimaPrompt::$testResponder = function () use (&$v1Calls): string {
            $v1Calls++;

            return $this->v2Payload('رد أول');
        };
        DimaEngine::nextReply([['role' => 'user', 'message' => 'مرحبا']], 'سامي', []);
        $this->assertGreaterThan(0, $v1Calls);

        config(['dima.engine' => 'v2']);
        $v2Calls = 0;
        DimaConversationService::$testResponder = function () use (&$v2Calls): string {
            $v2Calls++;

            return $this->v2Payload('رد ثاني');
        };
        DimaEngine::nextReply([['role' => 'user', 'message' => 'مرحبا']], 'سامي', []);
        $this->assertSame(1, $v2Calls);
    }

    public function test_v3_nudge_makes_no_model_call(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload();
        };

        $this->assertSame('', DimaEngine::nudge([['role' => 'user', 'message' => 'مرحبا']], 'سامي', true));
        $this->assertSame(0, $calls);
    }

    public function test_state_round_trip_and_v1_does_not_write_v3(): void
    {
        $chat = $this->conversation();
        $state = [
            'current_stage' => 'open',
            'permission_status' => 'granted',
            'voting_intention' => 'yes',
            'party_mentioned' => 'القائمة العربية الموحدة',
            'last_user_question' => 'مين رئيس القائمة؟',
            'previously_answered_topics' => ['القيادة'],
            'sent_media_ids' => ['fixture-clip'],
            'opted_out' => false,
        ];
        DimaEngine::remember($chat, 'open', '', false, $state, 'open', []);
        $read = DimaEngine::read($chat->fresh());
        foreach ($state as $key => $value) {
            $this->assertSame($value, $read[$key]);
        }

        config(['dima.engine' => 'v1']);
        $other = $this->conversation('972502222222');
        DimaEngine::remember($other, DimaPrompt::OPEN, '', false, [
            'known' => 'سامي',
            'topic' => '',
            'answered' => '',
            'open_question' => '',
            'concern' => '',
            'wants_to_continue' => true,
        ], DimaPrompt::STAGE_GREETING, []);
        $fresh = $other->fresh();
        $this->assertArrayNotHasKey('dima_v3', $fresh->metadata ?? []);
        $this->assertArrayHasKey('dima', $fresh->metadata ?? []);
        $this->assertFalse(DimaEngine::isClosed($fresh));
    }

    public function test_fixture_catalog_rejects_unknown_sent_and_blank_description(): void
    {
        $library = new MediaLibrary;
        $clip = $library->all()[0];
        foreach (['id', 'title', 'source', 'published_on', 'transcript', 'factual_context', 'category', 'url', 'description'] as $field) {
            $this->assertNotSame('', $clip[$field]);
        }
        $this->assertNull($library->attach('missing', 'وصف', []));
        $this->assertNull($library->attach('fixture-clip', 'وصف', ['fixture-clip']));
        $this->assertNull($library->attach('fixture-clip', '   ', []));
        $this->assertSame('fixture-clip', $library->attach('fixture-clip', 'وصف المقطع', [])['id']);
    }

    public function test_yes_and_no_votes_are_stored_and_no_does_not_close(): void
    {
        $script = ['yes', 'no'];
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls, &$script): string {
            $vote = $script[$calls];
            $calls++;

            return $this->payload([
                'stage' => 'voting_intention',
                'reply' => $vote === 'yes' ? 'سجلت إنك ناوي تصوت.' : 'سجلت إنك مش ناوي تصوت.',
                'voting_intention' => $vote,
            ]);
        };
        $prior = ['current_stage' => 'voting_intention', 'permission_status' => 'granted'];
        $yes = $this->engine()->turn([
            ['role' => 'user', 'message' => 'اه بس عندي سؤال قبل'],
        ], 'سامي', $prior);
        $no = $this->engine()->turn([
            ['role' => 'user', 'message' => 'لا'],
        ], 'سامي', $yes['memory']);

        $this->assertSame(2, $calls);
        $this->assertSame('yes', $yes['memory']['voting_intention']);
        $this->assertSame('no', $no['memory']['voting_intention']);
        $this->assertFalse($no['close']);
        $this->assertSame('سجلت إنك مش ناوي تصوت.', $no['text']);
    }

    public function test_parties_without_a_fixed_reply_go_to_the_model(): void
    {
        $names = ['ש"ס', 'ישראל ביתנו'];
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls, &$names): string {
            $name = $names[$calls];
            $calls++;

            return $this->payload([
                'stage' => 'party_preference',
                'reply' => 'ذكرت '.$name.'.',
                'party_mentioned' => $name,
                'media_id' => 'fixture-clip',
                'media_description' => 'وصف',
            ]);
        };
        $prior = ['current_stage' => 'voting_intention', 'permission_status' => 'granted'];
        $first = $this->engine()->turn([['role' => 'user', 'message' => 'ש"ס']], 'سامي', $prior);
        $second = $this->engine()->turn([['role' => 'user', 'message' => 'ישראל ביתנו']], 'سامي', $first['memory']);

        $this->assertSame(2, $calls);
        $this->assertSame('ש"ס', $first['memory']['party_mentioned']);
        $this->assertSame('ישראל ביתנו', $second['memory']['party_mentioned']);
        $this->assertSame('fixture-clip', $first['clips'][0]['id']);
        $this->assertSame([], $second['clips']);
    }

    public function test_yes_vote_path_asks_who_then_answers_the_party(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload();
        };
        $prior = ['current_stage' => 'voting_intention', 'permission_status' => 'granted'];

        $who = $this->engine()->turn([['role' => 'user', 'message' => 'اه رح اصوت']], 'سامي', $prior);
        $this->assertSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::WHO_MALE, $who['text']);
        $this->assertSame('party_preference', $who['stage']);
        $this->assertSame('yes', $who['memory']['voting_intention']);

        $democrats = $this->engine()->turn([['role' => 'user', 'message' => 'للديمقراطيين']], 'سامي', $who['memory']);
        $this->assertStringContainsString('غولان', $democrats['text']);
        $this->assertSame('impact-5', $democrats['clips'][0]['id']);
        $this->assertSame('الديمقراطيين', $democrats['memory']['party_mentioned']);

        $united = $this->engine()->turn([['role' => 'user', 'message' => 'للموحدة اكيد']], 'سامي', $who['memory']);
        $this->assertSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::THANKS_MALE, $united['text']);
        $this->assertSame('الموحدة', $united['memory']['party_mentioned']);

        $this->assertFalse((new \App\Services\AiChatbot\DimaV3\ObjectionReplies)->namesUnited('ليش اصوت للموحدة؟'));
        $this->assertSame(0, $calls);
    }

    public function test_leadership_and_budget_answers_pass_through(): void
    {
        $seen = '';
        $answers = [
            'ما عندي مصدر مؤكد لاسم رئيس القائمة.',
            'ما عندي مصدر مؤكد لرقم الميزانية.',
        ];
        $calls = 0;
        ConversationEngine::$testResponder = function (array $messages) use (&$calls, &$seen, &$answers): string {
            $seen = (string) $messages[0]['content'];
            $text = $answers[$calls];
            $calls++;

            return $this->payload([
                'stage' => 'open',
                'reply' => $text,
                'last_user_question' => $calls === 1 ? 'القيادة' : 'الميزانية',
            ]);
        };
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted'];
        $leadership = $this->engine()->turn([['role' => 'user', 'message' => 'مين رئيس القائمة؟']], 'سامي', $prior);
        $budget = $this->engine()->turn([['role' => 'user', 'message' => 'قديش ميزانية الحكومة؟']], 'سامي', $leadership['memory']);

        $this->assertStringContainsString('ما عندك مصدر مؤكد', $seen);
        $this->assertSame($answers[0], $leadership['text']);
        $this->assertSame($answers[1], $budget['text']);
        $this->assertSame([], $leadership['clips']);
    }

    public function test_evidence_clip_is_attached_once(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload([
                'stage' => 'open',
                'reply' => 'هذا وصف المقطع التجريبي.',
                'media_id' => 'fixture-clip',
                'media_description' => 'مقطع اختبار يشرح آلية الإرفاق فقط.',
            ]);
        };
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted'];
        $first = $this->engine()->turn([['role' => 'user', 'message' => 'وريني الدليل']], 'سامي', $prior);
        $second = $this->engine()->turn([['role' => 'user', 'message' => 'وريني الدليل']], 'سامي', $first['memory']);

        $this->assertSame(2, $calls);
        $this->assertSame('fixture-clip', $first['clips'][0]['id']);
        $this->assertFalse($first['video']);
        $this->assertSame(['fixture-clip'], $first['memory']['sent_media_ids']);
        $this->assertSame([], $second['clips']);
        $this->assertSame('هذا وصف المقطع التجريبي.', $second['text']);
    }

    public function test_distrust_then_topic_change_keeps_each_reply(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                return $this->payload([
                    'stage' => 'open',
                    'reply' => 'فاهم إنك مش واثق بالسياسيين.',
                    'last_user_question' => 'الثقة',
                    'answered_topic' => 'الثقة بالسياسيين',
                ]);
            }

            return $this->payload([
                'stage' => 'open',
                'reply' => 'السؤال الجديد عن الخدمات.',
                'last_user_question' => 'الخدمات',
                'answered_topic' => 'الخدمات',
            ]);
        };
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted'];
        $first = $this->engine()->turn([['role' => 'user', 'message' => 'ما بوثق بسياسي']], 'سامي', $prior);
        $second = $this->engine()->turn([['role' => 'user', 'message' => 'احكي عن الخدمات']], 'سامي', $first['memory']);

        $this->assertSame('فاهم إنك مش واثق بالسياسيين.', $first['text']);
        $this->assertSame('السؤال الجديد عن الخدمات.', $second['text']);
        $this->assertSame('الخدمات', $second['memory']['last_user_question']);
        $this->assertSame(['الثقة بالسياسيين', 'الخدمات'], $second['memory']['previously_answered_topics']);
    }

    public function test_repeated_message_calls_the_model_once_per_turn_with_history(): void
    {
        $calls = 0;
        $secondHistory = '';
        ConversationEngine::$testResponder = function (array $messages) use (&$calls, &$secondHistory): string {
            $calls++;
            if ($calls === 2) {
                $secondHistory = json_encode($messages, JSON_UNESCAPED_UNICODE);
            }

            return $this->payload([
                'stage' => 'open',
                'reply' => 'رد رقم '.$calls,
            ]);
        };
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted'];
        $first = $this->engine()->turn([['role' => 'user', 'message' => 'نفس الجملة']], 'سامي', $prior);
        $this->engine()->turn([
            ['role' => 'user', 'message' => 'نفس الجملة'],
            ['role' => 'assistant', 'message' => $first['text']],
            ['role' => 'user', 'message' => 'نفس الجملة'],
        ], 'سامي', $first['memory']);

        $this->assertSame(2, $calls);
        $this->assertStringContainsString('رد رقم 1', $secondHistory);
    }

    public function test_permission_refusal_and_opt_out_skip_the_model(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload();
        };
        $refused = $this->engine()->turn([
            ['role' => 'user', 'message' => 'لا'],
        ], 'سامي', ['current_stage' => 'permission']);
        $stopped = $this->engine()->turn([
            ['role' => 'user', 'message' => 'وقف'],
        ], 'سامي', ['current_stage' => 'open', 'permission_status' => 'granted']);

        $this->assertSame(0, $calls);
        $this->assertTrue($refused['close']);
        $this->assertTrue($stopped['close']);
        $this->assertTrue($refused['memory']['opted_out']);
        $this->assertSame('closed', $stopped['stage']);
    }

    public function test_model_cannot_skip_to_voting_or_close_from_greeting(): void
    {
        $prompt = '';
        ConversationEngine::$testResponder = function (array $messages) use (&$prompt): string {
            $prompt = (string) $messages[0]['content'];

            return $this->payload([
                'stage' => 'voting_intention',
                'reply' => 'رح تصوت؟',
                'opted_out' => true,
                'voting_intention' => 'yes',
            ]);
        };

        $decision = $this->engine()->turn([['role' => 'user', 'message' => 'مرحبا']], 'سامي', []);

        $this->assertSame('greeting', $decision['stage']);
        $this->assertFalse($decision['close']);
        $this->assertFalse($decision['memory']['opted_out']);
        $this->assertSame('', $decision['memory']['voting_intention']);
        $this->assertStringContainsString('القائمة العربية الموحدة', $prompt);
        $this->assertStringContainsString('بلا سؤال تصويت', $prompt);
        $this->assertStringContainsString('لا تعرّفي حالك من حالك كمساعدة رقمية', $prompt);
        $this->assertSame('رح تصوت؟', $decision['text']);
    }

    public function test_invalid_json_and_missing_responder_do_not_call_openai(): void
    {
        ConversationEngine::$testResponder = fn (): string => 'not-json';
        $invalid = $this->engine()->turn([['role' => 'user', 'message' => 'مرحبا']], 'سامي', []);
        $this->assertFalse($invalid['close']);
        $this->assertSame('ما وصلني رد واضح هلق.', $invalid['text']);

        ConversationEngine::$testResponder = null;
        $missing = $this->engine()->turn([['role' => 'user', 'message' => 'مرحبا']], 'سامي', []);
        $this->assertSame('ما وصلني رد واضح هلق.', $missing['text']);
        Http::assertNothingSent();
    }

    public function test_log_redacts_phones_and_the_api_key(): void
    {
        config(['services.openai.dima_api_key' => 'sk-test-dima-secret']);
        $logged = null;
        Log::listen(function ($message) use (&$logged): void {
            if ($message->message === 'dima-v3.turn') {
                $logged = $message->context;
            }
        });
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'stage' => 'introduction',
            'reply' => 'رقمي 050-123-4567 والرمز 7654321 والمفتاح sk-test-dima-secret',
        ]);

        $this->engine()->turn([['role' => 'user', 'message' => 'مرحبا']], 'سامي', [], false, 9);

        $encoded = json_encode($logged, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('050-123-4567', $encoded);
        $this->assertStringNotContainsString('0501234567', $encoded);
        $this->assertStringNotContainsString('7654321', $encoded);
        $this->assertStringNotContainsString('sk-test-dima-secret', $encoded);
        $this->assertStringNotContainsString('0501234567', TurnLogger::redact('اتصل 050-123-4567'));
    }

    public function test_chat_path_stores_v3_greeting_and_pauses_on_opt_out(): void
    {
        [$user, $dima] = $this->dimaBot();
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'stage' => 'introduction',
            'reply' => 'أنا ديما من مركز اتصالات القائمة العربية الموحدة، وبدي أسمع رأيك.',
        ]);

        $result = app(ChatbotTestService::class)->run($user, $dima, [
            'message' => 'مرحبا',
            'reset' => true,
            'customer_name' => 'سامي',
            'channel' => ChatbotConversation::CHANNEL_TEST,
        ]);

        $this->assertTrue($result['ok']);
        $chat = ChatbotConversation::query()->findOrFail($result['conversation_id']);
        $this->assertSame('permission', $chat->metadata['dima_v3']['current_stage']);
        $opening = (string) $chat->messages()->where('role', 'assistant')->orderBy('id')->value('message');
        $this->assertSame('مرحبا سامي، شو الاخبار', $opening);

        $this->assertSame(ConversationEngine::INTRO_LINE, (string) $chat->messages()->where('role', 'assistant')->orderByDesc('id')->value('message'));
        ConversationEngine::$testResponder = fn (): string => $this->payload();
        app(ChatbotTestService::class)->run($user, $dima, [
            'message' => 'وقف',
            'conversation_id' => $chat->id,
            'channel' => ChatbotConversation::CHANNEL_TEST,
        ]);
        $this->assertSame(ChatbotConversation::BOT_MODE_PAUSED, $chat->fresh()->bot_mode);
        $this->assertTrue(DimaEngine::isClosed($chat->fresh()));
    }

    public function test_v1_opening_still_uses_the_v1_line(): void
    {
        config(['dima.engine' => 'v1']);
        $opening = DimaEngine::openingFor('سامي');
        $this->assertStringContainsString('شو الاخبار', $opening);
        $this->assertSame('مرحبا سامي، شو الاخبار', ConversationEngine::openingFor('سامي'));
    }

    public function test_revival_treats_an_opted_out_v3_chat_as_closed(): void
    {
        [$user, $dima] = $this->dimaBot();
        $chat = ChatbotConversation::query()->create([
            'instance_id' => $dima->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972503333333@c.us',
            'contact_name' => 'سامي',
            'contact_phone' => '972503333333',
            'title' => 'سامي',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['dima_v3' => ['current_stage' => 'closed', 'opted_out' => true]],
        ]);
        $chat->messages()->create([
            'role' => 'user',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'message' => 'وقف',
        ]);
        Cache::flush();
        app(MansourConversationRevival::class)->arm($chat->id, 1);
        $this->assertFalse(Cache::has('mansour-revival-'.$chat->id));
        $this->assertTrue(DimaEngine::isClosed($chat->fresh()));
    }

    private function engine(): ConversationEngine
    {
        return app(ConversationEngine::class);
    }

    private function conversation(string $phone = '972501111111'): ChatbotConversation
    {
        [$user, $dima] = $this->dimaBot();

        return ChatbotConversation::query()->create([
            'instance_id' => $dima->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_TEST,
            'external_chat_id' => $phone.'@c.us',
            'contact_name' => 'سامي',
            'contact_phone' => $phone,
            'title' => 'سامي',
        ]);
    }

    /**
     * @return array{0:User,1:\App\Models\AiChatbot\ChatbotInstance}
     */
    private function dimaBot(): array
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $dima = app(MansourBotService::class)->ensureDimaBot($user);
        $this->assertNotNull($dima);

        return [$user, $dima];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function test_who_are_you_gets_the_name_and_no_clip(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload(['stage' => 'voting_intention', 'reply' => 'أنا ديما من مركز اتصالات القائمة العربية الموحدة.']);
        };

        $decision = $this->engine()->turn([
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'مين انت بزبط'],
        ], 'سامي', ['current_stage' => 'voting_intention', 'permission_status' => 'granted']);

        $this->assertSame(0, $calls);
        $this->assertSame([], $decision['clips']);
        $this->assertStringContainsString('ديما', $decision['text']);
        $this->assertStringContainsString('نشجع', $decision['text']);
        $this->assertStringEndsWith('ناوي تصوت بالانتخابات الجاي؟', $decision['text']);
    }

    public function test_stop_word_inside_a_question_does_not_close(): void
    {
        ConversationEngine::$testResponder = fn (): string => $this->payload(['stage' => 'open', 'reply' => 'جواب']);
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted'];

        foreach (['شو موقفكم من غزة', 'كيف بدكم توقفوا الهدم', 'مين رح يوقف القتل بالبلد'] as $question) {
            $decision = $this->engine()->turn([['role' => 'user', 'message' => $question]], 'سامي', $prior);
            $this->assertFalse($decision['close'], $question);
        }
        $this->assertTrue($this->engine()->turn([['role' => 'user', 'message' => 'وقف خلص']], 'سامي', $prior)['close']);
        $this->assertTrue($this->engine()->turn([['role' => 'user', 'message' => 'لو سمحت لا تبعتلي كمان']], 'سامي', $prior)['close']);
    }

    public function test_granted_permission_reaches_voting_and_a_no_vote_stays_open(): void
    {
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'stage' => 'voting_intention',
            'permission_status' => 'granted',
            'reply' => 'ناوي تصوت بالانتخابات الجاي؟',
        ]);
        $asked = $this->engine()->turn([['role' => 'user', 'message' => 'تفضلي']], 'سامي', ['current_stage' => 'introduction']);
        $this->assertSame('voting_intention', $asked['stage']);
        $this->assertSame('granted', $asked['memory']['permission_status']);

        ConversationEngine::$testResponder = fn (): string => $this->payload(['stage' => 'open', 'voting_intention' => 'no', 'reply' => 'ليش؟']);
        $no = $this->engine()->turn([['role' => 'user', 'message' => 'لا']], 'سامي', $asked['memory']);
        $this->assertFalse($no['close']);
        $this->assertSame('no', $no['memory']['voting_intention']);
    }

    public function test_plain_consent_asks_the_vote_question_without_the_model(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload();
        };

        $decision = $this->engine()->turn([['role' => 'user', 'message' => 'اه تفضلي']], 'سامي', ['current_stage' => 'permission']);

        $this->assertSame(0, $calls);
        $this->assertSame('ناوي تصوت بالانتخابات الجاي؟', $decision['text']);
        $this->assertSame('voting_intention', $decision['stage']);
        $this->assertSame('granted', $decision['memory']['permission_status']);
    }

    public function test_clear_no_vote_sends_the_ben_gvir_clip_once_without_the_model(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload(['stage' => 'open', 'reply' => 'جواب']);
        };
        $prior = ['current_stage' => 'voting_intention', 'permission_status' => 'granted'];
        $turns = [
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'لا مش رح اصوت'],
        ];

        $decision = $this->engine()->turn($turns, 'سامي', $prior);

        $this->assertSame(0, $calls);
        $this->assertSame(ConversationEngine::noVoteLine(false), $decision['text']);
        $this->assertSame('no-vote', $decision['clips'][0]['id']);
        $this->assertSame('videos/dima-no-vote-bengvir.mp4', $decision['clips'][0]['path']);
        $this->assertSame('no', $decision['memory']['voting_intention']);
        $this->assertFalse($decision['close']);

        $again = $this->engine()->turn(array_merge($turns, [
            ['role' => 'assistant', 'message' => $decision['text']],
            ['role' => 'user', 'message' => 'لا'],
        ]), 'سامي', $decision['memory']);
        $this->assertSame(1, $calls);
        $this->assertSame([], $again['clips']);
    }

    public function test_futility_objection_gets_the_campaign_answer_and_clip_once(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload(['stage' => 'open', 'reply' => 'جواب']);
        };
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted', 'voting_intention' => 'no', 'sent_media_ids' => ['no-vote']];

        $decision = $this->engine()->turn([['role' => 'user', 'message' => 'يختي ولله كله علفاضي, هي هي رح تيجي حكومه بدالها العن والعن']], 'سامي', $prior);

        $this->assertSame(1, $calls, 'long messages are read by the model first');
        $this->assertSame('impact-8', $decision['clips'][0]['id']);
        $this->assertStringContainsString('سنة 2021', $decision['text']);
        $this->assertStringNotContainsString('يضمن', $decision['text']);
        $this->assertStringEndsWith('؟', $decision['text']);

        $again = $this->engine()->turn([['role' => 'user', 'message' => 'برضو كله علفاضي']], 'سامي', $decision['memory']);
        $this->assertSame(2, $calls);
        $this->assertSame([], $again['clips']);
    }

    public function test_objection_cues_need_whole_words_and_permission(): void
    {
        $objections = new \App\Services\AiChatbot\DimaV3\ObjectionReplies;

        $this->assertNull($objections->match('الله يلعن هالوضع', false, []));
        $this->assertSame('record', $objections->match('طيب شو استفدنا منكم؟', false, [])['id']);
        $again = $objections->match('شو استفدنا منكم', false, ['impact-5']);
        $this->assertSame('', $again['clip']);
        $this->assertStringNotContainsString('الفيديو', $again['text']);

        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload();
        };
        $early = $this->engine()->turn([['role' => 'user', 'message' => 'كله علفاضي']], 'سامي', ['current_stage' => 'permission']);
        $this->assertSame([], $early['clips']);
        $this->assertSame(1, $calls);
    }

    public function test_agreement_asks_for_commitment_and_yes_gets_thanks(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload(['stage' => 'open', 'reply' => 'جواب']);
        };
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted', 'voting_intention' => 'no', 'sent_media_ids' => ['no-vote', 'impact-8']];

        $pitch = (new \App\Services\AiChatbot\DimaV3\ObjectionReplies)->match('كله علفاضي', false, [], 'open')['text'];
        $ask = $this->engine()->turn([
            ['role' => 'assistant', 'message' => $pitch],
            ['role' => 'user', 'message' => 'معك حق'],
        ], 'سامي', $prior);
        $this->assertSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::COMMIT_MALE, $ask['text']);

        $thanks = $this->engine()->turn([
            ['role' => 'assistant', 'message' => $ask['text']],
            ['role' => 'user', 'message' => 'ان شاء الله'],
        ], 'سامي', $ask['memory']);
        $this->assertSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::THANKS_MALE, $thanks['text']);
        $this->assertSame('yes', $thanks['memory']['voting_intention']);
        $this->assertSame(0, $calls);

        $this->engine()->turn([
            ['role' => 'assistant', 'message' => $ask['text']],
            ['role' => 'user', 'message' => 'لا مش متأكد'],
        ], 'سامي', $ask['memory']);
        $this->assertSame(1, $calls);
    }

    public function test_joint_list_preference_gets_evidence_and_clip(): void
    {
        ConversationEngine::$testResponder = fn (): string => $this->payload(['stage' => 'open', 'reply' => 'جواب']);
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted', 'sent_media_ids' => ['no-vote', 'impact-8']];

        $decision = $this->engine()->turn([['role' => 'user', 'message' => 'ليش ما اصوت للمشتركه بشوفهم احسن رح اصوت الهم']], 'سامي', $prior);

        $this->assertSame('impact-2', $decision['clips'][0]['id']);
        $this->assertStringContainsString('قانون القومية', $decision['text']);
        $this->assertStringNotContainsString('أضغط', $decision['text']);
        $this->assertStringEndsWith('؟', $decision['text']);
    }

    public function test_backing_off_sentences_are_dropped_from_model_replies(): void
    {
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'stage' => 'open',
            'reply' => 'مش رح أضغط عليك تغيّر اختيارك. بسنة وحدة جابت الموحدة خطة 30 مليار. شو أكثر إشي بهمك ببلدك؟',
        ]);
        $decision = $this->engine()->turn([['role' => 'user', 'message' => 'احكيلي اكثر']], 'سامي', ['current_stage' => 'open', 'permission_status' => 'granted']);

        $this->assertSame('بسنة وحدة جابت الموحدة خطة 30 مليار. شو أكثر إشي بهمك ببلدك؟', $decision['text']);
    }

    public function test_comparison_naming_united_is_not_a_vote_for_it(): void
    {
        $objections = new \App\Services\AiChatbot\DimaV3\ObjectionReplies;

        $this->assertFalse($objections->namesUnited('بس غولان بدافع عن حقوق العرب اكثر من الموحدة'));
        $this->assertTrue($objections->namesUnited('للموحدة طبعا'));
        $this->assertTrue($objections->commits('اا'));
        $this->assertTrue($objections->commits('اكييد'));
        $this->assertFalse($objections->commits('مش ناوي'));
        $this->assertSame('crime', $objections->match('شو بدكم تعملوا مع القتل اللي بالبلد؟', false, [], 'open')['id']);
        $this->assertSame('undecided_vote', $objections->match('يمكن', false, [], 'voting_intention')['id']);
        $this->assertNull($objections->match('يمكن', false, [], 'open'));
        $this->assertSame('religious', $objections->match('التصويت للكنيست حرام', false, [], 'open')['id']);
        $this->assertNull($objections->match('التصويت للكنيست حرام', false, [], 'open', ['اعتراض: religious']));
    }

    public function test_misspelled_party_and_confirmation_are_not_read_as_agreement(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload(['stage' => 'open', 'reply' => 'جواب']);
        };
        $prior = ['current_stage' => 'party_preference', 'permission_status' => 'granted', 'voting_intention' => 'yes'];

        // The exact chat: "دمقراطيني" is a misspelling of the Democrats.
        $democrats = $this->engine()->turn([
            ['role' => 'assistant', 'message' => \App\Services\AiChatbot\DimaV3\ObjectionReplies::WHO_MALE],
            ['role' => 'user', 'message' => 'دمقراطيني'],
        ], 'Test customer', $prior);
        $this->assertSame('impact-5', $democrats['clips'][0]['id']);
        $this->assertStringContainsString('غولان', $democrats['text']);

        // "صحيح" after a clarifying question confirms the party, it does not agree with الموحدة.
        $confirmed = $this->engine()->turn([
            ['role' => 'assistant', 'message' => 'قصدك قائمة الديمقراطيين برئاسة يائير غولان؟'],
            ['role' => 'user', 'message' => 'صحيح'],
        ], 'Test customer', ['current_stage' => 'open', 'permission_status' => 'granted', 'voting_intention' => 'yes']);
        $this->assertNotSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::COMMIT_MALE, $confirmed['text']);
        $this->assertStringContainsString('غولان', $confirmed['text']);
        $this->assertSame('impact-5', $confirmed['clips'][0]['id']);

        // Agreement with a model reply is not a commitment trigger either.
        $plain = $this->engine()->turn([
            ['role' => 'assistant', 'message' => 'الطقس حلو اليوم'],
            ['role' => 'user', 'message' => 'صحيح'],
        ], 'Test customer', ['current_stage' => 'open', 'permission_status' => 'granted']);
        $this->assertNotSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::COMMIT_MALE, $plain['text']);
        $this->assertSame(1, $calls);
    }

    public function test_plain_who_gets_name_and_the_question_again_then_model_on_push(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload(['stage' => 'permission', 'reply' => 'جواب صريح']);
        };
        $intro = ConversationEngine::INTRO_LINE;

        $who = $this->engine()->turn([
            ['role' => 'assistant', 'message' => $intro],
            ['role' => 'user', 'message' => 'مين معي'],
        ], 'سامي', ['current_stage' => 'permission']);
        $this->assertSame(0, $calls);
        $this->assertStringContainsString('ديما', $who['text']);
        $this->assertStringContainsString('نشجع', $who['text']);
        $this->assertStringNotContainsString('استطلاع', $who['text']);
        $this->assertStringContainsString('بتسمحلي أسألك سؤال صغير؟', $who['text']);
        $this->assertStringNotContainsString('الموحدة', $who['text']);
        $this->assertStringNotContainsString('آلية', $who['text']);
        $this->assertSame('permission', $who['stage']);

        // Asked again, or pushed: the model answers honestly.
        $this->engine()->turn([['role' => 'user', 'message' => 'مين انتي']], 'سامي', $who['memory']);
        $this->engine()->turn([['role' => 'user', 'message' => 'مين وراكي؟']], 'سامي', ['current_stage' => 'permission']);
        $this->assertSame(2, $calls);
    }

    public function test_cadre_guide_topics_get_the_guide_answer(): void
    {
        $calls = 0;
        ConversationEngine::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->payload(['stage' => 'open', 'reply' => 'جواب']);
        };
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted', 'party_mentioned' => 'المشتركة', 'sent_media_ids' => ['impact-2']];

        // The exact chat from the test page.
        $reunification = $this->engine()->turn([['role' => 'user', 'message' => 'بس يختي انتو وقفتو ضد قرار لم الشمل.']], 'Test customer', $prior);
        $this->assertStringContainsString('امتنعوا وما صوتوا مع القانون', $reunification['text']);
        $this->assertStringContainsString('أييلت شاكيد', $reunification['text']);
        $this->assertStringNotContainsString('رابط', $reunification['text']);

        $cases = [
            'انتو فككتو المشتركة' => ['2015', ''],
            'شو موقفكم من غزة؟' => ['دولتين لشعبين', ''],
            'الخدمة المدنية يعني تجنيد' => ['2013', ''],
            'ليش صوتتو ضد مستشفى سخنين' => ['وزارة الصحة', 'impact-3'],
            'الميزانيات كانت فتات' => ['90%', 'impact-3'],
            'انا مقاطع لانها دولة صهيونية' => ['أصحاب الأرض', ''],
            'بصوت ليش عتيد عشان يأثر' => ['بدون الموحدة', 'impact-8'],
        ];
        foreach ($cases as $message => [$needle, $clip]) {
            $decision = $this->engine()->turn([['role' => 'user', 'message' => $message]], 'Test customer', $prior);
            $this->assertStringContainsString($needle, $decision['text'], $message);
            $this->assertSame($clip, $decision['clips'][0]['id'] ?? '', $message);
            if ($clip === '') {
                $this->assertStringNotContainsString('الفيديو', $decision['text'], $message);
            }
        }
        $this->assertSame(6, $calls, 'the six long messages are read by the model first');

        // Female address.
        $female = $this->engine()->turn([['role' => 'user', 'message' => 'لم الشمل']], 'ريم', $prior);
        $this->assertStringContainsString('قوليلي', $female['text']);
    }

    public function test_model_understanding_routes_to_campaign_replies(): void
    {
        $voting = ['current_stage' => 'voting_intention', 'permission_status' => 'granted'];

        // "لا بصراحه" is not in any word list; the model reads it as a no.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'vote_no', 'reply' => 'فهمت عليك، والقرار إلك.']);
        $no = $this->engine()->turn([
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'لا بصراحه'],
        ], 'سامي', $voting);
        $this->assertSame('no-vote', $no['clips'][0]['id']);
        $this->assertSame(ConversationEngine::noVoteLine(false), $no['text']);
        $this->assertSame('no', $no['memory']['voting_intention']);

        // An objection worded freely: the campaign answer, opened by the model's own sentence about it.
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'intent' => 'objection',
            'topic' => 'reunification',
            'ack' => 'بفهم إنه موضوع العائلات بوجعك شخصياً.',
            'reply' => 'ما عندي سجل.',
        ]);
        $open = ['current_stage' => 'open', 'permission_status' => 'granted'];
        $objection = $this->engine()->turn([['role' => 'user', 'message' => 'ابن عمي مرته من الضفة وما قدر يجيبها، وانتو وين كنتو؟']], 'سامي', $open);
        $this->assertStringStartsWith('بفهم إنه موضوع العائلات بوجعك شخصياً.', $objection['text']);
        $this->assertStringContainsString('أييلت شاكيد', $objection['text']);
        $this->assertStringNotContainsString('ما عندي سجل', $objection['text']);

        // A backing-off "ack" is dropped and the campaign opener stays.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'objection', 'topic' => 'gaza', 'ack' => 'بحترم رأيك والقرار إلك.']);
        $gaza = $this->engine()->turn([['role' => 'user', 'message' => 'وين كنتو لما صار اللي صار هناك']], 'سامي', $open);
        $this->assertStringStartsWith('غزة وجع كل واحد فينا', $gaza['text']);

        // A party named in passing.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'choose_party', 'party' => 'joint']);
        $joint = $this->engine()->turn([['role' => 'user', 'message' => 'انا مع جماعة عودة من زمان']], 'سامي', ['current_stage' => 'party_preference', 'permission_status' => 'granted']);
        $this->assertSame('impact-2', $joint['clips'][0]['id']);
        $this->assertSame('المشتركة', $joint['memory']['party_mentioned']);

        // Nothing fits: the model's own reply goes out.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'other', 'reply' => 'ههه حلوة هاي 😄']);
        $other = $this->engine()->turn([['role' => 'user', 'message' => 'شو اكلتي اليوم']], 'سامي', $open);
        $this->assertSame('ههه حلوة هاي 😄', $other['text']);

        // The same campaign answer is not sent twice.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'objection', 'topic' => 'reunification', 'reply' => 'رد ثاني']);
        $again = $this->engine()->turn([['role' => 'user', 'message' => 'برضو لم الشمل']], 'سامي', $objection['memory']);
        $this->assertStringNotContainsString('أييلت شاكيد', $again['text']);
        $this->assertStringNotContainsString('رد ثاني', $again['text'], 'a political turn is built from campaign arguments, not the model text');
        $this->assertStringEndsWith('؟', $again['text']);
    }

    public function test_any_political_turn_without_a_prepared_reply_is_built_from_arguments(): void
    {
        // The exact last turn of the test chat: the Democrats reply was used; the model's own text retracted a point.
        $prior = [
            'current_stage' => 'open',
            'permission_status' => 'granted',
            'voting_intention' => 'yes',
            'party_mentioned' => 'الديمقراطيين',
            'previously_answered_topics' => ['اعتراض: democrats'],
            'sent_media_ids' => ['impact-5'],
        ];
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'intent' => 'objection',
            'party' => 'democrats',
            'topic' => '',
            'ack' => 'بفهم إنك فقدت الثقة من الوعود اللي ما شفت إلها نتيجة.',
            'arguments' => ['trust_by_deeds', 'seven_seats'],
            'question' => 'شو لازم يصير عشان ترجع تثق؟',
            'reply' => 'وللتوضيح، مبالغ الخطط كانت موافقات، مش معناها إنها انصرفت. في وعد محدد بتحب نفحص؟',
        ]);
        $decision = $this->engine()->turn([['role' => 'user', 'message' => 'صح معك حق, بس انا ما بوثق بقياداتننا, دائمنا منافقين وما بجيبو نتيجه']], 'Test customer', $prior);

        $this->assertStringStartsWith('بفهم إنك فقدت الثقة', $decision['text']);
        $this->assertStringContainsString('الثقة بتنبني على الفعل', $decision['text']);
        $this->assertStringContainsString('الهدف 7 مقاعد', $decision['text']);
        $this->assertStringEndsWith('بدك صوتك يكون مقعد بيقرر، ولا مقعد بيتفرج؟', $decision['text'], 'the closing question is the campaign argument own');
        $this->assertStringNotContainsString('موافقات', $decision['text']);
        $this->assertSame([], $decision['clips'], 'impact-5 already went out');
        $this->assertContains('حجة: trust_by_deeds', $decision['memory']['previously_answered_topics']);

        // Next turn: used arguments are not repeated, and a neutral question is replaced by the argument's own.
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'intent' => 'objection',
            'arguments' => ['trust_by_deeds', 'boycott_gift'],
            'question' => 'بتحب أساعدك تقارن البرامج؟',
            'reply' => 'القرار إلك.',
        ]);
        $next = $this->engine()->turn([['role' => 'user', 'message' => 'برضو مش مقتنع، كلهم بدهم كراسي']], 'Test customer', $decision['memory']);
        $this->assertStringNotContainsString('الثقة بتنبني على الفعل', $next['text']);
        // A Democrats voter: the non-voter argument the model picked is refused for one that fits the party.
        $this->assertStringNotContainsString('هدية لبن غفير', $next['text']);
        $this->assertStringContainsString('النواب العرب بالأحزاب الصهيونية', $next['text']);
        $this->assertStringEndsWith('بيحسبك أولوية؟', $next['text']);
        $this->assertStringNotContainsString('القرار إلك', $next['text']);

        // Small talk with a clean model reply stays the model's own.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'other', 'reply' => 'الله يسعدك 🌷']);
        $chat = $this->engine()->turn([['role' => 'user', 'message' => 'يسلمو على الحكي الحلو']], 'Test customer', $next['memory']);
        $this->assertSame('الله يسعدك 🌷', $chat['text']);
    }

    public function test_ack_never_erases_facts_or_concedes_and_not_convinced_keeps_the_door_open(): void
    {
        $open = ['current_stage' => 'open', 'permission_status' => 'granted', 'sent_media_ids' => ['impact-5']];

        // The "record" reply carries its facts on the first line: the ack goes in front of it.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'objection', 'topic' => 'record', 'ack' => 'بفهم إنك فقدت الثقة.']);
        $record = $this->engine()->turn([['role' => 'user', 'message' => 'شو عملتولنا اصلا غير الحكي الفاضي']], 'سامي', $open);
        $this->assertStringStartsWith('بفهم إنك فقدت الثقة.', $record['text']);
        $this->assertStringContainsString('30 مليار', $record['text']);

        // A conceding half-sentence is cut; a fact-checking question is replaced.
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'intent' => 'objection',
            'ack' => 'فهمت عليك، إنت شايف المشتركة أصدق، ومقارنة الميزانيات لحالها ما بتجاوب على موضوع الثقة.',
            'arguments' => ['joint_toppled_change'],
            'question' => 'في تصريح محدد حابب نتحقق منه؟',
        ]);
        $joint = $this->engine()->turn([['role' => 'user', 'message' => 'المشتركة اصدق منكم بكثير']], 'سامي', $open);
        $this->assertStringStartsWith('فهمت عليك، إنت شايف المشتركة أصدق.', $joint['text']);
        $this->assertStringNotContainsString('ما بتجاوب', $joint['text']);
        $this->assertStringNotContainsString('نتحقق', $joint['text']);
        $this->assertStringEndsWith('مين خدم مجتمعنا أكثر بهديك الفترة؟', $joint['text']);

        // "Not convinced" does not close the chat the first time.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'not_convinced']);
        $done = $this->engine()->turn([['role' => 'user', 'message' => 'خلص مش مقتنع يا ستي']], 'سامي', $open);
        $this->assertFalse($done['close']);
        $this->assertStringContainsString('بتسمحلي أتواصل معك مرة ثانية', $done['text']);
        $second = $this->engine()->turn([['role' => 'user', 'message' => 'لا خلص بكفي']], 'سامي', $done['memory']);
        $this->assertTrue($second['close']);
    }

    public function test_live_sending_follows_the_dima_outbound_setting(): void
    {
        config(['dima.outbound' => false]);
        $this->assertFalse(DimaEngine::allowsOutbound());
        config(['dima.outbound' => true]);
        $this->assertTrue(DimaEngine::allowsOutbound());
    }

    public function test_agreement_after_a_pitch_with_the_model_sentence_in_front_asks_for_the_commitment(): void
    {
        // The real chat: the religious reply went out with the model's sentence in front of it.
        $religious = (new \App\Services\AiChatbot\DimaV3\ObjectionReplies)->byId('religious', false, [], [], 'فهمت عليك يا يامن، اعتراضك من ناحية شرعية.')['text'];
        $this->assertStringStartsWith('فهمت عليك يا يامن', $religious);
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted', 'voting_intention' => 'no', 'sent_media_ids' => ['no-vote'], 'previously_answered_topics' => ['اعتراض: religious']];
        $turns = [
            ['role' => 'assistant', 'message' => $religious],
            ['role' => 'user', 'message' => 'معك حق'],
        ];

        // Short answer: the word lists.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'agree', 'reply' => 'شكراً إلك يا يامن.']);
        $short = $this->engine()->turn($turns, 'يامن', $prior);
        $this->assertSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::COMMIT_MALE, $short['text']);

        // Longer agreement: the model reads it.
        $turns[1]['message'] = 'والله معك حق بهالنقطة، ما فكرت فيها هيك';
        $long = $this->engine()->turn($turns, 'يامن', $prior);
        $this->assertSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::COMMIT_MALE, $long['text']);

        // After a reply built from arguments, agreement asks for the commitment too.
        $argumentsReply = "بفهم إحباطك.\n".(new \App\Services\AiChatbot\DimaV3\ArgumentLibrary)->get('change_2021')['text']."\nسؤال؟";
        $after = $this->engine()->turn([
            ['role' => 'assistant', 'message' => $argumentsReply],
            ['role' => 'user', 'message' => 'صح كلامك'],
        ], 'يامن', $prior);
        $this->assertSame(\App\Services\AiChatbot\DimaV3\ObjectionReplies::COMMIT_MALE, $after['text']);
    }

    public function test_identity_questions_get_a_warm_fixed_answer_and_the_chat_goes_on(): void
    {
        $open = ['current_stage' => 'open', 'permission_status' => 'granted', 'voting_intention' => 'yes', 'party_mentioned' => 'الديمقراطيين'];
        $turns = [
            ['role' => 'assistant', 'message' => "كلام.\nمين برأيك رح يحارب عن بلدك على طاولة الحكومة؟"],
            ['role' => 'user', 'message' => 'انت من الموحده منك بتحكي معي'],
        ];

        // The real chat: asked directly about الموحدة. Model text was a confession plus a non-voter argument.
        ConversationEngine::$testResponder = fn (): string => $this->payload([
            'intent' => 'ask_united',
            'party' => 'united',
            'ack' => 'آه، هالمحادثة من طرف الموحدة، وأنا ديما، ردود آلية مش إنسانة.',
            'reply' => 'آه، هالمحادثة من طرف الموحدة، وأنا ديما، ردود آلية مش إنسانة. مش عرض محايد.',
        ]);
        $united = $this->engine()->turn($turns, 'يامن', $open);
        $this->assertStringStartsWith('إيه، بحكي مع الناس من طرف الموحدة', $united['text']);
        $this->assertStringNotContainsString('مش إنسانة', $united['text']);
        $this->assertStringNotContainsString('بالبيت', $united['text']);
        $this->assertStringEndsWith('مين برأيك رح يحارب عن بلدك على طاولة الحكومة؟', $united['text']);
        $this->assertSame([], $united['clips']);

        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'push_who', 'reply' => 'من طرف الموحدة.']);
        $who = $this->engine()->turn($turns, 'يامن', $open);
        $this->assertStringStartsWith('شوف، أنا ديما من مركز اتصالات بالوسط العربي', $who['text']);

        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'ask_bot', 'reply' => 'أنا ردود آلية مش إنسانة.']);
        $bot = $this->engine()->turn($turns, 'يامن', $open);
        $this->assertStringStartsWith('ردودي آلية 😊', $bot['text']);
        $this->assertStringNotContainsString('مش إنسانة', $bot['text']);
    }

    public function test_voters_never_get_stay_home_arguments_and_fallback_fits_the_party(): void
    {
        // A Joint List voter pushing back; the model picked nothing.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'objection', 'party' => 'joint', 'arguments' => [], 'ack' => 'فهمت عليك.']);
        $prior = ['current_stage' => 'open', 'permission_status' => 'granted', 'voting_intention' => 'yes', 'party_mentioned' => 'المشتركة', 'previously_answered_topics' => ['اعتراض: joint']];
        $decision = $this->engine()->turn([['role' => 'user', 'message' => 'ليش مش للمشتركه اصوت، هم الاصدق']], 'سامي', $prior);

        $this->assertStringNotContainsString('بالبيت', $decision['text']);
        $this->assertStringNotContainsString('ما نصوت', $decision['text']);
        $this->assertStringContainsString('المشتركة', $decision['text']);

        // A non-voter still gets the stay-home arguments.
        ConversationEngine::$testResponder = fn (): string => $this->payload(['intent' => 'objection', 'topic' => '', 'arguments' => ['change_2021']]);
        $nonVoter = $this->engine()->turn([['role' => 'user', 'message' => 'ما رح اصوت ولا بحلم']], 'سامي', ['current_stage' => 'open', 'permission_status' => 'granted', 'voting_intention' => 'no', 'sent_media_ids' => ['impact-8']]);
        $this->assertStringContainsString('2021', $nonVoter['text']);
    }

    public function test_prompt_carries_the_fact_sheet_and_real_clips(): void
    {
        $prompt = '';
        ConversationEngine::$testResponder = function (array $messages) use (&$prompt): string {
            $prompt = (string) $messages[0]['content'];

            return $this->payload();
        };
        $this->engine()->turn([['role' => 'user', 'message' => 'مرحبا']], 'سامي', []);

        $this->assertStringContainsString('قرار 550', $prompt);
        $this->assertStringContainsString('impact-7', $prompt);
        $this->assertStringNotContainsString('fixture-clip', $prompt);
    }

    private function payload(array $overrides = []): string
    {
        $json = json_encode(array_merge([
            'reply' => 'تمام.',
            'stage' => 'introduction',
            'permission_status' => '',
            'voting_intention' => '',
            'party_mentioned' => '',
            'last_user_question' => '',
            'answered_topic' => '',
            'media_id' => '',
            'media_description' => '',
        ], $overrides), JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{}';
    }

    private function v2Payload(string $text = 'رد'): string
    {
        $json = json_encode([
            'reply' => $text,
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
            'memory' => [
                'known' => '',
                'topic' => '',
                'answered' => '',
                'open_question' => '',
                'concern' => '',
                'wants_to_continue' => true,
            ],
        ], JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{}';
    }
}
