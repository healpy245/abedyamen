<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * أرقام ووقائع سياسية بمصدر وتاريخ تحقق.
 * الكراس الحزبي يبقى في DimaKnowledge. هون بس اللي إله مصدر محدد.
 */
class DimaFacts
{
    /**
     * @return list<array{id:string,topic:string,needles:list<string>,claim:string,source_url:string,source_name:string,publication_date:string,last_verified_at:string,confidence:string,status:string}>
     */
    public static function entries(): array
    {
        return [
            [
                'id' => 'res-550-approved',
                'topic' => 'قرار 550 الموافقة',
                'needles' => ['550', 'إنجاز', 'انجاز', 'إنجازات', 'انجازات'],
                'claim' => 'قرار الحكومة 550 انوافق في 24 تشرين الأول 2021، خطة خمس سنين 2022–2026. مجموع البرنامج نحو 30 مليار شيكل. هاد مبلغ موافق عليه، مش مصروف، ومش كله موجه حرفيا للمجتمع العربي وبدو الشمال.',
                'source_url' => 'https://www.gov.il/he/pages/minority_development_authority_about?officeid=f60d1f2a-ddf1-45c0-84a2-1c1164d6dba9',
                'source_name' => 'سلطة التنمية الاقتصادية للأقليات، gov.il',
                'publication_date' => '2021-10-24',
                'last_verified_at' => '2026-10-19',
                'confidence' => 'high',
                'status' => 'approved',
            ],
            [
                'id' => 'res-550-execution',
                'topic' => 'قرار 550 التنفيذ',
                'needles' => ['وصل فعلي', 'انصرف', 'انصرفت', 'المصاري', 'قديش', 'تخصيص', 'التزام'],
                'claim' => 'تقرير مركز البحث والمعلومات في الكنيست، آذار 2026: حتى نهاية 2024 انخصص نحو 13 مليار شيكل، وانسجلت التزامات نحو 10 مليار. التخصيص غير الالتزام، وغير المصروف، وغير اللي انبنى. لا تقولي إن الـ30 مليار انصرفت.',
                'source_url' => 'https://main.knesset.gov.il/activity/info/research/pages/incident.aspx?businesstype=1&rid=8627',
                'source_name' => 'مركز البحث والمعلومات في الكنيست',
                'publication_date' => '2026-03-01',
                'last_verified_at' => '2026-10-19',
                'confidence' => 'high',
                'status' => 'allocated',
            ],
            [
                'id' => 'res-549',
                'topic' => 'قرار 549 الجريمة',
                'needles' => ['549', 'جريم', 'عنف', 'مكافحة'],
                'claim' => 'قرار 549 خطة ثانية للجريمة والعنف، منفصلة عن 550 وما ينجمع مبلغها معه. كراس الكادر بيحكي عن نحو 2.5 مليار كمبلغ للخطة. هاد مش رقم مصروف، وما في بهالملف رقم تنفيذ مستقل لهاي الخطة.',
                'source_url' => 'https://www.gov.il/he/pages/minority_development_authority_about?officeid=f60d1f2a-ddf1-45c0-84a2-1c1164d6dba9',
                'source_name' => 'كراس الكادر عن مبلغ الخطة، والتمييز عن 550 من ملف الوقائع',
                'publication_date' => '2021-10-24',
                'last_verified_at' => '2026-10-19',
                'confidence' => 'medium',
                'status' => 'approved',
            ],
            [
                'id' => 'res-922',
                'topic' => 'قرار 922',
                'needles' => ['922', 'قبل الموحدة', '2016'],
                'claim' => 'قرار 922 خطة اقتصادية أقدم للفترة 2016–2021، قبل ما تدخل الموحدة للائتلاف. وجودها يعني إن خطط التنمية ما بلشت مع مشاركة الموحدة. لا تخترعي مبلغ لهاي الخطة.',
                'source_url' => 'https://www.gov.il/he/pages/minority_development_authority_about?officeid=f60d1f2a-ddf1-45c0-84a2-1c1164d6dba9',
                'source_name' => 'سلطة التنمية الاقتصادية للأقليات، gov.il',
                'publication_date' => '2015-12-30',
                'last_verified_at' => '2026-10-19',
                'confidence' => 'high',
                'status' => 'approved',
            ],
            [
                'id' => 'joint-history',
                'topic' => 'المشتركة والانفصال',
                'needles' => ['انفصل', 'منفصلين', 'بختلف', 'الفرق', 'مع بعض', 'طلعوا', 'المشتركة'],
                'claim' => 'الموحدة كانت شريكة بالقائمة المشتركة من 2015، وبالانتخابات سنة 2021 خاضت وحدها. كراس الموحدة، وهو وثيقة حزبية، بيقول الخلاف كان ترتيب مقاعد مش هوية، وإبراهيم حجازي دخل لما جاء دوره. 15 مقعد وهم برا ما منعت قانون القومية ولا كامينتس. مع بعض يعني فترة المشتركة، مش إنجاز جوا الحكومة. لا تقولي حزب أحسن من حزب.',
                'source_url' => '',
                'source_name' => 'سجل انتخابي 2021، وتفصيل الخلاف من كراس الكادر',
                'publication_date' => '2021-03-23',
                'last_verified_at' => '2026-10-19',
                'confidence' => 'medium',
                'status' => 'implemented',
            ],
            [
                'id' => 'criticism',
                'topic' => 'انتقاد الموحدة',
                'needles' => ['انتقاد', 'انتقد', 'باع', 'فشل'],
                'claim' => 'الانتقاد الموثق على الميزانية: الموافقة نحو 30 مليار غير التخصيص نحو 13 مليار والالتزامات نحو 10 مليار حتى نهاية 2024. الكراس وثيقة حزبية، والنقد على فجوة التنفيذ محله. لا تنكري النقد ولا تخترعي اقتباس.',
                'source_url' => 'https://m.knesset.gov.il/activity/info/research/pages/incident.aspx?docid=mev-81774-m0h5w8',
                'source_name' => 'مركز البحث والمعلومات في الكنيست',
                'publication_date' => '2026-03-01',
                'last_verified_at' => '2026-10-19',
                'confidence' => 'high',
                'status' => 'committed',
            ],
            [
                'id' => 'institutions',
                'topic' => 'قرار حكومة وقانون',
                'needles' => ['السبب بالقرار', 'مين كان السبب', 'قرار حكوم', 'الكنيست', 'ائتلاف'],
                'claim' => 'قرار الحكومة قرار مجلس الوزراء، غير قانون الكنيست، وغير مصاري وصلت للبلد. قرار 550 قرار حكومة انوافق بـ24 تشرين الأول 2021 والموحدة كانت جوا الائتلاف. ما في شخص واحد ينلام لحاله بالملف.',
                'source_url' => 'https://www.gov.il/he/pages/minority_development_authority_about?officeid=f60d1f2a-ddf1-45c0-84a2-1c1164d6dba9',
                'source_name' => 'سلطة التنمية الاقتصادية للأقليات، gov.il',
                'publication_date' => '2021-10-24',
                'last_verified_at' => '2026-10-19',
                'confidence' => 'high',
                'status' => 'approved',
            ],
        ];
    }

