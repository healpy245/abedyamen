<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Services\AiChatbot\DimaContextBuilder;
use App\Services\AiChatbot\DimaConversationService;
use App\Services\AiChatbot\DimaConversationState;
use App\Services\AiChatbot\DimaEngine;
use App\Services\AiChatbot\DimaPrompt;
use Tests\TestCase;

/**
 * Structural checks for Dima V2. A mocked reply proves the engine kept it.
 * It does not prove how the live model would phrase the same turn.
 */
class DimaV2ConversationTest extends TestCase
{
    protected function tearDown(): void
    {
        DimaConversationService::$testResponder = null;
        DimaConversationState::$impactOrder = null;
        parent::tearDown();
    }

    public function test_a_challenge_to_the_previous_claim_is_kept(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('هيك قصدي إن النتائج ناقصة بسبب قلة التصويت، وهاد تفسير مش رقم من الكراس.');
        };

        $decision = $this->turn([
            ['role' => 'assistant', 'message' => 'الموحدة ضخت ميزانيات أكبر للوسط العربي.'],
            ['role' => 'user', 'message' => 'هيك انت شايف يعني؟'],
        ]);

        $this->assertSame(1, $calls);
        $this->assertSame('هيك قصدي إن النتائج ناقصة بسبب قلة التصويت، وهاد تفسير مش رقم من الكراس.', $decision['text']);
        $this->assertFalse($decision['close']);
        $this->assertFalse($decision['video']);
        $this->assertStringNotContainsString('صوتك لازم يكون للموحدة', $decision['text']);
    }

    public function test_a_budget_shaped_model_answer_is_not_swapped_for_a_script(): void
    {
        DimaConversationService::$testResponder = fn (): string => $this->reply(
            'قرار 549 خطة ثانية للجريمة، منفصلة عن 550.',
            true,
        );

        $decision = $this->turn([
            ['role' => 'assistant', 'message' => 'صوتك مهم.'],
            ['role' => 'user', 'message' => 'هيك انت شايف يعني؟'],
        ]);

        $this->assertSame('قرار 549 خطة ثانية للجريمة، منفصلة عن 550.', $decision['text']);
        $this->assertFalse($decision['close']);
        $this->assertStringNotContainsString('قلة التصويت من الناس', $decision['text']);
    }

    public function test_an_unexpected_topic_change_follows_the_new_message(): void
    {
        $seen = '';
        DimaConversationService::$testResponder = function (array $messages) use (&$seen): string {
            $seen = (string) ($messages[1]['content'] ?? '');

            return $this->reply('غولان رئيس الديمقراطيين من 2024، حسب المعهد.');
        };

        $decision = $this->turn([
            ['role' => 'user', 'message' => 'الميزانية ما وصلت'],
            ['role' => 'assistant', 'message' => 'في ميزانيات انقرت.'],
            ['role' => 'user', 'message' => 'طيب مين يائير غولان؟'],
        ], ['memory' => ['topic' => 'الميزانية', 'known' => 'قال الميزانية ما وصلت']]);

        $this->assertSame('غولان رئيس الديمقراطيين من 2024، حسب المعهد.', $decision['text']);
        $this->assertStringContainsString('آخر رسالة من الشخص', $seen);
        $this->assertStringContainsString('مين يائير غولان؟', $seen);
        $this->assertFalse($decision['close']);
    }

    public function test_two_factual_questions_and_a_source_request_are_kept(): void
    {
        DimaConversationService::$testResponder = fn (): string => $this->reply('من الكراس: وقف الهدم، وقرار 550. المصدر كراس الكادر.');

        $decision = $this->turn([
            ['role' => 'user', 'message' => 'شو عملوا للهدم، وقديش الميزانية؟ واعطيني مصدر'],
        ]);

        $this->assertStringContainsString('كراس الكادر', $decision['text']);
        $this->assertFalse($decision['close']);
    }

    public function test_a_disputed_claim_and_repeated_argument_are_not_restarted(): void
    {
        DimaConversationService::$testResponder = fn (): string => $this->reply('معك حق تختلف. الرقم اللي قلته من الكراس، والتفسير إلي.');

        $decision = $this->turn([
            ['role' => 'assistant', 'message' => 'صوتك لازم يكون للموحدة.'],
            ['role' => 'user', 'message' => 'خلص بطلت اسمع نفس الحجة، والرقم غلط'],
        ]);

        $this->assertSame('معك حق تختلف. الرقم اللي قلته من الكراس، والتفسير إلي.', $decision['text']);
        $this->assertStringNotContainsString('تتضاعف', $decision['text']);
    }

    public function test_naming_another_party_does_not_close_or_force_a_pitch(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('الديمقراطيين قائمة من 2024، وهاد وصف المعهد.', true);
        };
        DimaConversationState::$impactOrder = ['impact-2', 'impact-1'];

        $decision = $this->turn([
            ['role' => 'user', 'message' => 'بصوت للديمقراطيين'],
        ]);

        $this->assertSame(0, $calls);
        $this->assertFalse($decision['close']);
        $this->assertFalse($decision['video']);
        $this->assertStringContainsString('غريب إنه نسمع عربي', $decision['text']);
        $this->assertStringContainsString('ليش ما بتصوت لحزب مثل الموحدة؟', $decision['text']);
        $this->assertStringNotContainsString('هدم', $decision['text']);
        $this->assertStringNotContainsString('أربعة', $decision['text']);
        $this->assertSame(['impact-5'], array_column($decision['clips'], 'id'));
        $talk = $this->turn([
            ['role' => 'user', 'message' => 'تمام شفته'],
        ], ['memory' => ['clips' => 'impact-2']]);
        $pushback = $this->turn([
            ['role' => 'user', 'message' => 'ما اقتنعت'],
        ], ['memory' => ['clips' => 'impact-5']]);
        $this->assertSame(1, $calls);
        $this->assertArrayNotHasKey('clips', $talk);
        $this->assertStringContainsString('الديمقراطيين', $talk['text']);
        $this->assertSame(['impact-2'], array_column($pushback['clips'], 'id'));
        $this->assertStringContainsString('ما اقتنعت', $pushback['text']);
        $this->assertNotSame($decision['text'], $pushback['text']);
    }

    public function test_a_yes_asks_who_and_only_raam_closes(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('تمام، شكرا إلّك على الرد.');
        };

        $who = $this->turn([
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'اكيد'],
        ]);
        $female = app(DimaConversationService::class)->turn([
            ['role' => 'assistant', 'message' => 'ناوية تصوتي بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'أكيد'],
        ], 'ليلى');
        $thanks = $this->turn([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'للموحدة'],
        ]);
        $joint = $this->turn([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'للمشتركة'],
        ]);

        $this->assertSame(0, $calls);
        $this->assertSame('لمين رح تصوت المره هاي؟', $who['text']);
        $this->assertFalse($who['close']);
        $this->assertSame('لمين رح تصوتي المره هاي؟', $female['text']);
        $this->assertSame('تمام شكرا الك كتير على وقتك، نهارك سعيد 🌷', $thanks['text']);
        $this->assertTrue($thanks['close']);
        $this->assertStringContainsString('خيار بنحترمه', $joint['text']);
        $this->assertFalse($joint['close']);
        $this->assertSame(['impact-2'], array_column($joint['clips'], 'id'));
    }

    public function test_a_short_ambiguous_answer_is_kept(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('تمام، كملي.');
        };

        $agreed = $this->turn([
            ['role' => 'assistant', 'message' => 'بتسمحلي أسألك سؤال صغير؟'],
            ['role' => 'user', 'message' => 'اه'],
        ]);
        $elsewhere = $this->turn([
            ['role' => 'assistant', 'message' => 'الميزانية انقرت وما انصرفت كلها.'],
            ['role' => 'user', 'message' => 'اه'],
        ]);

        $this->assertSame('ناوي تصوت بالانتخابات الجاي؟', $agreed['text']);
        $this->assertSame(1, $calls);
        $this->assertSame('تمام، كملي.', $elsewhere['text']);
        $this->assertFalse($elsewhere['close']);
    }

    public function test_an_explicit_stop_closes_without_a_model_call(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('ما رح اسكت.');
        };

        $decision = $this->turn([
            ['role' => 'user', 'message' => 'خلص ما بدي أكمل'],
        ]);

        $this->assertSame(0, $calls);
        $this->assertTrue($decision['close']);
        $this->assertSame(DimaPrompt::REFUSED, $decision['stance']);
        $this->assertSame(DimaPrompt::STAGE_CLOSED, $decision['stage']);
        $this->assertSame('تمام، ما برجعلش رسالة.', $decision['text']);
        $this->assertFalse($decision['memory']['wants_to_continue']);
    }

    public function test_resuming_an_earlier_topic_keeps_both_messages_in_context(): void
    {
        $builder = app(DimaContextBuilder::class);
        $packet = $builder->build([
            ['role' => 'user', 'message' => 'حكينا عن غولان'],
            ['role' => 'assistant', 'message' => 'غولان رئيسهم من 2024.'],
            ['role' => 'user', 'message' => 'ارجع لموضوع غولان'],
        ], 'أحمد', ['memory' => ['topic' => 'غولان', 'answered' => 'مين رئيسهم']]);

        $this->assertStringContainsString('حكينا عن غولان', $packet);
        $this->assertStringContainsString('ارجع لموضوع غولان', $packet);
        $this->assertStringContainsString('أسئلة انجاوبت: مين رئيسهم', $packet);
    }

    public function test_a_correction_is_kept_and_a_failed_model_does_not_invent_a_speech(): void
    {
        DimaConversationService::$testResponder = fn (): string => $this->reply('صح، أنا فهمتك غلط. قصدك النتيجة مش القيادة.');
        $corrected = $this->turn([
            ['role' => 'user', 'message' => 'لا، أنا ما حكيت عن القيادة'],
        ]);
        $this->assertStringContainsString('فهمتك غلط', $corrected['text']);

        DimaConversationService::$testResponder = fn (): string => '';
        $failed = $this->turn([
            ['role' => 'user', 'message' => 'هيك انت شايف يعني؟'],
        ]);
        $this->assertSame('صار خلل بالرد. عيدي آخر جملة بجملة وحدة.', $failed['text']);
        $this->assertStringNotContainsString('549', $failed['text']);
        $this->assertStringNotContainsString('الموحدة', $failed['text']);
        $this->assertFalse($failed['close']);
    }

    public function test_permission_opens_the_original_voting_question_without_a_model_call(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('شو أكتر إشي شاغل بالك بهالأيام؟');
        };
        $permission = [
            ['role' => 'assistant', 'message' => 'بتسمحلي أسألك سؤال صغير؟'],
        ];

        $agreed = app(DimaConversationService::class)->turn([
            ...$permission,
            ['role' => 'user', 'message' => 'تفضلي'],
        ], 'أحمد');
        $sure = app(DimaConversationService::class)->turn([
            ...$permission,
            ['role' => 'user', 'message' => 'أكيد'],
        ], 'أحمد');
        $which = app(DimaConversationService::class)->turn([
            ...$permission,
            ['role' => 'user', 'message' => 'شو السؤال؟'],
        ], 'أحمد');
        $female = app(DimaConversationService::class)->turn([
            ['role' => 'assistant', 'message' => 'بتسمحيلي أسالكي سؤال صغير؟'],
            ['role' => 'user', 'message' => 'تفضلي'],
        ], 'ليلى');

        $this->assertSame(0, $calls);
        $this->assertSame('ناوي تصوت بالانتخابات الجاي؟', $agreed['text']);
        $this->assertSame('ناوي تصوت بالانتخابات الجاي؟', $sure['text']);
        $this->assertSame('ناوي تصوت بالانتخابات الجاي؟', $which['text']);
        $this->assertSame('ناوية تصوتي بالانتخابات الجاي؟', $female['text']);
        $this->assertSame(DimaPrompt::STAGE_VOTING_INTENTION_ASKED, $agreed['stage']);
        $this->assertFalse($agreed['close']);
    }

    public function test_a_stated_intention_and_an_answered_vote_question_are_not_asked_again(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('ناوي تصوت بالانتخابات الجاي؟');
        };

        $declined = $this->turn([
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'أنا مش ناوي أصوت'],
        ]);
        $again = $this->turn([
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'لا'],
            ['role' => 'user', 'message' => 'ليش؟'],
        ], ['stage' => DimaPrompt::STAGE_VOTING_INTENTION_ASKED]);

        $this->assertSame(1, $calls);
        $this->assertTrue($declined['video']);
        $this->assertStringContainsString('وبعد ها الفيديو', $declined['text']);
        $this->assertStringNotContainsString('ناوي تصوت بالانتخابات', $declined['text']);
        $this->assertSame('تمام، سمعت موقفك.', $again['text']);
        $this->assertFalse($declined['close']);
    }

    public function test_a_bare_no_after_the_vote_question_restores_the_video_message(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('تمام، بحترم اختيارك. شو الأسباب اللي خلتك ما تصوت؟');
        };

        $no = $this->turn([
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'لا'],
        ]);
        $female = app(DimaConversationService::class)->turn([
            ['role' => 'assistant', 'message' => 'ناوية تصوتي بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'لا'],
        ], 'ليلى');

        $this->assertSame(0, $calls);
        $this->assertTrue($no['video']);
        $this->assertFalse($no['close']);
        $this->assertSame(
            "وبعد ها الفيديو شو قلت؟ بدك نعيش كمان 4 سنين بهالحكومة العنصرية؟\n4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.\nبقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟",
            $no['text'],
        );
        $this->assertStringContainsString('شو قلتي', $female['text']);
        $this->assertStringContainsString('ما تصوّتي', $female['text']);
        $this->assertTrue($female['video']);
    }

    public function test_a_clear_concern_is_answered_without_another_question(): void
    {
        $prompt = '';
        DimaConversationService::$testResponder = function (array $messages) use (&$prompt): string {
            $prompt = (string) ($messages[0]['content'] ?? '');

            return $this->reply('فهمت عليك، مش شايف نتيجة من التصويت. شو التغيير اللي كنت متوقع تشوفه؟');
        };

        DimaConversationState::$impactOrder = ['impact-1', 'impact-2'];
        $decision = $this->turn([
            ['role' => 'assistant', 'message' => 'بقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟'],
            ['role' => 'user', 'message' => 'مش شايف نتيجة من التصويت'],
        ]);
        DimaConversationService::$testResponder = function (array $messages) use (&$prompt): string {
            $prompt = (string) ($messages[0]['content'] ?? '');

            return $this->reply('النتائج السياسية مش دايما بتبين بسرعة، وفي فرق بين القرارات اللي بتنقر وبين اللي بتتنفذ فعليا.');
        };
        $kept = $this->turn([
            ['role' => 'assistant', 'message' => 'هاذ فيديو قصير، يا ريت تعطيني فرصة وتشاهده حتى النهاية. رح تعرف قديش صوتك مهم، والأهم إنه يكون للموحدة.'],
            ['role' => 'user', 'message' => 'طيب وشو معنى القرار؟'],
        ], ['memory' => ['clips' => 'impact-1,impact-2']]);

        $this->assertStringContainsString('يا ريت تشوفه للآخر', $decision['text']);
        $this->assertStringNotContainsString('2.5', $decision['text']);
        $this->assertSame(['impact-5'], array_column($decision['clips'], 'id'));
        $this->assertStringNotContainsString('شو التغيير', $decision['text']);
        $this->assertStringNotContainsString('؟', $decision['text']);
        $this->assertSame('النتائج السياسية مش دايما بتبين بسرعة، وفي فرق بين القرارات اللي بتنقر وبين اللي بتتنفذ فعليا.', $kept['text']);
        $this->assertStringNotContainsString('سؤال واحد فقط', $prompt);
        $this->assertStringContainsString('السؤال مش خاتمة الرسالة', $prompt);
        $this->assertStringContainsString('لا تعيدي جملته', $prompt);
        $this->assertFalse($decision['close']);
    }

    public function test_a_topic_change_after_a_concern_follows_the_new_subject(): void
    {
        $seen = '';
        DimaConversationService::$testResponder = function (array $messages) use (&$seen): string {
            $seen = (string) ($messages[1]['content'] ?? '');

            return $this->reply('غولان رئيس الديمقراطيين من 2024، حسب المعهد.');
        };

        $decision = $this->turn([
            ['role' => 'assistant', 'message' => 'النتائج السياسية مش دايما بتبين بسرعة.'],
            ['role' => 'user', 'message' => 'مش شايف نتيجة من التصويت'],
            ['role' => 'assistant', 'message' => 'في فرق بين قرار انقر وبين اللي تنفذ.'],
            ['role' => 'user', 'message' => 'طيب مين يائير غولان؟'],
        ]);

        $this->assertSame('غولان رئيس الديمقراطيين من 2024، حسب المعهد.', $decision['text']);
        $this->assertStringContainsString('آخر رسالة من الشخص', $seen);
        $this->assertStringContainsString('مين يائير غولان؟', $seen);
        $this->assertStringNotContainsString('شو التغيير', $decision['text']);
    }

    public function test_an_identity_question_names_the_organization_and_a_stop_closes_without_a_model_call(): void
    {
        $calls = 0;
        DimaConversationService::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->reply('شو أكتر إشي شاغل بالك؟');
        };

        $who = $this->turn([
            ['role' => 'user', 'message' => 'مين الجهة اللي بتحكي معي؟'],
        ]);
        $named = app(DimaConversationService::class)->turn([
            ['role' => 'assistant', 'message' => 'بتسمحيلي أسالكي سؤال صغير؟'],
            ['role' => 'user', 'message' => 'تفضلي بس مين معي'],
        ], 'ليلى');
        $later = $this->turn([
            ['role' => 'assistant', 'message' => 'هل رح تطلع تصوت هاي المرة ولا كيف؟'],
            ['role' => 'user', 'message' => 'مين معي'],
        ]);
        $stop = $this->turn([
            ['role' => 'user', 'message' => 'خلص لا تبعثولي'],
        ]);

        $this->assertSame(0, $calls);
        $this->assertStringContainsString('أنا ديما من مركز استطلاعات للوسط العربي', $who['text']);
        $this->assertStringContainsString('هل رح تطلع تصوت هاي المرة ولا كيف؟', $who['text']);
        $this->assertStringNotContainsString('القائمة العربية الموحدة', $who['text']);
        $this->assertSame(DimaPrompt::STAGE_VOTING_INTENTION_ASKED, $who['stage']);
        $this->assertStringContainsString('هل رح تطلعي تصوتي هاي المرة ولا كيف؟', $named['text']);
        $this->assertStringNotContainsString('القائمة العربية الموحدة', $named['text']);
        $this->assertSame('أنا ديما من مركز استطلاعات للوسط العربي.', $later['text']);
        $this->assertFalse($who['close']);
        $this->assertTrue($stop['close']);
        $this->assertSame('تمام، ما برجعلش رسالة.', $stop['text']);
        $declined = $this->turn([
            ['role' => 'assistant', 'message' => 'بتسمحلي أسألك سؤال صغير؟'],
            ['role' => 'user', 'message' => 'لا'],
        ]);
        $this->assertSame('تمام.', $declined['text']);
        $this->assertFalse($declined['close']);
        $this->assertStringNotContainsString('ناوي تصوت', $declined['text']);
        $this->assertSame(0, $calls);
    }

    public function test_dima_and_the_survey_bot_do_not_share_a_prompt_and_dima_does_not_send(): void
    {
        $this->assertFalse(DimaEngine::allowsOutbound());
        $dima = DimaPrompt::instructions();
        $survey = \App\Services\AiChatbot\MansourPrompt::defaultCampaignPrompt();

        $this->assertStringNotContainsString('ناوي تصوّت ولا كيف', $dima);
        $this->assertStringNotContainsString('ناوي تصوت بالانتخابات الجاي', $survey);
        $this->assertStringNotContainsString('مركز اتصالات للوسط العربي', $survey);
        $this->assertStringContainsString('ناوي تصوّت ولا كيف', $survey);
    }

    public function test_the_engine_flag_keeps_v1_until_v2_is_selected(): void
    {
        config(['dima.engine' => 'v1']);
        $this->assertFalse(DimaEngine::usesV2());

        config(['dima.engine' => 'v2']);
        $this->assertTrue(DimaEngine::usesV2());
        DimaConversationService::$testResponder = fn (): string => $this->reply('جواب V2.');
        $decision = DimaEngine::nextReply([
            ['role' => 'user', 'message' => 'هيك انت شايف يعني؟'],
        ], 'أحمد');
        $this->assertSame('جواب V2.', $decision['text']);
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @param  array<string, mixed>  $prior
     * @return array<string, mixed>
     */
    private function turn(array $turns, array $prior = []): array
    {
        return app(DimaConversationService::class)->turn($turns, 'أحمد', $prior);
    }

    private function reply(string $text, bool $close = false): string
    {
        return json_encode([
            'reply' => $text,
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => $close,
            'memory' => [
                'known' => '',
                'topic' => '',
                'answered' => '',
                'open_question' => '',
                'concern' => '',
                'wants_to_continue' => true,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
