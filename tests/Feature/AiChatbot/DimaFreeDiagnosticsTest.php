<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Services\AiChatbot\DimaFacts;
use App\Services\AiChatbot\DimaKnowledge;
use App\Services\AiChatbot\DimaPrompt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage A. Every case uses a mock or a pure function.
 * A passing result does not mean a live model would answer well.
 */
class DimaFreeDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        DimaPrompt::$testResponder = null;
    }

    protected function tearDown(): void
    {
        DimaPrompt::$testResponder = null;
        parent::tearDown();
    }

    #[DataProvider('decisionCases')]
    public function test_mocked_turn_follows_state_and_fallback_rules(
        array $turns,
        string $raw,
        string $name,
        array $prior,
        array $expect,
    ): void {
        $calls = 0;
        DimaPrompt::$testResponder = function (array $messages) use ($raw, &$calls): string {
            $calls++;
            if ($calls === 1) {
                $this->assertSame(DimaPrompt::instructions(), $messages[0]['content'] ?? null);
                $this->assertSame('system', $messages[0]['role'] ?? null);
            }

            return $raw === 'EMPTY' ? '' : $raw;
        };

        $decision = DimaPrompt::nextReply($turns, $name, $prior);

        if (isset($expect['exact'])) {
            $this->assertSame($expect['exact'], $decision['text']);
        }
        foreach ($expect['contains'] ?? [] as $needle) {
            $this->assertStringContainsString($needle, $decision['text']);
        }
        foreach ($expect['absent'] ?? [] as $needle) {
            $this->assertStringNotContainsString($needle, $decision['text']);
        }
        if (array_key_exists('stance', $expect)) {
            $this->assertSame($expect['stance'], $decision['stance']);
        }
        if (array_key_exists('close', $expect)) {
            $this->assertSame($expect['close'], $decision['close']);
        }
        if (array_key_exists('video', $expect)) {
            $this->assertSame($expect['video'], $decision['video']);
        }
        if (array_key_exists('stage', $expect)) {
            $this->assertSame($expect['stage'], $decision['stage']);
        }
        if (isset($expect['max'])) {
            $this->assertLessThanOrEqual($expect['max'], mb_strlen($decision['text']));
        }
        if (isset($expect['calls'])) {
            $this->assertSame($expect['calls'], $calls);
        }
        if (isset($expect['memory'])) {
            foreach ($expect['memory'] as $key => $value) {
                $this->assertSame($value, $decision['memory'][$key]);
            }
        }
        if (isset($expect['note_max'])) {
            $this->assertLessThanOrEqual($expect['note_max'], mb_strlen($decision['note']));
            $this->assertNotSame('', $decision['note']);
        }
        if (isset($expect['identity'])) {
            foreach ($expect['identity'] as $key => $value) {
                $this->assertSame($value, $decision['identity'][$key]);
            }
        }
        $this->assertArrayHasKey('identity', $decision);
        $this->assertIsBool($decision['video']);
        $this->assertIsBool($decision['memory']['wants_to_continue']);
        Http::assertNothingSent();
    }

    public static function decisionCases(): array
    {
        $cases = [];

        $political = [
            ['شو إنجازات الموحدة؟', ['30 مليار', 'مش مصروف'], ['انصرفت كلها']],
            ['شو الفرق بينها وبين المشتركة؟', ['برا'], ['أحسن حزب']],
            ['ليش انفصلوا؟', ['2021', 'مقاعد'], []],
            ['قديش من الميزانية وصل فعليا؟', ['13', '10'], ['انصرفت كلها']],
            ['شو عملوا لمكافحة الجريمة؟', ['549'], ['30 مليار']],
            ['يعني كل المصاري انصرفت؟', ['لا', 'مش مصروف'], ['انصرفت كلها']],
            ['مين كان السبب بالقرار؟', ['حكومة', '2021'], []],
            ['شو عملوا مع بعض؟', ['القائمة المشتركة', '15 مقعد'], ['30 مليار']],
            ['شو الانتقادات الموجهة للموحدة؟', ['13', 'مش مصروف'], []],
            ['ليش أصلا مهم التصويت؟', ['التصويت'], ['؟']],
            ['طب وين إنجازاتهم؟', ['2022'], []],
            ['حسب رأيك شو بختلفوا؟', ['ائتلاف'], []],
            ['وليش انفصلوا عن المشتركة؟', ['2021'], []],
            ['شو طلعوا مع بعض؟', ['2015'], ['أحسن']],
            ['قديش وصل فعلي؟', ['تخصيص'], []],
            ['كل المصاري وصلت؟', ['لا'], []],
            ['شو عملوا للعنف؟', ['منفصلة'], []],
            ['الانتقاد على التنفيذ؟', ['10'], []],
        ];
        foreach ($political as [$line, $contains, $absent]) {
            $cases['null model answers '.$line] = self::row(
                self::afterVote($line),
                'EMPTY',
                ['contains' => $contains, 'absent' => $absent, 'close' => false, 'video' => false, 'max' => 320, 'calls' => 1],
            );
        }

        $burst = "شو رأيك انت؟\nطب وين إنجازاتهم الموحدة؟\nحسب رأيك شو بختلفوا عن المشتركة؟\nوليش منفصلين؟\nشو طلعوا مع بعض؟";
        $cases['null model keeps the latest burst line'] = self::row(
            self::afterVote($burst),
            'EMPTY',
            ['contains' => ['القائمة المشتركة', '15 مقعد'], 'absent' => ['30 مليار', 'أحسن'], 'max' => 320, 'close' => false],
        );

        $cases['null model on consent asks the vote question once'] = self::row(
            self::introThen('تفضل'),
            'EMPTY',
            ['exact' => 'ناوي تصوت بالانتخابات الجاي؟', 'stage' => DimaPrompt::STAGE_VOTING_INTENTION_ASKED, 'close' => false, 'video' => false, 'calls' => 0, 'identity' => ['consented' => true]],
        );
        $cases['null model on female consent uses the feminine question'] = self::row(
            self::introThen('احكي'),
            'EMPTY',
            ['exact' => 'ناوية تصوتي بالانتخابات الجاي؟', 'stage' => DimaPrompt::STAGE_VOTING_INTENTION_ASKED],
            'فاطمة',
        );
        foreach (['اه', 'نعم', 'اسأل', 'أكيد', 'شو السؤال'] as $consent) {
            $cases['consent word '.$consent] = self::row(
                self::introThen($consent),
                'EMPTY',
                ['exact' => 'ناوي تصوت بالانتخابات الجاي؟', 'stage' => DimaPrompt::STAGE_VOTING_INTENTION_ASKED, 'calls' => 0],
            );
        }

        $cases['null model on a flat no keeps the video'] = self::row(
            self::afterVote('لا'),
            'EMPTY',
            ['contains' => ['فيديو', '1000'], 'video' => true, 'close' => false, 'calls' => 1],
        );
        $cases['null model on خلي علي closes'] = self::row(
            self::afterVote('خلي علي'),
            'EMPTY',
            ['exact' => 'تمام، ما برجعلش رسالة.', 'close' => true, 'stance' => DimaPrompt::REFUSED, 'video' => false, 'calls' => 0],
        );
        $cases['منفصلين gets the split fallback'] = self::row(
            self::afterVote('وليش منفصلين؟'),
            'EMPTY',
            ['contains' => ['2021', 'مقاعد'], 'close' => false, 'video' => false, 'max' => 320, 'calls' => 1],
        );
        $cases['a vague answer to منفصلين is replaced'] = self::row(
            self::afterVote('وليش منفصلين؟'),
            self::payload('كل حزب وإلو ظروف.'),
            ['contains' => ['2021', 'مقاعد'], 'absent' => ['ظروف'], 'calls' => 2, 'max' => 320],
        );
        $cases['null model on a plain greeting uses the opening'] = self::row(
            [
                ['role' => 'assistant', 'message' => 'هلا أحمد، شو الاخبار'],
                ['role' => 'user', 'message' => 'هلا'],
            ],
            'EMPTY',
            ['contains' => ['مركز استطلاعات'], 'stage' => DimaPrompt::STAGE_INTRODUCTION, 'video' => false, 'close' => false, 'calls' => 1],
        );
        $cases['consent never calls the model'] = self::row(
            self::introThen('تفضل'),
            self::payload(str_repeat('خطبة طويلة. ', 30)),
            ['exact' => 'ناوي تصوت بالانتخابات الجاي؟', 'calls' => 0, 'video' => false, 'stage' => DimaPrompt::STAGE_VOTING_INTENTION_ASKED],
        );
        $cases['string false does not close a united reply'] = self::row(
            self::afterVote('الوضع هادي'),
            (string) json_encode([
                'reply' => 'لسا بنحكي.',
                'stance' => DimaPrompt::UNITED,
                'note' => '',
                'close' => 'false',
                'memory' => [
                    'known' => '',
                    'topic' => '',
                    'answered' => '',
                    'open_question' => '',
                    'concern' => '',
                    'wants_to_continue' => 'false',
                ],
            ], JSON_UNESCAPED_UNICODE),
            ['exact' => 'لسا بنحكي.', 'close' => false, 'stance' => DimaPrompt::UNITED, 'memory' => ['wants_to_continue' => false], 'calls' => 1],
        );
        $cases['null model on a second stop still closes'] = self::row(
            self::brushTwice(),
            'EMPTY',
            ['exact' => 'تمام، ما برجعلش رسالة.', 'close' => true, 'stance' => DimaPrompt::REFUSED, 'video' => false],
        );
        $cases['null model on a religious objection stays short'] = self::row(
            self::afterVote('مش رح أصوت، حرام شرعا'),
            'EMPTY',
            ['contains' => ['مش شيخة'], 'absent' => ['1000'], 'stance' => DimaPrompt::RELIGIOUS, 'close' => false, 'video' => false],
        );

        $long = str_repeat('الموحدة أحسن حزب وأنجزت كل إشي. ', 12);
        $cases['long comparison is replaced and stays on the last question'] = self::row(
            self::afterVote($burst),
            self::payload($long),
            ['contains' => ['القائمة المشتركة'], 'absent' => ['أحسن حزب', '30 مليار'], 'max' => 320, 'calls' => 2],
        );
        $cases['claim that the thirty billion was spent is replaced'] = self::row(
            self::afterVote('يعني كل المصاري انصرفت؟'),
            self::payload('اي انصرفت كلها ووصلت للبلد.'),
            ['contains' => ['13', 'مش مصروف'], 'absent' => ['انصرفت كلها'], 'calls' => 2],
        );
        $cases['generic likud question is replaced by the record'] = self::row(
            self::afterVote('بصوت ليكود'),
            self::payload('شو السبب اللي خلاك تختار هالحزب؟'),
            ['contains' => ['القومية', 'كامينتس'], 'absent' => ['شو السبب'], 'close' => false],
        );
        $cases['religious sermon is shortened'] = self::row(
            self::afterVote('مش رح أصوت، حرام شرعا'),
            self::payload('الضرورات بتبيح وهاد نحو 1000 قتيل، ناوي تصوت؟'),
            ['contains' => ['مش شيخة'], 'absent' => ['1000', 'ناوي تصوت'], 'stance' => DimaPrompt::RELIGIOUS, 'close' => false, 'video' => false],
        );
        $cases['flat no with a video-blind reply is tied back to the video'] = self::row(
            self::afterVote('لأ'),
            self::payload('تمام بحترم قرارك.'),
            ['contains' => ['فيديو', '1000'], 'video' => true, 'close' => false],
        );
        $cases['a reason beside the refusal does not take the video'] = self::row(
            self::afterVote("لأ\nما في إنجازات"),
            'EMPTY',
            ['contains' => ['30 مليار'], 'video' => false],
        );
        $cases['first خلي علي closes without a vote question'] = self::row(
            self::afterVote('خلي علي'),
            self::payload('ثانية بس. بتطلع تصوت؟'),
            ['exact' => 'تمام، ما برجعلش رسالة.', 'close' => true, 'stance' => DimaPrompt::REFUSED, 'video' => false, 'calls' => 0],
        );
        $cases['second stop closes'] = self::row(
            self::brushTwice(),
            self::payload('خلاص بفهمك.'),
            ['close' => true, 'stance' => DimaPrompt::REFUSED, 'video' => false],
        );
        $cases['لا تبعت closes the first time'] = self::row(
            self::afterVote('لا تبعتلي'),
            self::payload('سؤال عن التصويت وفيديو وهدم'),
            ['exact' => 'تمام، ما برجعلش رسالة.', 'close' => true, 'stance' => DimaPrompt::REFUSED, 'video' => false],
        );
        $cases['united conviction ends on the fixed goodbye'] = self::row(
            self::afterVote('بروح مع الموحدة عن قناعة'),
            self::payload('يعطيك العافية', DimaPrompt::UNITED, true),
            ['exact' => 'تمام شكرا الك كتير على وقتك، نهارك سعيد 🌷', 'close' => true, 'stance' => DimaPrompt::UNITED, 'stage' => DimaPrompt::STAGE_CLOSED],
        );
        $cases['female united goodbye uses الكي'] = self::row(
            self::afterVote('مع الموحدة'),
            self::payload('يسلمو', DimaPrompt::UNITED, true),
            ['exact' => 'تمام شكرا الكي كتير على وقتك، نهارك سعيد 🌷', 'stance' => DimaPrompt::UNITED, 'close' => true],
            'فاطمة',
        );
        $cases['joint stance cannot close'] = self::row(
            self::afterVote('بصوت للمشتركة'),
            self::payload('المشتركة وصلت 15 مقعد وهي برا.', DimaPrompt::JOINT, true),
            ['close' => false, 'stance' => DimaPrompt::JOINT, 'contains' => ['15 مقعد']],
        );
        $cases['unknown stance becomes open'] = self::row(
            self::afterVote('الوضع هادي'),
            self::payload('الوضع هادي اليوم.', 'not-a-stance'),
            ['stance' => DimaPrompt::OPEN, 'exact' => 'الوضع هادي اليوم.', 'close' => false],
        );
        $cases['fenced json is accepted'] = self::row(
            self::afterVote('الوضع هادي'),
            "```json\n".self::payload('ماشي الحال.')."\n```",
            ['exact' => 'ماشي الحال.', 'calls' => 1],
        );
        $cases['broken json on a political question uses the fallback'] = self::row(
            self::afterVote('شو إنجازات الموحدة؟'),
            '{not json',
            ['contains' => ['30 مليار'], 'calls' => 1],
        );
        $cases['diacritics and quotes are stripped'] = self::row(
            self::afterVote('الوضع هادي'),
            self::payload('«تمامْ» الوضع هادي.'),
            ['exact' => 'تمام الوضع هادي.', 'calls' => 1],
        );
        $cases['مايل is rewritten to the vote-list question'] = self::row(
            self::afterVote('الوضع هادي'),
            self::payload('مايل للموحدة؟'),
            ['exact' => 'لمين رح تصوت المره هاي؟', 'absent' => ['مايل']],
        );
        $cases['unsolicited bot wording is removed'] = self::row(
            self::afterVote('الوضع هادي'),
            self::payload('أنا بوت. الوضع هادي.'),
            ['absent' => ['بوت'], 'contains' => ['الوضع هادي']],
        );
        $cases['a direct bot question may keep the word'] = self::row(
            self::afterVote('انت بوت؟'),
            self::payload('اي، أنا من مركز اتصالات القائمة العربية الموحدة، والرد آلي مش بوت بشر.'),
            ['contains' => ['بوت', 'آلي', 'مركز اتصالات'], 'absent' => ['ناوي تصوت'], 'calls' => 1],
        );
        $cases['identity answer does not invent an address'] = self::row(
            self::afterVote('مين انت ومن أي جهة؟'),
            self::payload('عنا مكتب بشارع الرئيسي ورقم من فيسبوك.'),
            ['contains' => ['مركز اتصالات'], 'absent' => ['شارع', 'فيسبوك']],
        );
        $cases['short comparison is kept'] = self::row(
            self::afterVote('شو الفرق بينها وبين المشتركة؟'),
            self::payload('المشتركة ضلت برا، والموحدة دخلت الائتلاف. الخلاف كان ترتيب مقاعد.'),
            ['exact' => 'المشتركة ضلت برا، والموحدة دخلت الائتلاف. الخلاف كان ترتيب مقاعد.', 'calls' => 1, 'max' => 320],
        );
        $cases['budget figures from the model are kept'] = self::row(
            self::afterVote('احكيلي عن الميزانية'),
            self::payload('قرار 550 نحو 30 مليار، والسنة الاولى نحو 6، و60 بالمية من مبلغ هاي السنة. قرار 549 نحو 2.5.'),
            ['contains' => ['30', '60', '549'], 'calls' => 1],
        );
        $cases['note is capped at 280 characters'] = self::row(
            self::afterVote('الوضع هادي'),
            self::payload('الوضع هادي.', DimaPrompt::OPEN, false, str_repeat('ن', 400)),
            ['note_max' => 280, 'exact' => 'الوضع هادي.'],
        );
        $cases['omitted memory keeps the prior memory'] = self::row(
            self::afterVote('الوضع هادي'),
            (string) json_encode([
                'reply' => 'الوضع هادي.',
                'stance' => DimaPrompt::OPEN,
                'note' => '',
                'close' => false,
            ], JSON_UNESCAPED_UNICODE),
            ['memory' => ['known' => 'ناوي يصوت', 'topic' => 'تصويت', 'wants_to_continue' => true], 'calls' => 1],
            'أحمد',
            ['stance' => DimaPrompt::OPEN, 'memory' => [
                'known' => 'ناوي يصوت',
                'topic' => 'تصويت',
                'answered' => '',
                'open_question' => '',
                'concern' => '',
                'wants_to_continue' => true,
            ]],
        );
        $cases['model memory replaces the prior known field'] = self::row(
            self::afterVote('بصوت للمشتركة'),
            self::payload('المشتركة وصلت 15 مقعد وهي برا.', DimaPrompt::JOINT, false, '', [
                'known' => 'بصوت للمشتركة',
                'topic' => 'المشتركة',
                'answered' => 'القائمة',
                'open_question' => '',
                'concern' => '',
                'wants_to_continue' => true,
            ]),
            ['stance' => DimaPrompt::JOINT, 'close' => false, 'memory' => ['known' => 'بصوت للمشتركة', 'topic' => 'المشتركة']],
        );
        $cases['answer after the vote question is response_received'] = self::row(
            self::afterVote('الوضع هادي'),
            self::payload('الوضع هادي اليوم.'),
            ['stage' => DimaPrompt::STAGE_RESPONSE_RECEIVED, 'close' => false],
        );
        $cases['a later user line is follow_up'] = self::row(
            self::followUp('شو أخبارك'),
            self::payload('لسا بنحكي.'),
            ['stage' => DimaPrompt::STAGE_FOLLOW_UP],
        );
        $cases['an invented date on the steering question is replaced'] = self::row(
            self::afterVote('بدك اياي اصوت للموحدة؟'),
            self::payload('هاد انحسم بتموز.'),
            ['contains' => ['مصلحة شعبي'], 'close' => false, 'absent' => ['تموز', 'موظف']],
        );
        $cases['a vague dodge on the steering question is kept'] = self::row(
            self::afterVote('بدك اياي اصوت للموحدة؟'),
            self::payload('ما بعرف.'),
            ['exact' => 'ما بعرف.', 'close' => false, 'calls' => 1],
        );
        $cases['demolition question is not a flat refusal'] = self::row(
            self::afterVote('والهدم شو عملوا؟'),
            self::payload('حكي عام.'),
            ['contains' => ['هدم'], 'video' => false],
        );
        $cases['abbass criticism stays on the written record'] = self::row(
            self::afterVote('منصور عباس ما عمل اشي'),
            self::payload('شو السبب؟'),
            ['contains' => ['منصور عباس'], 'absent' => ['شو السبب']],
        );
        $cases['922 is not given an invented amount'] = self::row(
            self::afterVote('شو قرار 922 قبل الموحدة؟'),
            'EMPTY',
            ['contains' => ['2016'], 'absent' => ['مليار'], 'video' => false, 'max' => 320],
        );
        $cases['a stored false identity flag stays false'] = self::row(
            self::afterVote('الوضع هادي'),
            self::payload('الوضع هادي.'),
            ['exact' => 'الوضع هادي.', 'identity' => ['stopped' => false, 'consented' => true], 'calls' => 1],
            'أحمد',
            ['identity' => ['stopped' => 'false', 'consented' => 'true']],
        );

        return $cases;
    }

    #[DataProvider('packetCases')]
    public function test_packet_routes_the_turn_without_a_second_model_call(string $user, string $hint, string $name = 'أحمد'): void
    {
        $seen = '';
        DimaPrompt::$testResponder = function (array $messages) use (&$seen): string {
            if ($seen === '') {
                $seen = (string) ($messages[1]['content'] ?? '');
                $this->assertSame(DimaPrompt::instructions(), $messages[0]['content'] ?? null);
            }

            return self::payload('تمام.');
        };

        DimaPrompt::nextReply(self::routedTurns($user), $name);
        $this->assertStringContainsString($hint, $seen);
        Http::assertNothingSent();
    }

    public static function packetCases(): array
    {
        return [
            'religious' => ['حرام شرعا', 'السبب ديني'],
            'spent' => ['قديش من الميزانية وصل فعليا؟', 'سال إذا المصاري وصلت'],
            'record' => ['شو عملوا العرب؟', 'سال شو انعمل'],
            'budget' => ['احكيلي عن الميزانية', 'سال عن الميزانية'],
            'likud' => ['بصوت ليكود', 'سمى الليكود'],
            'abbas' => ['منصور عباس', 'بحكي عن منصور عباس'],
            'who' => ['مين انت؟', 'سأل عن الهوية'],
            'opening' => ['هلا', 'اول رد بعد التحية'],
            'steer' => ['بدك اياي اصوت للموحدة', 'سأل اذا بدك يصوت'],
            'crime' => ['الجريمة زادت', 'ردي على كلامه من الذاكرة'],
            'split' => ['ليش انفصلوا؟', 'ردي على كلامه من الذاكرة'],
            'why vote' => ['ليش أصلا مهم التصويت؟', 'ردي على كلامه من الذاكرة'],
            'hebrew likud' => ['הליכוד', 'سمى الليكود'],
            'democrats' => ['ديموقراطيين', 'الموضوع الديمقراطيون بإسرائيل'],
        ];
    }

    #[DataProvider('memoryCases')]
    public function test_memory_shape(mixed $raw, ?array $fallback, array $expect): void
    {
        $memory = DimaPrompt::memoryFrom($raw, $fallback);
        foreach ($expect as $key => $value) {
            $this->assertSame($value, $memory[$key]);
        }
        $this->assertLessThanOrEqual(240, mb_strlen($memory['known']));
        Http::assertNothingSent();
    }

    public static function memoryCases(): array
    {
        $blank = [
            'known' => '',
            'topic' => '',
            'answered' => '',
            'open_question' => '',
            'concern' => '',
            'wants_to_continue' => true,
        ];

        return [
            'null uses defaults' => [null, null, $blank],
            'string is ignored' => ['ناوي', null, $blank],
            'empty array keeps defaults' => [[], null, $blank],
            'known is copied' => [['known' => ' بصوت '], null, ['known' => 'بصوت']],
            'topic is copied' => [['topic' => 'ميزانية'], null, ['topic' => 'ميزانية']],
            'answered is copied' => [['answered' => 'القائمة'], null, ['answered' => 'القائمة']],
            'open question is copied' => [['open_question' => 'ليش'], null, ['open_question' => 'ليش']],
            'concern is copied' => [['concern' => 'حرام'], null, ['concern' => 'حرام']],
            'continue false' => [['wants_to_continue' => false], null, ['wants_to_continue' => false]],
            'continue true' => [['wants_to_continue' => true], null, ['wants_to_continue' => true]],
            'missing continue stays true' => [['known' => 'ا'], null, ['wants_to_continue' => true]],
            'fallback fills gaps' => [null, ['known' => 'قديم', 'topic' => 'ت', 'answered' => '', 'open_question' => '', 'concern' => '', 'wants_to_continue' => false], ['known' => 'قديم', 'wants_to_continue' => false]],
            'new known overrides fallback' => [['known' => 'جديد'], ['known' => 'قديم', 'topic' => '', 'answered' => '', 'open_question' => '', 'concern' => '', 'wants_to_continue' => true], ['known' => 'جديد']],
            'non string known is ignored' => [['known' => 12], null, ['known' => '']],
            'long known is cut' => [['known' => str_repeat('ا', 300)], null, ['known' => str_repeat('ا', 240)]],
            'string false stays false' => [['wants_to_continue' => 'false'], null, ['wants_to_continue' => false]],
            'string FALSE stays false' => [['wants_to_continue' => 'FALSE'], null, ['wants_to_continue' => false]],
            'string true stays true' => [['wants_to_continue' => 'true'], null, ['wants_to_continue' => true]],
            'string zero stays false' => [['wants_to_continue' => '0'], null, ['wants_to_continue' => false]],
            'bool false stays false' => [['wants_to_continue' => false], null, ['wants_to_continue' => false]],
        ];
    }

    #[DataProvider('stanceCases')]
    public function test_stance_normalization(string $raw, string $expect): void
    {
        $this->assertSame($expect, DimaPrompt::normalizeStance($raw));
    }

    public static function stanceCases(): array
    {
        $cases = [];
        foreach (DimaPrompt::STANCES as $stance) {
            $cases[$stance] = [$stance, $stance];
        }
        $cases['empty'] = ['', DimaPrompt::OPEN];
        $cases['typo'] = ['United', DimaPrompt::OPEN];
        $cases['refused spaced'] = [' refused', DimaPrompt::OPEN];

        return $cases;
    }

    #[DataProvider('factCases')]
    public function test_fact_retrieval_stays_sourced_and_short(string $thread, array $contains, array $absent): void
    {
        $guide = DimaFacts::forThread($thread);
        foreach ($contains as $needle) {
            $this->assertStringContainsString($needle, $guide);
        }
        foreach ($absent as $needle) {
            $this->assertStringNotContainsString($needle, $guide);
        }
        preg_match_all('/^\[/m', $guide, $factLines);
        $this->assertLessThanOrEqual(2, count($factLines[0]));
        Http::assertNothingSent();
    }

    public static function factCases(): array
    {
        return [
            'achievements' => ['شو إنجازات الموحدة؟', ['30 مليار', 'approved'], ['13 مليار']],
            'arrived' => ['قديش من الميزانية وصل فعليا؟', ['13 مليار', '10 مليار', 'allocated', 'لا تقولي إن الـ30 مليار انصرفت'], ['انصرفت كلها']],
            'all spent' => ['يعني كل المصاري انصرفت؟', ['لا تقولي إن الـ30 مليار انصرفت'], []],
            'crime' => ['شو عملوا لمكافحة الجريمة؟', ['549', 'منفصلة عن 550'], ['13 مليار']],
            'split' => ['ليش انفصلوا؟', ['2021', 'مقاعد'], ['30 مليار']],
            'split wording' => ['وليش منفصلين؟', ['2021'], ['30 مليار']],
            'difference' => ['شو الفرق بينها وبين المشتركة؟', ['لا تقولي حزب أحسن'], []],
            'together' => ['شو عملوا مع بعض؟', ['2015'], ['30 مليار']],
            'criticism' => ['شو الانتقادات؟', ['committed', '13 مليار'], []],
            'who decided' => ['مين كان السبب بالقرار؟', ['24 تشرين الأول 2021'], []],
            '922' => ['شو قرار 922 من 2016؟', ['2016', 'لا تخترعي مبلغ'], ['30 مليار']],
            'at most two facts' => ['إنجازات 550 وانفصلوا والجريمة وقرار 922', ['30 مليار'], []],
            'empty thread' => ['الطقس حلو', [], ['30 مليار']],
        ];
    }

    public function test_stale_facts_are_marked_and_fresh_facts_are_not(): void
    {
        $this->assertTrue(DimaFacts::isStale('2020-01-01'));
        $this->assertFalse(DimaFacts::isStale('2026-10-19'));
        $this->assertTrue(DimaFacts::isStale('not-a-date'));
        config(['dima.facts_max_age_days' => 10]);
        $this->assertTrue(DimaFacts::isStale('2026-01-01'));
    }

    public function test_knowledge_does_not_merge_549_into_550_on_a_crime_question(): void
    {
        $guide = DimaKnowledge::retrieve('شو عملوا لمكافحة الجريمة؟');
        $this->assertStringContainsString('منفصلة عن 550', $guide);
        $this->assertStringContainsString('كراس', $guide);
        Http::assertNothingSent();
    }

    public function test_opening_is_only_a_greeting(): void
    {
        $male = DimaPrompt::openingFor('أحمد');
        $female = DimaPrompt::openingFor('فاطمة');
        $blank = DimaPrompt::openingFor('');
        $this->assertMatchesRegularExpression('/أحمد/u', $male);
        $this->assertMatchesRegularExpression('/^(يسعد اوقاتك اخ أحمد شو الاخبار|مرحبا أحمد، شو الاخبار|هلا أحمد، شو الاخبار|كيفك أحمد، شو الاخبار|أهلا أحمد، شو الاخبار)$/u', $male);
        $this->assertMatchesRegularExpression('/^(يسعد اوقاتك اخت فاطمة شو الاخبار|مرحبا فاطمة، شو الاخبار|هلا فاطمة، شو الاخبار|كيفك فاطمة، شو الاخبار|أهلا فاطمة، شو الاخبار)$/u', $female);
        $this->assertStringNotContainsString('مركز', $blank);
        $this->assertStringContainsString('شو الاخبار', $blank);
    }

    public function test_production_instructions_are_what_the_mock_receives(): void
    {
        $seen = '';
        DimaPrompt::$testResponder = function (array $messages) use (&$seen): string {
            $seen = (string) $messages[0]['content'];

            return self::payload('تمام.');
        };
        DimaPrompt::nextReply(self::afterVote('الوضع هادي'), 'أحمد');
        $this->assertSame(DimaPrompt::instructions(), $seen);
        $this->assertStringContainsString('جاوي على آخر سؤال أولا', $seen);
        $this->assertGreaterThan(2000, mb_strlen($seen));
        Http::assertNothingSent();
    }

    public function test_a_repair_makes_a_second_mock_call_and_no_third(): void
    {
        $calls = 0;
        DimaPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return self::payload(str_repeat('فقرة طويلة بلا جواب. ', 30));
        };
        DimaPrompt::nextReply(self::afterVote('شو طلعوا مع بعض؟'), 'أحمد');
        $this->assertSame(2, $calls);
        Http::assertNothingSent();
    }

    public function test_unit_tests_cannot_reach_the_network_when_the_mock_is_missing(): void
    {
        DimaPrompt::$testResponder = null;
        $decision = DimaPrompt::nextReply(self::afterVote('شو إنجازات الموحدة؟'), 'أحمد');
        $this->assertStringContainsString('30 مليار', $decision['text']);
        Http::assertNothingSent();
    }

    #[DataProvider('concernCases')]
    public function test_distrust_is_answered_without_another_vote_question(
        array $turns,
        string $raw,
        array $prior,
        array $expect,
    ): void {
        $calls = 0;
        DimaPrompt::$testResponder = function () use ($raw, &$calls): string {
            $calls++;

            return $raw === 'EMPTY' ? '' : $raw;
        };

        $decision = DimaPrompt::nextReply($turns, 'أحمد', $prior);

        foreach ($expect['contains'] ?? [] as $needle) {
            $this->assertStringContainsString($needle, $decision['text']);
        }
        foreach ($expect['absent'] ?? [] as $needle) {
            $this->assertStringNotContainsString($needle, $decision['text']);
        }
        if (isset($expect['exact'])) {
            $this->assertSame($expect['exact'], $decision['text']);
        }
        $this->assertSame($expect['close'], $decision['close']);
        $this->assertSame($expect['calls'], $calls);
        $this->assertFalse($decision['video']);
        if (isset($expect['concern'])) {
            $this->assertSame($expect['concern'], $decision['memory']['concern']);
        }
        if (array_key_exists('continue', $expect)) {
            $this->assertSame($expect['continue'], $decision['memory']['wants_to_continue']);
        }
        Http::assertNothingSent();
    }

    public static function concernCases(): array
    {
        $votePush = self::payload('التصويت مهم. بتطلع تصوت؟');
        $long = 'والله تعبت من الوعود. كل سنة بنسمع حكي وبنرجع لنفس المكان. أنا فقدت الثقة، وبهمني أعيش بكرامة وأشوف نتيجة على الأرض، مش شعار.';
        $good = self::payload('بدك نتيجة وتعيش بكرامة، وهاد من حقك. شو لازم يتغير عالأرض؟');
        $facts = self::payload('قرار 550 نحو 30 مليار، وهاد مش مصروف.');
        $housing = self::payload('أربعة مقاعد جوا حكومة التغيير وقفت الهدم، حسب كراس الكادر.');
        $prior = ['memory' => [
            'known' => 'خايب من الوعود',
            'topic' => 'ثقة',
            'answered' => '',
            'open_question' => '',
            'concern' => 'فقدان الثقة لأن الوعود ما بتبين كنتائج',
            'wants_to_continue' => true,
        ]];

        return [
            'long loss of trust' => [self::afterVote($long), $votePush, [], [
                'contains' => ['كرامة', 'عالأرض'],
                'absent' => ['بتطلع تصوت', 'التصويت مهم'],
                'close' => false,
                'calls' => 1,
                'concern' => 'فقدان الثقة لأن الوعود ما بتبين كنتائج، وبده يعيش بكرامة',
            ]],
            'good concern reply is kept' => [self::afterVote($long), $good, [], [
                'exact' => 'بدك نتيجة وتعيش بكرامة، وهاد من حقك. شو لازم يتغير عالأرض؟',
                'close' => false,
                'calls' => 1,
            ]],
            'promises without results' => [self::afterVote('كلهم بوعدوا وما بعملوا'), $votePush, [], [
                'contains' => ['وعد', 'نتيجة'],
                'absent' => ['بتطلع تصوت'],
                'close' => false,
                'calls' => 1,
                'concern' => 'فقدان الثقة لأن الوعود ما بتبين كنتائج',
            ]],
            'dignity' => [self::afterVote('بدي أعيش بكرامة'), $votePush, [], [
                'contains' => ['بكرامة', 'من حقك'],
                'absent' => ['بتطلع تصوت'],
                'close' => false,
                'calls' => 1,
                'concern' => 'بدي أعيش بكرامة',
            ]],
            'no difference who wins' => [self::afterVote('ما بفرق مين بفوز'), $votePush, [], [
                'contains' => ['نتيجة'],
                'absent' => ['بتطلع تصوت'],
                'close' => false,
                'calls' => 1,
                'concern' => 'ما بفرق مين بفوز إذا ما في نتيجة ملموسة',
            ]],
            'asks for evidence' => [self::afterVote('شو الدليل على الإنجازات؟'), $facts, [], [
                'exact' => 'قرار 550 نحو 30 مليار، وهاد مش مصروف.',
                'absent' => ['بتطلع تصوت'],
                'close' => false,
                'calls' => 1,
            ]],
            'changes topic' => [self::afterVote('ليش الهدم رجع؟'), $housing, $prior, [
                'exact' => 'أربعة مقاعد جوا حكومة التغيير وقفت الهدم، حسب كراس الكادر.',
                'close' => false,
                'calls' => 1,
                'concern' => 'فقدان الثقة لأن الوعود ما بتبين كنتائج',
            ]],
            'repeats the concern' => [self::afterVote('كلهم بوعدوا وما بعملوا'), $votePush, $prior, [
                'contains' => ['وعد'],
                'absent' => ['بتطلع تصوت'],
                'close' => false,
                'calls' => 1,
                'concern' => 'فقدان الثقة لأن الوعود ما بتبين كنتائج',
            ]],
            'will not vote but asks' => [self::afterVote("مش ناوي أصوت\nشو الإنجازات؟"), $facts, [], [
                'contains' => ['30 مليار'],
                'absent' => ['بتطلع تصوت'],
                'close' => false,
                'calls' => 1,
            ]],
            'stop sending' => [self::afterVote('خلص لا تبعثولي'), $votePush, $prior, [
                'exact' => 'تمام، ما برجعلش رسالة.',
                'absent' => ['تصوت'],
                'close' => true,
                'calls' => 0,
                'continue' => false,
                'concern' => 'فقدان الثقة لأن الوعود ما بتبين كنتائج',
            ]],
            'several objections at once' => [self::afterVote("كلهم بوعدوا وما بعملوا\nبدي أعيش بكرامة\nما بفرق مين بفوز"), $votePush, [], [
                'contains' => ['كرامة', 'وعود'],
                'absent' => ['بتطلع تصوت', 'موحدة'],
                'close' => false,
                'calls' => 1,
            ]],
            'vote line that still answers the concern is kept' => [self::afterVote($long), self::payload('في فرق بين وعد ونتيجة، وهاد من حقك. بتطلع تصوت؟'), [], [
                'exact' => 'في فرق بين وعد ونتيجة، وهاد من حقك. بتطلع تصوت؟',
                'close' => false,
                'calls' => 1,
            ]],
            'vote line on an unrelated message is kept' => [self::afterVote('الطقس منيح اليوم'), self::payload('منيح. بتطلع تصوت؟'), [], [
                'exact' => 'منيح. بتطلع تصوت؟',
                'close' => false,
                'calls' => 1,
            ]],
            'factual answer after distrust is kept' => [self::afterVote("فقدت الثقة بالوعود\nشو إنجازات الموحدة؟"), $facts, [], [
                'exact' => 'قرار 550 نحو 30 مليار، وهاد مش مصروف.',
                'absent' => ['بتطلع تصوت', 'بدك تعيش بكرامة'],
                'close' => false,
                'calls' => 1,
                'concern' => 'فقدان الثقة لأن الوعود ما بتبين كنتائج',
            ]],
            'record plus a vote question is not swapped for dignity' => [self::afterVote($long), self::payload('قرار 550 نحو 30 مليار، وهاد مش مصروف. بتطلع تصوت؟'), [], [
                'exact' => 'قرار 550 نحو 30 مليار، وهاد مش مصروف. بتطلع تصوت؟',
                'close' => false,
                'calls' => 1,
            ]],
            'leave me alone' => [self::afterVote('خلي علي'), $votePush, $prior, [
                'exact' => 'تمام، ما برجعلش رسالة.',
                'close' => true,
                'calls' => 0,
                'continue' => false,
                'concern' => 'فقدان الثقة لأن الوعود ما بتبين كنتائج',
            ]],
            'do not message me' => [self::afterVote('لا تبعت'), $votePush, [], [
                'exact' => 'تمام، ما برجعلش رسالة.',
                'close' => true,
                'calls' => 0,
                'continue' => false,
            ]],
        ];
    }

    public function test_concern_memory_persists_and_a_repeated_fallback_drops_the_question(): void
    {
        $calls = 0;
        $votePush = self::payload('التصويت مهم. بتطلع تصوت؟');
        $housing = self::payload('أربعة مقاعد جوا حكومة التغيير وقفت الهدم، حسب كراس الكادر.');
        $blankConcern = self::payload('تمام، بنضل على الموضوع.', DimaPrompt::OPEN, false, '', [
            'known' => 'خايب من الوعود',
            'topic' => 'ثقة',
            'answered' => '',
            'open_question' => '',
            'concern' => '',
            'wants_to_continue' => true,
        ]);
        $script = [$votePush, $housing, $blankConcern, $votePush];
        DimaPrompt::$testResponder = function () use (&$calls, $script, $votePush): string {
            $raw = $script[$calls] ?? $votePush;
            $calls++;

            return $raw;
        };

        $firstTurns = self::afterVote('كلهم بوعدوا وما بعملوا');
        $first = DimaPrompt::nextReply($firstTurns, 'أحمد');
        $this->assertSame(1, $calls);
        $this->assertSame(1, mb_substr_count($first['text'], '؟'));
        $this->assertStringNotContainsString('بتطلع تصوت', $first['text']);
        $this->assertSame('فقدان الثقة لأن الوعود ما بتبين كنتائج', $first['memory']['concern']);

        $secondTurns = array_merge($firstTurns, [
            ['role' => 'assistant', 'message' => $first['text']],
            ['role' => 'user', 'message' => 'ليش الهدم رجع؟'],
        ]);
        $second = DimaPrompt::nextReply($secondTurns, 'أحمد', ['memory' => $first['memory']]);
        $this->assertSame(2, $calls);
        $this->assertSame('أربعة مقاعد جوا حكومة التغيير وقفت الهدم، حسب كراس الكادر.', $second['text']);
        $this->assertSame($first['memory']['concern'], $second['memory']['concern']);

        $thirdTurns = array_merge($secondTurns, [
            ['role' => 'assistant', 'message' => $second['text']],
            ['role' => 'user', 'message' => 'رجعت أفكر بنفس الموضوع'],
        ]);
        $third = DimaPrompt::nextReply($thirdTurns, 'أحمد', ['memory' => $second['memory']]);
        $this->assertSame(3, $calls);
        $this->assertSame('تمام، بنضل على الموضوع.', $third['text']);
        $this->assertSame($first['memory']['concern'], $third['memory']['concern']);
        $this->assertTrue($third['memory']['wants_to_continue']);

        $again = array_merge($firstTurns, [
            ['role' => 'assistant', 'message' => $first['text']],
            ['role' => 'user', 'message' => 'كلهم بوعدوا وما بعملوا'],
        ]);
        $repeat = DimaPrompt::nextReply($again, 'أحمد', ['memory' => $first['memory']]);
        $this->assertSame(4, $calls);
        $this->assertSame(0, mb_substr_count($repeat['text'], '؟'));
        $this->assertStringNotContainsString('شو النتيجة اللي ناقصتك تشوفها', $repeat['text']);
        $this->assertStringContainsString('نتيجة', $repeat['text']);
        $this->assertSame($first['memory']['concern'], $repeat['memory']['concern']);
        Http::assertNothingSent();
    }

    public function test_a_vote_only_reply_to_a_factual_question_keeps_the_record(): void
    {
        $calls = 0;
        DimaPrompt::$testResponder = function () use (&$calls): string {
            $calls++;

            return self::payload('بتطلع تصوت؟');
        };

        $decision = DimaPrompt::nextReply(self::afterVote("فقدت الثقة بالوعود\nشو إنجازات الموحدة؟"), 'أحمد');

        $this->assertSame(2, $calls);
        $this->assertStringContainsString('30 مليار', $decision['text']);
        $this->assertStringNotContainsString('بدك تعيش بكرامة', $decision['text']);
        $this->assertStringNotContainsString('بتطلع تصوت', $decision['text']);
        Http::assertNothingSent();
    }

    public function test_several_achievement_questions_keep_a_direct_answer(): void
    {
        $answer = 'قرار 550 نحو 30 مليار مش مصروف، والتخصيص نحو 13 مليار. قرار 549 خطة منفصلة، والخلاف كان ترتيب مقاعد سنة 2021.';
        $calls = 0;
        DimaPrompt::$testResponder = function () use (&$calls, $answer): string {
            $calls++;

            return self::payload($answer);
        };

        $burst = "شو إنجازات الموحدة؟\nقديش من الميزانية وصل فعليا؟\nقرار 549 منفصل ولا لا؟";
        $decision = DimaPrompt::nextReply(self::afterVote($burst), 'أحمد');

        $this->assertSame(1, $calls);
        $this->assertSame($answer, $decision['text']);
        Http::assertNothingSent();
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @param  array<string, mixed>  $expect
     * @param  array<string, mixed>  $prior
     * @return array{0:list,1:string,2:string,3:array,4:array}
     */
    private static function row(array $turns, string $raw, array $expect, string $name = 'أحمد', array $prior = []): array
    {
        return [$turns, $raw, $name, $prior, $expect];
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private static function payload(
        string $reply,
        string $stance = DimaPrompt::OPEN,
        bool $close = false,
        string $note = '',
        array $memory = [],
    ): string {
        return json_encode([
            'reply' => $reply,
            'stance' => $stance,
            'note' => $note,
            'close' => $close,
            'memory' => $memory === [] ? [
                'known' => '',
                'topic' => '',
                'answered' => '',
                'open_question' => '',
                'concern' => '',
                'wants_to_continue' => true,
            ] : $memory,
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return list<array{role:string,message:string}>
     */
    private static function afterVote(string $user): array
    {
        return [
            ['role' => 'assistant', 'message' => 'هلا أحمد، شو الاخبار'],
            ['role' => 'user', 'message' => 'هلا'],
            ['role' => 'assistant', 'message' => 'بعرف إنه تواصلي معك إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي. بتسمحلي أسألك سؤال صغير؟'],
            ['role' => 'user', 'message' => 'تفضل'],
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => $user],
        ];
    }

    /**
     * @return list<array{role:string,message:string}>
     */
    private static function introThen(string $user): array
    {
        return [
            ['role' => 'assistant', 'message' => 'هلا أحمد، شو الاخبار'],
            ['role' => 'user', 'message' => 'هلا'],
            ['role' => 'assistant', 'message' => 'أنا من مركز استطلاعات. بتسمحلي أسألك سؤال صغير؟'],
            ['role' => 'user', 'message' => $user],
        ];
    }

    /**
     * @return list<array{role:string,message:string}>
     */
    private static function followUp(string $user): array
    {
        return [
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'لسا بفكر'],
            ['role' => 'assistant', 'message' => 'تمام، خد وقتك.'],
            ['role' => 'user', 'message' => $user],
        ];
    }

    /**
     * @return list<array{role:string,message:string}>
     */
    private static function brushTwice(): array
    {
        return [
            ['role' => 'assistant', 'message' => 'ناوي تصوت بالانتخابات الجاي؟'],
            ['role' => 'user', 'message' => 'خلي علي'],
            ['role' => 'assistant', 'message' => 'ثانية بس. بتطلع تصوت؟'],
            ['role' => 'user', 'message' => 'خلي علي'],
        ];
    }

    /**
     * @return list<array{role:string,message:string}>
     */
    private static function routedTurns(string $user): array
    {
        if ($user === 'تفضل') {
            return self::introThen($user);
        }
        if ($user === 'هلا') {
            return [
                ['role' => 'assistant', 'message' => 'هلا أحمد، شو الاخبار'],
                ['role' => 'user', 'message' => 'هلا'],
            ];
        }

        return self::afterVote($user);
    }
}