    /**
     * @return list<array{id:string,topic:string,needles:list<string>,claim:string,source_url:string,source_name:string,publication_date:string,last_verified_at:string,confidence:string,status:string}>
     */
    public static function partyEntries(): array
    {
        return [
            [
                'id' => 'democrats-identity',
                'topic' => 'الديمقراطيون بإسرائيل',
                'needles' => ['ديموقراطيين', 'ديموقراطيون', 'ديمقراطيين', 'ديمقراطيون', 'הדמוקרטים', 'דמוקרטים', 'العمل وميرتس', 'העבודה ומרצ'],
                'claim' => 'הדמוקרטים قائمة تأسست سنة 2024 من دمج جزئي بين العمل وميرتس. يائير غولان قاد الدمج، وكان نائب عن ميرتس ورئيس العمل بأيار 2024، ومعه على قائمة الموقع نعمة لزيمي وغلعاد كريف وإفرات ريتن. الحزب يعرّف نفسه صهيونيا. تجنيد للكل يعني الخدمة العسكرية على كل المواطنين ومنهم العرب. الانفصال عن الفلسطينيين يعني ينفصلوا عن الشعب الفلسطيني والأمن يضل بإيدهم. بعد التسمية اشرحي هاد المعنى، وبعدين ليش ما بتصوت لحزب مثل الموحدة، حزب عربي بدعم القضية وبجيب ميزانيات. ممنوع تذكري وقف الهدم بهالرد، وممنوع تختراعي جريمة أو حادثة عنصرية.',
                'source_url' => 'https://en.idi.org.il/israeli-elections-and-parties/parties/the-democrats/',
                'source_name' => 'المعهد الإسرائيلي للديمقراطية',
                'publication_date' => '2024',
                'last_verified_at' => '2026-10-20',
                'confidence' => 'high',
                'status' => 'record',
            ],
            [
                'id' => 'democrats-leadership',
                'topic' => 'قيادة الديمقراطيين',
                'needles' => ['قود', 'رئيس', 'لزيمي', 'كريف', 'ريتن'],
                'claim' => 'يائير غولان رئيس الحزب. كان نائب كنيست عن ميرتس، وانتخب رئيس حزب العمل بأيار 2024. المعهد بيذكر مع المرشحين نعمة لزيمي وغلعاد كريف. موقع الحزب بيذكر إفرات ريتن عضوة كنيست. ما في يوم محدد للانتخاب ولا عدد مقاعد بهالملف.',
                'source_url' => 'https://en.idi.org.il/israeli-elections-and-parties/parties/the-democrats/',
                'source_name' => 'المعهد الإسرائيلي للديمقراطية، وقائمة الأسماء من موقع الحزب',
                'publication_date' => '2024-05',
                'last_verified_at' => '2026-10-20',
                'confidence' => 'high',
                'status' => 'record',
            ],
            [
                'id' => 'democrats-golan',
                'topic' => 'يائير غولان',
                'needles' => ['غولان', 'גולן', 'يائير', 'يئير', 'יאיר'],
                'claim' => 'الموثق عن يائير غولان: نائب سابق عن ميرتس، انتخب رئيس العمل بأيار 2024، وقاد الدمج اللي أسس القائمة سنة 2024. موقع الحزب بيوصفه لواء احتياط. ما في اتهام ولا دافع ولا اقتباس جنائي ولا تصويت كنيست موثق بهالملف. لا تخترعي.',
                'source_url' => 'https://democrats.org.il/ar/ar-home/',
                'source_name' => 'موقع الديمقراطيين، والسيرة الانتخابية من المعهد الإسرائيلي للديمقراطية',
                'publication_date' => '2024-05',
                'last_verified_at' => '2026-10-20',
                'confidence' => 'high',
                'status' => 'record',
            ],
            [
                'id' => 'democrats-arabs',
                'topic' => 'برنامج الديمقراطيين والمجتمع العربي',
                'needles' => ['من العرب', 'المجتمع العربي', 'موقفهم'],
                'claim' => 'برنامج منشور مش سجل تصويت: مساواة حقوق وفرص، تعديل قانون القومية بروح وثيقة الاستقلال، وقف الضم، وتجنيد للكل. صفحة المجتمع العربي بتحكي عن إنهاء الجريمة والتمييز ومساواة مدنية وأمان شخصي. ممنوع تنسبي دافع أو جريمة أو مسؤولية تاريخية مش مكتوبة بالمصدر.',
                'source_url' => 'https://yes.democrats.org.il/',
                'source_name' => 'موقع الديمقراطيين، وصفحة المعهد الإسرائيلي للديمقراطية',
                'publication_date' => '2024',
                'last_verified_at' => '2026-10-20',
                'confidence' => 'medium',
                'status' => 'platform',
            ],
            [
                'id' => 'democrats-compare',
                'topic' => 'مقارنة الديمقراطيين',
                'needles' => ['الفرق', 'مقارنة', 'بينهم'],
                'claim' => 'مقارنة محايدة: الموحدة قائمة عربية دخلت ائتلاف حكومة التغيير. المشتركة ضلت برا، و15 مقعد برا ما منعت قانون القومية ولا كامينتس حسب كراس الموحدة، وهو وثيقة حزبية مش حكم. الديمقراطيون قائمة صهيونية من 2024 برئاسة غولان. ممنوع تقولي حزب أحسن وممنوع تضغطي لتغيير الاختيار.',
                'source_url' => 'https://en.idi.org.il/israeli-elections-and-parties/parties/the-democrats/',
                'source_name' => 'المعهد الإسرائيلي للديمقراطية، وفرق الموحدة من كراس الكادر',
                'publication_date' => '2024',
                'last_verified_at' => '2026-10-20',
                'confidence' => 'medium',
                'status' => 'record',
            ],
            [
                'id' => 'democrats-sources',
                'topic' => 'مصادر الديمقراطيين',
                'needles' => ['مصدر', 'رابط', 'من وين'],
                'claim' => 'المصادر: https://democrats.org.il/ar/ar-home/ و https://yes.democrats.org.il/ و https://en.idi.org.il/israeli-elections-and-parties/parties/the-democrats/ . كلام الموقع برنامج حزبي. المعهد بوصف التأسيس سنة 2024 والدمج الجزئي. جدول المعهد لانتخابات 2026 فاضي من عدد الأصوات والمقاعد، فلا تخترعي نتيجة.',
                'source_url' => 'https://en.idi.org.il/israeli-elections-and-parties/parties/the-democrats/',
                'source_name' => 'المعهد الإسرائيلي للديمقراطية وموقع الحزب',
                'publication_date' => '2026',
                'last_verified_at' => '2026-10-20',
                'confidence' => 'high',
                'status' => 'record',
            ],
        ];
    }

