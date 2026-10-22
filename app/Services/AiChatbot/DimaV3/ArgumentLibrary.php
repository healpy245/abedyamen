<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\DimaV3;

/**
 * Short campaign arguments, taken from دليل الكوادر 2026 and the clip
 * transcripts. The model picks which ones answer the person; it never writes
 * the argument itself, so a reply cannot be softened, hedged, or retracted.
 * Each one is used at most once per chat.
 */
class ArgumentLibrary
{
    /**
     * Male text; ObjectionReplies::feminize() derives the female form.
     *
     * @var array<string, array{summary:string,text:string,clip:string,question:string}>
     */
    private const ARGUMENTS = [
        'change_2021' => [
            'summary' => 'نتنياهو من 2009 باقي، والمرة الوحيدة اللي طلع فيها 2021 لما حزب عربي دخل الائتلاف',
            'text' => 'نتنياهو رئيس حكومة من 2009، انتخابات ورا انتخابات وهو باقي. المرة الوحيدة اللي طلع فيها كانت سنة 2021، مش بمظاهرة ولا ببيان، لما حزب عربي واحد دخل الائتلاف. يعني التغيير صار، وصار بصوتنا.',
            'clip' => 'impact-8',
            'question' => 'لو قعدنا كلنا بالبيت، مين برأيك رح يفرح أكثر، إحنا ولا بن غفير؟',
        ],
        'tried_everything' => [
            'summary' => 'جربنا المقاطعة و15 عضو بالمعارضة والتوصية على غانتس وما تغير إشي، والائتلاف 2021 هو اللي نجح',
            'text' => 'جربنا ما نصوت، نتنياهو بقي. جربنا 15 عضو بالمعارضة، نتنياهو بقي. جربنا التوصية على غانتس، راح قعد مع نتنياهو. وسنة 2021 جربنا الائتلاف، ونتنياهو طلع. أربع طرق، وحدة بس نجحت.',
            'clip' => 'impact-2',
            'question' => 'ليش نرجع للطرق اللي جربناها وما نجحت؟',
        ],
        'inside_vs_outside' => [
            'summary' => '15 مقعد بالمعارضة ما منعوا قانون القومية ولا كامينتس، و4 مقاعد جوا الحكومة حققت',
            'text' => 'الصراخ من المعارضة، حتى بـ15 مقعد، ما منع قانون القومية سنة 2018 ولا قانون كامينتس سنة 2017. بالمقابل، 4 مقاعد بس جوّا حكومة التغيير جابت ميزانيات واعتراف بقرى وجمّدت وتيرة الهدم.',
            'clip' => '',
            'question' => 'شو بدك من النائب اللي بتعطيه صوتك، خطاب قوي ولا نتيجة على الأرض؟',
        ],
        'one_year_record' => [
            'summary' => 'بسنة وحدة و4 مقاعد: 30 مليار، 2.5 مليار للجريمة، مسطحات، 3 قرى بالنقب',
            'text' => 'بسنة وحدة وبأربع مقاعد بس: خطة تقدّم 30 مليار لبلداتنا، 2.5 مليار لمحاربة العنف والجريمة، توسيع مسطحات بعدة بلدات، واعتراف بثلاث قرى بالنقب.',
            'clip' => 'impact-5',
            'question' => 'إذا هاد صار بأربع مقاعد وسنة وحدة، شو ممكن يصير بثمان مقاعد وأربع سنين؟',
        ],
        'seven_seats' => [
            'summary' => 'الهدف 7 مقاعد وفوق: كل مقعد بيخلي الموحدة شريك إجباري لأي حكومة بديلة لليمين',
            'text' => 'الهدف 7 مقاعد وفوق. كل مقعد زيادة للموحدة بيخليها الشريك اللي ما في حكومة بديلة لليمين بتقوم بدونه، وهيك منفرض شروط مجتمعنا بكرامة. مقعد زيادة بالمعارضة ما بيغير مين بيحكم.',
            'clip' => '',
            'question' => 'بدك صوتك يكون مقعد بيقرر، ولا مقعد بيتفرج؟',
        ],
        'trust_by_deeds' => [
            'summary' => 'الثقة بتنبني على الفعل: الموحدة قالت بتدخل وبتجيب، ودخلت وجابت، وحاسبها على اللي وصل',
            'text' => 'الثقة بتنبني على الفعل مش على الكلام. الموحدة قالت بدها تدخل الحكومة وتجيب ميزانيات لبلداتنا، ودخلت وجابت خطة 30 مليار وخطة جريمة. حاسبها على اللي وصل، وهاد بالزبط اللي بتعمله بصوتك.',
            'clip' => 'impact-5',
            'question' => 'مين أحق بثقتك: اللي جرّب وجاب، ولا اللي بس بيحكي؟',
        ],
        'implementation_gap' => [
            'summary' => 'صح في قصور بالتنفيذ، والحل مقاعد أكثر وسنين أكثر جوا، مش الانسحاب',
            'text' => 'صح، في قصور بالتنفيذ، وهاد نقد بمحله. بس الخطة كانت سنة وحدة قبل ما تسقط الحكومة. الحل إنه نرجع أقوى بمقاعد أكثر ولسنين أطول، مش إنه نترك الطاولة لغيرنا يقرر عنّا.',
            'clip' => '',
            'question' => 'شو بدك أكثر إشي يتغير ببلدك؟',
        ],
        'budgets_are_rights' => [
            'summary' => 'الميزانيات مش منة ومش فتات، حقنا، وبالسنة الأولى 90% من 6 مليار استخدم حسب بنك إسرائيل',
            'text' => 'الميزانيات مش منّة من حدا، هي حقنا كمواطنين. بالسنة الأولى من خطة تقدّم انخصص نحو 6 مليار، وحسب بنك إسرائيل استُخدم نحو 90% منهم، يعني مدارس وشوارع وبنى تحتية على الأرض.',
            'clip' => 'impact-3',
            'question' => 'لو كنا برّا الحكومة، مين كان جاب هالمليارات لبلداتنا؟',
        ],
        'crime_plan' => [
            'summary' => 'خطة 549 بـ2.5 مليار للجريمة لما الموحدة جوا، والضحايا نزلوا، وبعدها الشرطة بإيد بن غفير',
            'text' => 'لما الموحدة كانت جوّا الحكومة، انقرّت خطة 2.5 مليار لمحاربة العنف والجريمة، وحسب القائمة لأول مرة من سنين عدد الضحايا نزل. بعدها ملف الشرطة صار بإيد بن غفير.',
            'clip' => 'impact-5',
            'question' => 'بدك اللي يقرر عن أمان ولادك يكون بن غفير، ولا حدا منّا جوّا الحكومة؟',
        ],
        'demolition_decision' => [
            'summary' => 'الهدم بيتوقف بقرار قبل الهدم جوا الائتلاف، مش ببيان بعده',
            'text' => 'أمر الهدم بيطلع من الحكومة، واللي بيوقفه كمان الحكومة. البيت بينبنى بلا رخصة لأنه ما في مسطح، والمسطح بتقرره الحكومة. الحل مش بيان استنكار بعد الهدم، الحل قرار قبل الهدم، والقرار بينوخذ جوّا الائتلاف.',
            'clip' => 'impact-7',
            'question' => 'مين بدك يكون قاعد على الطاولة لما ينقرر مصير البيت الجاي؟',
        ],
        'housing_plot' => [
            'summary' => 'الشاب بده يبني، المسطح بيتقرر على طاولة الحكومة، والموحدة وسعت مسطحات',
            'text' => 'الشاب بده يتجوز ويبني ببلده، وما في قسيمة ولا مسطح. ومسطح البلد مش بس بإيد البلدية، بيتقرر على طاولة الحكومة. لما الموحدة كانت جوّا، توسعت مسطحات بعدة بلدات بسنة وحدة.',
            'clip' => 'impact-4',
            'question' => 'ولادك وين رح يبنوا إذا ضلينا برّا القرار؟',
        ],
        'negev' => [
            'summary' => 'قرى النقب عشرات السنين بلا اعتراف، والموحدة جابت اعتراف بثلاث قرى بالتوقيع على اتفاق ائتلاف',
            'text' => 'عشرات السنين قرى بالنقب بلا اعتراف، بلا مي ولا كهربا، ومظاهرات وبيانات ما غيرت إشي. الموحدة دخلت الحكومة والحكومة اعترفت بثلاث قرى، مش بالصوت العالي، بالتوقيع على اتفاق ائتلاف.',
            'clip' => 'impact-1',
            'question' => 'اللي جاب اعتراف لثلاث قرى، مش أقدر يجيب لغيرهم؟',
        ],
        'streets_schools' => [
            'summary' => 'الشارع المكسر والمدرسة المزدحمة ناقصهم ميزانية، والميزانية بتوزعها الحكومة',
            'text' => 'الشارع المكسّر والمدرسة المزدحمة ببلدك مش ناقصهم شعارات، ناقصهم ميزانية. والميزانية بتوزعها الحكومة. الفرق مين قاعد على الطاولة لما تتوزع.',
            'clip' => 'impact-3',
            'question' => 'شو أكثر إشي ناقص ببلدك هلق؟',
        ],
        'boycott_gift' => [
            'summary' => 'كل صوت عربي بيقعد بالبيت هدية لبن غفير وسموتريتش، والمقاطعة بتخلي الساحة للمتطرفين',
            'text' => 'كل صوت عربي بيقعد بالبيت هو هدية لبن غفير وسموتريتش. المقاطعة ما بتوجعهم، بتخلي الساحة إلهم يستفردوا ببلداتنا.',
            'clip' => '',
            'question' => 'شو رأيك: مقاطعتنا بتوجع بن غفير ولا بتريّحه؟',
        ],
        'indigenous' => [
            'summary' => 'إحنا أصحاب الأرض الأصليين، والتصويت أداة صمود مش تنازل عن الهوية',
            'text' => 'إحنا أبناء هالبلد وأصحاب الأرض الأصليين، وباقيين فيها. التصويت مش تنازل عن هويتنا، هو أداة مدنية نحمي فيها بيوتنا ووجودنا ونتحدى فيها العنصرية.',
            'clip' => '',
            'question' => 'مش أحسن نفرض وجودنا بدل ما ننسحب ونعطيهم الفراغ؟',
        ],
        'necessity' => [
            'summary' => 'الضرورات تبيح المحظورات: التصويت ضرورة لحفظ الأنفس بعد نحو 1000 قتيل ب4 سنين',
            'text' => 'في علماء كثير بيشوفوا المشاركة ضرورة، والقاعدة بتقول الضرورات بتبيح المحظورات. وإحنا اليوم عنا ضرورة حقيقية: نحو 1000 قتيل بأربع سنين، وبيوت بتنهدم.',
            'clip' => '',
            'question' => 'حماية الروح والبيت مش أولى من إنه نقعد ونتفرج؟',
        ],
        'no_gov_without_raam' => [
            'summary' => 'ما في حكومة بديلة لليمين بتتشكل بدون الموحدة',
            'text' => 'لغة الأرقام واضحة: ما في حكومة بديلة لليمين بتتشكل بدون الموحدة. يعني صوتك للموحدة هو الصوت اللي بيقرر إذا بن غفير بيضل ولا بيروح.',
            'clip' => 'impact-8',
            'question' => 'بدك صوتك يقرر مين بيحكم، ولا يضيع؟',
        ],
        'arab_mks_zionist' => [
            'summary' => 'النواب العرب بالأحزاب الصهيونية حققوا أضعاف أقل من الموحدة لحالها، والموحدة كتلة مستقلة',
            'text' => 'الموحدة لحالها حققت أضعاف اللي حققوه النواب العرب بالأحزاب الصهيونية. ليش؟ لأنها كتلة عربية مستقلة بتدخل الائتلاف براس مرفوع وبتفرض مطالب أهلنا، مش رقم بقائمة غيرها.',
            'clip' => '',
            'question' => 'بدك صوتك يروح لحزب بيحسبك رقم، ولا لحزب بيحسبك أولوية؟',
        ],
        'coalition_purpose' => [
            'summary' => 'الموحدة ما قعدت مع حدا عشان تحبه، قعدت عشان تنتزع حقوقنا',
            'text' => 'الموحدة ما قعدت مع حدا عشان تحبه، قعدت عشان تنتزع حقوقنا. التأثير الحقيقي بيكون جوّا الحكومة، مش من مقاعد المعارضة.',
            'clip' => '',
            'question' => 'الأهم مين قاعد معنا على الطاولة، ولا شو بنطلّع من الطاولة لأهلنا؟',
        ],
        'joint_toppled_change' => [
            'summary' => 'المشتركة بالمعارضة سعت لإفشال حكومة التغيير وصوتت مع نتنياهو',
            'text' => 'لما كانت الموحدة بالحكومة والمشتركة بالمعارضة، المشتركة سعت لإفشال حكومة التغيير وصوتت مع نتنياهو، والحكومة اللي جابت الميزانيات سقطت.',
            'clip' => '',
            'question' => 'مين خدم مجتمعنا أكثر بهديك الفترة؟',
        ],
        'unity_real' => [
            'summary' => 'الوحدة الحقيقية مش بس كراسي، تكامل أدوار وميثاق شرف، والمشتركة ب15 مقعد ما أسقطت نتنياهو',
            'text' => 'الوحدة قيمة مهمة، بس مش غاية لحالها. المشتركة جربناها ثلاث مرات ووصلت 15 مقعد سنة 2020، وما أسقطت نتنياهو ولا غيرت واقعنا. الوحدة الحقيقية تكامل أدوار وميثاق شرف، مش بس كراسي.',
            'clip' => 'impact-2',
            'question' => 'الوحدة أهم إشي، ولا الوحدة اللي بتجيب نتيجة؟',
        ],
        'civil_is_national' => [
            'summary' => 'ما في فصل بين المدني والوطني، الأمن والسكن والأرض كلها قضايا وطنية، والموحدة بتجمع الهوية والحقوق',
            'text' => 'الأمن الشخصي والسكن ومحاربة الجريمة والحفاظ على الأرض كلها قضايا بتمس كرامتنا وصمودنا، يعني هي قضايا وطنية كمان. والموحدة ما بتختار بين الهوية والحقوق، بتجمع بينهم.',
            'clip' => '',
            'question' => 'شو بيفيد الشعار إذا البيت انهدم والشاب انقتل؟',
        ],
        'vote_matters_now' => [
            'summary' => 'هالانتخابات مصيرية، والتصويت طوق نجاة لحفظ الأرواح وإسقاط حكومة اليمين',
            'text' => 'هالانتخابات مصيرية. التصويت مش إجراء شكلي، هو طوق نجاة: نسقط حكومة اليمين المتطرف، ونكون جوّا القرار نوقف الجريمة والهدم وننتزع ميزانيات بلداتنا.',
            'clip' => '',
            'question' => 'شو ممكن يخليك تطلع تصوت هالمرة؟',
        ],
    ];

