<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Services\AiChatbot\DimaFacts;
use App\Services\AiChatbot\DimaPrompt;
use Tests\TestCase;

class DimaDemocratsContinuityTest extends TestCase
{
    protected function tearDown(): void
    {
        DimaPrompt::$testResponder = null;
        parent::tearDown();
    }

    public function test_party_records_stay_sourced_and_do_not_invent_harm(): void
    {
        foreach (DimaFacts::partyEntries() as $entry) {
            $this->assertNotSame('', $entry['source_url']);
            $this->assertNotSame('', $entry['source_name']);
            $this->assertNotSame('', $entry['last_verified_at']);
            $this->assertDoesNotMatchRegularExpression('/مجرم|إبادة|ابادة|يكره العرب|خائن/u', $entry['claim']);
        }

        $named = DimaFacts::forThread('ديموقراطيين');
        $this->assertStringContainsString('2024', $named);
        $this->assertStringContainsString('غولان', $named);
        $this->assertStringContainsString('idi.org.il', $named);

        $confirmed = DimaFacts::forThread('قصدي الديمقراطيين بإسرائيل');
        $this->assertStringContainsString('2024', $confirmed);

        $hebrew = DimaFacts::forThread('הדמוקרטים');
        $this->assertStringContainsString('הדמוקרטים', $hebrew);

        $merger = DimaFacts::forThread('العمل وميرتس');
        $this->assertStringContainsString('تجنيد للكل', $merger);

        $this->assertStringNotContainsString('غولان', DimaFacts::forThread('الحزب الديمقراطي الأمريكي'));
        $this->assertStringNotContainsString('הדמוקרטים', DimaFacts::forThread('قديش من الميزانية وصل فعليا؟'));
    }

    public function test_a_misspelled_party_name_stays_open_and_gets_an_introduction(): void
    {
        $calls = 0;
        DimaPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return $this->respectCloser();
        };