    public static function mentionsDemocrats(string $text): bool
    {
        $folded = self::fold($text);
        if (preg_match('/امريك|الولايات|بايدن|ترامب/u', $folded) === 1
            && preg_match('/اسراييل|דמוקרטים|العمل وميرتس|غولان|גולן/u', $folded) !== 1) {
            return false;
        }

        return preg_match('/ديموقراطي(?:ين|ون)|ديمقراطي(?:ين|ون)|دمقراطي(?:ين|ون)|דמוקרטים|العمل وميرتس|העבודה ומרצ|עבודה ומרצ|غولان|גולן/u', $folded) === 1;
    }

    public static function forThread(string $thread, string $topic = ''): string
    {
        $party = self::partyBlock($thread."\n".$topic);
        $body = self::moneyBlock($thread);
        if ($party === '') {
            return $body;
        }
        if ($body === '') {
            return $party;
        }

        return $party."\n".$body;
    }

    private static function moneyBlock(string $thread): string
    {
        $recent = mb_substr($thread, -400);
        $ranked = [];
        foreach (self::entries() as $entry) {
            $score = 0;
            foreach ($entry['needles'] as $needle) {
                if ($recent !== '' && mb_stripos($recent, $needle) !== false) {
                    $score += 2;
                } elseif (mb_stripos($thread, $needle) !== false) {
                    $score += 1;
                }
            }
            if ($score > 0) {
                $ranked[] = ['score' => $score, 'entry' => $entry];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $ranked = array_slice($ranked, 0, 2);
        if ($ranked === []) {
            return '';
        }

        $lines = ['وقائع بمصدر. الحالة: موافق غير مخصص غير ملتزم غير مصروف. لا تخلطيهم.'];
        foreach ($ranked as $row) {
            $entry = $row['entry'];
            $stale = self::isStale($entry['last_verified_at']);
            $prefix = $stale ? 'رقم قديم، لا تعرضيه كوضع اليوم. ' : '';
            $lines[] = '['.$entry['topic'].' | '.$entry['status'].' | '.$entry['source_name'].' | '.$entry['publication_date'].'] '.$prefix.$entry['claim'];
        }

        return implode("\n", $lines);
    }

    private static function partyBlock(string $thread): string
    {
        if (! self::mentionsDemocrats($thread)) {
            return '';
        }
        $foldedThread = self::fold($thread);
        $recent = mb_substr($foldedThread, -220);
        $ranked = [];
        foreach (self::partyEntries() as $entry) {
            $score = 0;
            foreach ($entry['needles'] as $needle) {
                $needle = self::fold($needle);
                if ($needle === '') {
                    continue;
                }
                if ($recent !== '' && mb_stripos($recent, $needle) !== false) {
                    $score += 3;
                } elseif (mb_stripos($foldedThread, $needle) !== false) {
                    $score += 1;
                }
            }
            if ($score > 0) {
                $ranked[] = ['score' => $score, 'entry' => $entry];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $ranked = array_slice($ranked, 0, 2);
        if ($ranked === []) {
            return '';
        }

        $lines = ['وقائع الديمقراطيين بمصدر. برنامج الحزب غير سجل التصويت وغير الاتهام. لا تخترعي جريمة او دافع.'];
        foreach ($ranked as $row) {
            $entry = $row['entry'];
            $lines[] = '['.$entry['topic'].' | '.$entry['status'].' | '.$entry['source_name'].' | '.$entry['publication_date'].'] '.$entry['claim'].' المصدر: '.$entry['source_url'];
        }

        return implode("\n", $lines);
    }

    private static function fold(string $text): string
    {
        return str_replace(
            ['أ', 'إ', 'آ', 'ى', 'ة', 'ؤ', 'ئ', 'ـ'],
            ['ا', 'ا', 'ا', 'ي', 'ه', 'و', 'ي', ''],
            $text,
        );
    }

    public static function isStale(string $lastVerifiedAt): bool
    {
        $verified = strtotime($lastVerifiedAt);
        if ($verified === false) {
            return true;
        }
        $days = (int) config('dima.facts_max_age_days', 540);
        if ($days < 1) {
            $days = 540;
        }

        return $verified < strtotime('-'.$days.' days');
    }
}
