<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Services\AiChatbot\DimaFacts;
use App\Services\AiChatbot\DimaKnowledge;
use App\Services\AiChatbot\DimaPrompt;
use Tests\TestCase;

class DimaPoliticalKnowledgeTest extends TestCase
{
    protected function tearDown(): void
    {
        DimaPrompt::$testResponder = null;
        parent::tearDown();
    }

    public function test_fact_entries_keep_source_and_money_status(): void
    {
        $byId = [];
        foreach (DimaFacts::entries() as $entry) {
            $byId[$entry['id']] = $entry;
            $this->assertNotSame('', $entry['source_name']);
            $this->assertNotSame('', $entry['last_verified_at']);
            $this->assertContains($entry['status'], ['approved', 'allocated', 'committed', 'spent', 'implemented']);
        }

        $this->assertSame('approved', $byId['res-550-approved']['status']);
        $this->assertStringContainsString('2021-10-24', $byId['res-550-approved']['publication_date']);
        $this->assertStringContainsString('30 مليار', $byId['res-550-approved']['claim']);
        $this->assertSame('allocated', $byId['res-550-execution']['status']);
        $this->assertStringContainsString('13 مليار', $byId['res-550-execution']['claim']);
        $this->assertStringContainsString('10 مليار', $byId['res-550-execution']['claim']);
        $this->assertStringContainsString('knesset.gov.il', $byId['res-550-execution']['source_url']);
        $this->assertStringContainsString('منفصلة عن 550', $byId['res-549']['claim']);
        $this->assertStringContainsString('2016', $byId['res-922']['claim']);
        $this->assertFalse(DimaFacts::isStale('2026-10-19'));
        $this->assertTrue(DimaFacts::isStale('2020-01-01'));

        $guide = DimaKnowledge::retrieve('قديش من الميزانية وصل فعليا؟');
        $this->assertStringContainsString('13 مليار', $guide);
        $this->assertStringContainsString('لا تقولي إن الـ30 مليار انصرفت', $guide);
    }

    public function test_a_burst_of_comparison_questions_stays_on_the_latest_one(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => str_repeat('الموحدة أحسن حزب وأنجزت كل إشي بالهدم والجريمة والميزانية والهوية. ', 12),
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        $decision = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'شو رأيك انت؟'],
            ['role' => 'user', 'message' => 'طب وين إنجازاتهم الموحدة؟'],
            ['role' => 'user', 'message' => 'حسب رأيك شو بختلفوا عن المشتركة؟'],
            ['role' => 'user', 'message' => 'وليش منفصلين؟'],
            ['role' => 'user', 'message' => 'شو طلعوا مع بعض؟'],
        ], 'أحمد');

        $this->assertLessThan(320, mb_strlen($decision['text']));
        $this->assertStringContainsString('القائمة المشتركة', $decision['text']);
        $this->assertStringContainsString('15 مقعد', $decision['text']);
        $this->assertStringNotContainsString('30 مليار', $decision['text']);
        $this->assertStringNotContainsString('أحسن حزب', $decision['text']);
        $this->assertFalse(str_contains($decision['text'], '؟'));
    }

    public function test_a_short_comparison_is_kept(): void
    {
        $line = 'المشتركة ضلت برا، والموحدة دخلت الائتلاف. الخلاف كان ترتيب مقاعد.';
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => $line,
            'stance' => DimaPrompt::JOINT,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        $decision = DimaPrompt::nextReply([
            ['role' => 'assistant', 'message' => 'لمين رح تصوت المره هاي؟'],
            ['role' => 'user', 'message' => 'شو الفرق بينها وبين المشتركة؟'],
        ], 'أحمد');

        $this->assertSame($line, $decision['text']);
        $this->assertFalse($decision['close']);
    }

    public function test_budget_questions_do_not_treat_approval_as_spending(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'اي الثلاثين انصرفت ووصلت للناس.',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        $spent = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'يعني كل المصاري انصرفت؟'],
        ], 'أحمد');
        $arrived = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'قديش من الميزانية وصل فعلياً؟'],
        ], 'أحمد');
        $crime = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'شو عملوا لمكافحة الجريمة؟'],
        ], 'أحمد');

        $this->assertStringContainsString('مش مصروف', $spent['text']);
        $this->assertStringContainsString('13 مليار', $spent['text']);
        $this->assertStringNotContainsString('انصرفت ووصلت', $spent['text']);
        $this->assertStringContainsString('10 مليار', $arrived['text']);
        $this->assertStringContainsString('منفصلة عن 550', $crime['text']);
        $this->assertStringContainsString('2.5', $crime['text']);
    }

    public function test_split_criticism_and_voting_stay_specific(): void
    {
        DimaPrompt::$testResponder = fn (): string => json_encode([
            'reply' => 'الموضوع طويل ومعقد وفي عوامل كثيرة وما بقدر ألخصه بجملة، والناس بدها تفهم السياق كامل قبل ما تحكي.',
            'stance' => DimaPrompt::OPEN,
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);

        $split = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'ليش انفصلوا؟'],
        ], 'أحمد');
        $cause = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'مين كان السبب بالقرار؟'],
        ], 'أحمد');
        $criticism = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'شو الانتقادات الموجهة للموحدة؟'],
        ], 'أحمد');
        $vote = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'ليش أصلاً مهم التصويت؟'],
        ], 'أحمد');

        $this->assertStringContainsString('2021', $split['text']);
        $this->assertStringContainsString('ترتيب مقاعد', $split['text']);
        $this->assertStringContainsString('24 تشرين الأول 2021', $cause['text']);
        $this->assertStringContainsString('مش قرار شخص واحد', $cause['text']);
        $this->assertStringContainsString('13 مليار', $criticism['text']);
        $this->assertStringNotContainsString('غلطان', $criticism['text']);
        $this->assertStringContainsString('من غير ما يلزمك بقائمة', $vote['text']);
        $this->assertStringNotContainsString('لازم تصوت للموحدة', $vote['text']);

        DimaPrompt::$testResponder = fn (): string => '';
        $offline = DimaPrompt::nextReply([
            ['role' => 'user', 'message' => 'شو طلعوا مع بعض؟'],
        ], 'أحمد');
        $this->assertStringContainsString('القائمة المشتركة', $offline['text']);
        foreach ([$split, $cause, $criticism, $vote] as $decision) {
            $this->assertLessThan(320, mb_strlen($decision['text']));
            $this->assertFalse(str_ends_with($decision['text'], '؟'));
        }
    }
}