    /**
     * Arguments that only make sense for someone who is not going to vote.
     *
     * @var list<string>
     */
    private const FOR_NON_VOTERS = ['change_2021', 'tried_everything', 'boycott_gift', 'indigenous', 'necessity', 'vote_matters_now'];

    /**
     * Best arguments for a party the person leans to, or a topic they raised, strongest first.
     *
     * @var array<string, list<string>>
     */
    private const FITS = [
        'joint' => ['inside_vs_outside', 'joint_toppled_change', 'unity_real', 'seven_seats', 'one_year_record'],
        'democrats' => ['arab_mks_zionist', 'no_gov_without_raam', 'seven_seats', 'one_year_record'],
        'zionist' => ['arab_mks_zionist', 'no_gov_without_raam', 'seven_seats'],
        'likud' => ['inside_vs_outside', 'crime_plan', 'demolition_decision', 'no_gov_without_raam'],
        'other' => ['seven_seats', 'no_gov_without_raam', 'one_year_record'],
        'futility' => ['change_2021', 'tried_everything', 'trust_by_deeds', 'seven_seats'],
        'record' => ['one_year_record', 'trust_by_deeds', 'budgets_are_rights', 'implementation_gap'],
        'crumbs' => ['budgets_are_rights', 'implementation_gap', 'streets_schools'],
        'crime' => ['crime_plan', 'seven_seats'],
        'demolition' => ['demolition_decision', 'housing_plot'],
        'coalition' => ['coalition_purpose', 'inside_vs_outside', 'seven_seats'],
        'joint_breakup' => ['unity_real', 'joint_toppled_change'],
        'identity' => ['indigenous', 'boycott_gift', 'civil_is_national'],
        'national_issues' => ['civil_is_national', 'indigenous'],
        'religious' => ['necessity', 'vote_matters_now'],
    ];