        $decision = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'ديموقراطيين'],
        ], 'أحمد');

        $this->assertSame(1, $calls);
        $this->assertFalse($decision['close']);
        $this->assertSame(DimaPrompt::ZIONIST, $decision['stance']);
        $this->assertNotSame(DimaPrompt::STAGE_CLOSED, $decision['stage']);
        $this->assertTrue($decision['memory']['wants_to_continue']);
        $this->assertSame('الديمقراطيون بإسرائيل', $decision['memory']['topic']);
        $this->assertStringContainsString('2024', $decision['text']);
        $this->assertStringContainsString('غريب', $decision['text']);
        $this->assertStringContainsString('الديمقراطيين', $decision['text']);
        $this->assertStringContainsString('غولان', $decision['text']);
        $this->assertStringContainsString('يعني', $decision['text']);
        $this->assertStringNotContainsString('بحترم اختيارك', $decision['text']);
        $this->assertStringNotContainsString('نهارك سعيد', $decision['text']);
        $this->assertStringNotContainsString('وقفت الهدم', $decision['text']);
        $this->assertLessThan(480, mb_strlen($decision['text']));
    }

    public function test_hamza_spelling_and_an_explicit_israeli_confirmation_resolve_the_same_party(): void
    {
        DimaPrompt::$testResponder = fn (): string => $this->respectCloser();
        $hamza = 'ديم'.'ؤ'.'قراطيين';

        $misspelled = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => $hamza],
        ], 'أحمد');
        $confirmed = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'قصدي الديمقراطيين بإسرائيل'],
        ], 'أحمد');

        foreach ([$misspelled, $confirmed] as $decision) {
            $this->assertFalse($decision['close']);
            $this->assertStringContainsString('لحزب مثل الموحدة', $decision['text']);
            $this->assertStringContainsString('غريب', $decision['text']);
            $this->assertStringContainsString('لزيمي', $decision['text']);
            $this->assertStringContainsString('2024', $decision['text']);
            $this->assertStringContainsString('ميرتس', $decision['text']);
        }
    }

    public function test_hebrew_name_and_labor_meretz_stay_open(): void
    {
        DimaPrompt::$testResponder = fn (): string => $this->respectCloser();

        $hebrew = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'הדמוקרטים'],
        ], 'أحمد');
        $merger = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'العمل وميرتس'],
        ], 'أحمد');

        $this->assertFalse($hebrew['close']);
        $this->assertFalse($merger['close']);
        $this->assertStringContainsString('הדמוקרטים', $hebrew['text']);
        $this->assertStringContainsString('ميرتس', $merger['text']);
        $this->assertSame(DimaPrompt::ZIONIST, $hebrew['stance']);
    }

    public function test_a_sourced_model_answer_is_kept_but_cannot_close(): void
    {
        $reply = 'غولان رئيس הדמוקרטים ونائب ميرتس، ومعاه لزيمي وكريف وريتن. من 2024 الحزب صهيوني: تجنيد للكل وانفصال عن الفلسطينيين. هاد ضد قضيتنا. الموحدة قائمة عربية جوا القرار، وأربعة مقاعد وقفت الهدم. لحزب مثل الموحدة؟';
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => $reply,
            'stance' => DimaPrompt::UNITED,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        $decision = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'الديمقراطيين'],
        ], 'أحمد');

        $this->assertStringContainsString('لحزب مثل الموحدة', $decision['text']);
        $this->assertStringContainsString('غريب', $decision['text']);
        $this->assertStringContainsString('يعني', $decision['text']);
        $this->assertStringContainsString('لزيمي', $decision['text']);
        $this->assertStringContainsString('صهيون', $decision['text']);
        $this->assertStringNotContainsString('هاد ضد قضيتنا', $decision['text']);
        $this->assertStringNotContainsString('وقفت الهدم', $decision['text']);
        $this->assertFalse($decision['close']);
        $this->assertSame(DimaPrompt::ZIONIST, $decision['stance']);
    }

    public function test_followups_use_context_and_keep_facts_apart_from_accusations(): void
    {
        DimaPrompt::$testResponder = fn (): string => $this->respectCloser();
        $prior = [
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'قصدي الديمقراطيين بإسرائيل'],
            ['role' => 'assistant', 'message' => 'الديمقراطيون بإسرائيل قائمة سنة 2024.'],
        ];

        $leader = DimaPrompt::nextReply([...$prior, ['role' => 'user', 'message' => 'مين بقودهم؟']], 'أحمد');
        $arabs = DimaPrompt::nextReply([...$prior, ['role' => 'user', 'message' => 'شو موقفهم من العرب؟']], 'أحمد');
        $raam = DimaPrompt::nextReply([...$prior, ['role' => 'user', 'message' => 'شو الفرق بينهم وبين الموحدة؟']], 'أحمد');
        $joint = DimaPrompt::nextReply([...$prior, ['role' => 'user', 'message' => 'شو الفرق بينهم وبين المشتركة؟']], 'أحمد');
        $golan = DimaPrompt::nextReply([['role' => 'user', 'message' => 'شو عمل يائير غولان؟']], 'أحمد');
        $source = DimaPrompt::nextReply([...$prior, ['role' => 'user', 'message' => 'اعطيني مصدر']], 'أحمد');

        $this->assertStringContainsString('غولان', $leader['text']);
        $this->assertStringContainsString('2024', $leader['text']);
        $this->assertStringContainsString('ميرتس', $leader['text']);

        $this->assertStringContainsString('قانون القومية', $arabs['text']);
        $this->assertStringContainsString('مساواة', $arabs['text']);
        $this->assertStringContainsString('مش من سجل تصويت', $arabs['text']);
        $this->assertDoesNotMatchRegularExpression('/مجرم|يكره العرب|عنصريين/u', $arabs['text']);

        $this->assertStringContainsString('الموحدة', $raam['text']);
        $this->assertStringContainsString('غولان', $raam['text']);
        $this->assertStringNotContainsString('أحسن', $raam['text']);

        $this->assertStringContainsString('المشتركة', $joint['text']);
        $this->assertStringContainsString('غولان', $joint['text']);
        $this->assertStringNotContainsString('أحسن', $joint['text']);

        $this->assertStringContainsString('ميرتس', $golan['text']);
        $this->assertStringContainsString('2024', $golan['text']);
        $this->assertStringContainsString('لواء احتياط', $golan['text']);
        $this->assertDoesNotMatchRegularExpression('/مجرم|قتل|إبادة/u', $golan['text']);

        $this->assertStringContainsString('democrats.org', $source['text']);
        $this->assertStringContainsString('idi.org.il', $source['text']);

        foreach ([$leader, $arabs, $raam, $joint, $golan, $source] as $decision) {
            $this->assertFalse($decision['close']);
            $this->assertNotSame(DimaPrompt::STAGE_CLOSED, $decision['stage']);
            $this->assertTrue($decision['memory']['wants_to_continue']);
            $this->assertStringNotContainsString('بحترم اختيارك', $decision['text']);
            $this->assertStringNotContainsString('ما بقدر أحسم', $decision['text']);
            $this->assertLessThan(320, mb_strlen($decision['text']));
        }
    }

    public function test_an_explicit_opt_out_closes_immediately_and_a_party_name_does_not(): void
    {
        $calls = 0;
        DimaPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return json_encode([
                'reply' => 'يائير غولان رئيس الديمقراطيين، خلينا نكمل.',
                'stance' => DimaPrompt::OPEN,
                'note' => '',
                'close' => false,
            ], JSON_UNESCAPED_UNICODE);
        };

        $stop = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'ديموقراطيين'],
            ['role' => 'assistant', 'message' => 'رئيسها يائير غولان.'],
            ['role' => 'user', 'message' => 'خلص ما بدي أكمل'],
        ], 'أحمد');

        $this->assertSame(0, $calls);
        $this->assertTrue($stop['close']);
        $this->assertSame(DimaPrompt::REFUSED, $stop['stance']);
        $this->assertSame(DimaPrompt::STAGE_CLOSED, $stop['stage']);
        $this->assertFalse($stop['memory']['wants_to_continue']);
        $this->assertStringNotContainsString('غولان', $stop['text']);
        $this->assertLessThan(40, mb_strlen($stop['text']));
    }

    public function test_the_american_democrats_are_not_treated_as_the_israeli_list(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'بحترم اختيارك',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        $decision = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'الحزب الديمقراطي الأمريكي'],
        ], 'أحمد');

        $this->assertStringNotContainsString('2024', $decision['text']);
        $this->assertStringNotContainsString('ميرتس', $decision['text']);
        $this->assertFalse($decision['close']);
    }

    public function test_a_changed_ideas_defense_keeps_convincing_for_the_united_list(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'ممكن تكون أفكارهم تغيرت، بس المصدر اللي استندت إله من 2024، فما بقدر أحسم منه موقفهم اليوم. لازم نقارن برنامجهم الحالي بالقديم.',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        $decision = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'اكيد الديمقراطيين'],
            ['role' => 'assistant', 'message' => 'الحزب صهيوني وبرنامجه تجنيد للكل.'],
            ['role' => 'user', 'message' => 'لا بس يعني هي دمقراطيين وافكارهم تغيرت.'],
        ], 'أحمد');

        $this->assertFalse($decision['close']);
        $this->assertNotSame(DimaPrompt::STAGE_CLOSED, $decision['stage']);
        $this->assertStringContainsString('الموحدة', $decision['text']);
        $this->assertStringContainsString('يعني', $decision['text']);
        $this->assertStringContainsString('لحزب مثل الموحدة', $decision['text']);
        $this->assertStringNotContainsString('وقفت الهدم', $decision['text']);
        $this->assertStringNotContainsString('ما بقدر أحسم', $decision['text']);
        $this->assertStringNotContainsString('لازم نقارن', $decision['text']);
        $this->assertLessThan(480, mb_strlen($decision['text']));
    }

    public function test_the_recorded_choice_line_does_not_end_the_chat(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'بحترم اختيارك لحزب الديمقراطيين، وسجلت جوابك.',
            'stance' => DimaPrompt::ZIONIST,
            'note' => '',
            'close' => true,
        ], JSON_UNESCAPED_UNICODE);

        $decision = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'الاكيد'],
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'لحزب الديمقراطيين'],
        ], 'Test customer');

        $this->assertFalse($decision['close']);
        $this->assertSame(DimaPrompt::ZIONIST, $decision['stance']);
        $this->assertStringContainsString('2024', $decision['text']);
        $this->assertStringContainsString('غولان', $decision['text']);
        $this->assertStringNotContainsString('سجلت جوابك', $decision['text']);
        $this->assertStringNotContainsString('بحترم اختيارك', $decision['text']);
    }

    public function test_an_offline_party_name_still_introduces_the_list(): void
    {
        DimaPrompt::$testResponder = fn (): string => '';

        $decision = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'דמוקרטים'],
        ], 'أحمد');

        $this->assertFalse($decision['close']);
        $this->assertStringContainsString('2024', $decision['text']);
        $this->assertStringContainsString('غولان', $decision['text']);
    }

    private function respectCloser(): string
    {
        return json_encode([
            'reply' => 'بحترم اختيارك',
            'stance' => DimaPrompt::UNITED,
            'note' => 'ديمقراطيين',
            'close' => true,
            'memory' => [
                'known' => '',
                'topic' => '',
                'answered' => '',
                'open_question' => '',
                'concern' => '',
                'wants_to_continue' => false,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