    public function suitsVoter(string $id): bool
    {
        return ! in_array($id, self::FOR_NON_VOTERS, true);
    }

    /**
     * Unused arguments that fit, in order: the party or topic first, then any that suit this person.
     *
     * @param  list<string>  $keys  party and topic ids
     * @param  list<string>  $used
     * @return list<string>
     */
    public function fitting(array $keys, bool $voter, array $used): array
    {
        $ordered = [];
        foreach ($keys as $key) {
            foreach (self::FITS[$key] ?? [] as $id) {
                $ordered[] = $id;
            }
        }
        foreach (array_keys(self::ARGUMENTS) as $id) {
            $ordered[] = $id;
        }
        $result = [];
        foreach ($ordered as $id) {
            if (in_array($id, $used, true) || in_array($id, $result, true) || ($voter && ! $this->suitsVoter($id))) {
                continue;
            }
            $result[] = $id;
        }

        return $result;
    }

    /**
     * One line per argument for the prompt.
     */
    public function catalog(): string
    {
        $lines = [];
        foreach (self::ARGUMENTS as $id => $argument) {
            $lines[] = '- '.$id.': '.$argument['summary'].(in_array($id, self::FOR_NON_VOTERS, true) ? ' (بس لمين مش ناوي يصوت)' : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_values(array_map(static fn (array $argument): string => $argument['text'], self::ARGUMENTS));
    }

    public function has(string $id): bool
    {
        return isset(self::ARGUMENTS[$id]);
    }

    /**
     * @return array{summary:string,text:string,clip:string,question:string}|null
     */
    public function get(string $id): ?array
    {
        return self::ARGUMENTS[$id] ?? null;
    }

    /**
     * Arguments not used yet in this chat, in library order: the fallback when
     * the model picks nothing usable.
     *
     * @param  list<string>  $used
     * @return list<string>
     */
    public function unused(array $used): array
    {
        return array_values(array_filter(array_keys(self::ARGUMENTS), static fn (string $id): bool => ! in_array($id, $used, true)));
    }
}
