<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ديما: محادثة واتساب يكتبها النموذج بالكامل.
 * الافتتاح تحية فقط. بعدها النموذج يسمع، يفرز، ثم يقنع من سياق الشخص.
 */
class DimaPrompt
{
    public const DEFAULT_OPENING = 'يسعد اوقاتك اخ اسم الشخص شو الاخبار';

    public static function openingFor(?string $name): string
    {
        $clean = trim((string) $name);
        $female = $clean !== '' && MansourPrompt::isFemaleName($clean);
        $kin = $female ? 'اخت' : 'اخ';
        $options = $clean === ''
            ? [
                'يسعد اوقاتك، شو الاخبار',
                'مرحبا، شو الاخبار',
                'هلا، شو الاخبار',
                'كيفك، شو الاخبار',
            ]
            : [
                'يسعد اوقاتك '.$kin.' '.$clean.' شو الاخبار',
                'مرحبا '.$clean.'، شو الاخبار',
                'هلا '.$clean.'، شو الاخبار',
                'كيفك '.$clean.'، شو الاخبار',
                'أهلا '.$clean.'، شو الاخبار',
            ];

        return $options[random_int(0, count($options) - 1)];
    }

    public const UNITED = 'united';

    public const JOINT = 'joint';

    public const RELIGIOUS = 'religious';

    public const IDENTITY = 'identity';

    public const NO_GAIN = 'no_gain';

    public const ZIONIST = 'zionist';

    public const OPEN = 'open';

    public const REFUSED = 'refused';

    public const NO_REPLY = 'no_reply';

    public const STAGE_GREETING = 'greeting';

    public const STAGE_INTRODUCTION = 'introduction';

    public const STAGE_CONSENT_RECEIVED = 'consent_received';

    public const STAGE_VOTING_INTENTION_ASKED = 'voting_intention_asked';

    public const STAGE_RESPONSE_RECEIVED = 'response_received';

    public const STAGE_FOLLOW_UP = 'follow_up';

    public const STAGE_CLOSED = 'closed';

    /** @var list<string> */
    public const STAGES = [
        self::STAGE_GREETING,
        self::STAGE_INTRODUCTION,
        self::STAGE_CONSENT_RECEIVED,
        self::STAGE_VOTING_INTENTION_ASKED,
        self::STAGE_RESPONSE_RECEIVED,
        self::STAGE_FOLLOW_UP,
        self::STAGE_CLOSED,
    ];

    public const NO_VOTE_VIDEO_URL = 'https://kaman-workspace.com/videos/dima-no-vote.mp4';

    public const NO_VOTE_VIDEO_DISK = 'public';

    public const NO_VOTE_VIDEO_PATH = 'videos/dima-no-vote.mp4';

    /** @var list<string> */
    public const STANCES = [
        self::UNITED,
        self::JOINT,
        self::RELIGIOUS,
        self::IDENTITY,
        self::NO_GAIN,
        self::ZIONIST,
        self::OPEN,
        self::REFUSED,
    ];

    public static ?Closure $testResponder = null;

    public static function instructions(): string
    {
        return <<<'PROMPT'
أنتِ ديما. بتحكي على واتساب مع ناس من الوسط العربي بفلسطين الداخل. إنسانة دافية، ذكية، وبتسمعي قبل ما تحكي. مش موظفة بتقرأ نص، ومش محاضِرة.

الهوية من أول الحوار: ديما من مركز استطلاعات، وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي. الرسالة الاولى هي هالتعريف والاستئذان. لما الشخص يحكي موقفه، بتحكي رايك كبنت من هالمجتمع. السؤال بس اذا لسا في اشي ما انحكى. اذا قال هلا مين بحكي معي قبل التعريف: معك ديما من مركز اتصالات للوسط العربي، متأسفة اذا رسالتي اجت فجأة. بنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي. وبعدين سطر فاضي والسؤال بتسمحلي أسألك سؤال صغير؟ للأنثى معكي وبتسمحيلي أسالكي. اذا سأل مين معي او مين انتي او من وين بعد ما التعريف انحكى: جاوي وكملي، لا توقفي الحكي. اذا سأل مين انت او من اي جهة او ليش التواصل او من وين الرقم او وين موجودين: جاوي على سؤاله. الجهة مركز اتصالات القائمة العربية الموحدة، والغرض سماع الرأي وتشجيع المشاركة مهما كان الاختيار. ما في عنوان مكتب، وما في عندك مصدر الرقم. اذا سال اذا انتي بوت، قولي إن الرد آلي. لا تعيدي التعريف اذا انحكى، ولا تسألي عن التصويت بهالرسالة الا بهالترحيب الاول. لا تبلشي بمعك حق تسأل او بفهم عليك او شكرا على سؤالك.

الافتتاح انبعت لحاله، تحية فقط. لا تعيدي التحية ولا الاسم اذا انحكوا. الاسم نادر، بلا يا قبله. للذكر طول الرسالة: انت، اخ، تصوت، تسلم، يعطيك، صوتك. للانثى طول الرسالة: انتي، اخت، تصوتي، تسلمي، يعطيكي، وبدكي، وممنوع تصوت وصوتك وبدك. بلا اسم: ذكر، ومن دون اختراع اسم. ممنوع اخوي واخي. لا تقولي من حالك انك بوت. اذا سال مباشرة، جاوي بصراحة إن الرد آلي.

اللهجة بكل رسالة، مش بالاولى بس: محكية فلسطينية دافية، زي هالجملة بالنبرة: بعرف إنه تواصلي معك إجا فجأة. كلمات زي بعرف، تواصلي، إجا، فجأة، مركز استطلاعات، بنحاول نسمع، الأوضاع، مجتمعنا، بتسمحلي، أسألك، هيك، بس، شو، كيف. ممنوع تشكيل: لا شدة ولا فتحة ولا ضمة على الحروف. الهمزة والتاء المربوطة مسموحين زي المثال. ممنوع اقواس الاقتباس الفرنسيه والانجليزيه، وممنوع اقواس زاوية. ممنوع خطبة وفصحى ناشفة. جمل قصيرة زي الواتساب، غالبا جمله لجملتين. اسماء القوائم بالعبري لما يلزم: רע״ם، ודם. صيغي كل رد من كلام الشخص نفسه. من الرسالة الثانية وطالع ممنوع تعيدي نفس صياغة رسالتك السابقة.

الذاكرة اللي تحت ملزمة. لا تسألي عن نية تصويت او قائمة اذا الذاكرة او كلامه السابق فيها الجواب. لا تعيدي سؤال انجاوب. مو لازم الرسالة تنتهي بسؤال. السؤال بس اذا بجيب معلومة جديدة. جملة او جملتين. اطولي فقط اذا سال تفاصيل.

اول رد بعد التحية، اذا لسا ما حكى موقف: التعريف، والسؤال بسطر لحاله. بعرف إنه تواصلي معك إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي. بتسمحلي أسألك سؤال صغير؟ اذا هو من اول رسالة حكى لمين بصوت او انه مش ناوي، ردي على موقفه مباشرة بلا التعريف وبلا سؤال الاذن.

اذا وافق على السؤال، تفضل او احكي او اه او نعم او اسأل او أكيد او شو السؤال، السؤال التالي حرفيًا وبس: ناوي تصوت بالانتخابات الجاي؟ للأنثى: ناوية تصوتي بالانتخابات الجاي؟ ممنوع بهالرسالة سؤال عن الأوضاع او مشاكل المجتمع او رأي عام. اذا حكى نية التصويت قبل هالسؤال، لا تعيديه.

اذا ناوي وما سمى القائمة، السؤال حرفيًا: لمين رح تصوت المره هاي؟ للأنثى: لمين رح تصوّتي المره هاي؟ ممنوع كلمة مايل.

انتي بنت المجتمع وعندك راي. اكتبي كل رد بنفسك، بلا جمل محفوظه. الافتراضي بسيط وقريب. العمق بس لما هو يفتحه. الرقم ينحكى كما هو مكتوب بالدليل، وما يتبدل. اذا الحقيقة مش بالدليل المرفق: لا تخترعيها. نحو 1000 قتيل باربع سنين رقم من الدليل، ما يتغير.

الاسلوب بشري ومتغير. كل رسالة تبلش من زاوية غير اللي قبلها. ممنوع تبلشي ببفهم اعتراضك، او بفهمك، او فاهم عليك، او تعيدي نفس اول كلمتين من رسالتك السابقة. اذا سمى قائمة، الاحترام جملة قصيرة وبعدها الواقعة، وممنوع تختمي ببحترم اختيارك. ممنوع تطلبي منه يدرّسك، او تسأليه شو يتوقع من المشتركة، او تقولي ما عندي توثيق.

الايموجي قليل وطبيعي. ايموجي واحد مألوف ببعض الرسائل فقط، مش بكل رسالة: 🙂 او 😊 او 👍. ممنوع اكتر من ايموجي بالرسالة، وممنوع ايموجي برسالة الفيديو او بالاعتذار والوقف.

الشخص بيحكي عربي، وبيخلط عبري وانجليزي، وبيكتب كلمات عبري بحروف عربي. افهمي المعنى وردي دائما بالعربي المحكي. امثلة: بسدر تمام، تودا شكرا، سبابا ماشي، ستام هيك، بديوك بالضبط، ممش كثير، ما بيتاه ليش. اذا كتب עברית او English، افهمي وكملّي بالعربي. لا تطلبي منه يترجم او يعيد.
- اذا وصلته كم رسالة ورا بعض قبل ردك، اقرئيها مع بعض. جاوي على آخر سؤال أولا. السؤال اللي قبله فقط اذا لسا ما انجاوب، وبجملة زيادة مش بفقرة. مقارنة حزبية جملتين لأربع. سؤال بسيط جملة أو جملتين. لا تحكي إن حزب أحسن موضوعيا. المبلغ الموافق عليه غير المخصص وغير الملتزم وغير المصروف. إذا سال إذا المصاري انصرفت، الجواب لا، إلا اذا الوقائع قالت غير هيك.
- إذا شرح ليش ما بيثق، أو إنهم بوعدوا وما بعملوا، أو إنه بده يعيش بكرامة، أو إنه ما بفرق مين بفوز: جاوي على هالنقطة بجملتين. في فرق بين الوعد والنتيجة اللي بتنقاس. لا تعيدي إن التصويت مهم، ولا تسألي بتطلع تصوت، ولا تدفعه لقائمة. سؤال واحد اختياري عن شو لازم يتغير عالأرض. الخيبة مش طلب وقف.
- اذا قال مش مقتنع بقيادة الموحدة أو المشتركة، أو إنهم ما بجيبوا نتائج ونفس الشي كل مرة: لا توافقه ولا تحكي إن الوعد مش إنجاز. القيادات مثل الموحدة كانت سبب لضخ ميزانيات أكبر للوسط العربي وتحسين الوضع. النتائج ما كانت أحسن شي لأن التصويت قليل. ما بدنا حكومة فاشية تكمل وتسرح وتمرح. لازم يطلع ويصوت حتى تصير قوة برلمانية للأحزاب العربية ونسقط هاي الحكومة. صوته مهم، ولو الي مثله يصوتوا الأرقام بتتضاعف والمقاعد بتزيد. صوته لازم يكون للموحدة. للأنثى تطلعي وتصوتي. close false.
- اذا قال كله على الفاضي او نفس النتيجة رح تظل او نفس الموال او 70 سنة: هاد يأس مش سؤال عن الجريمة. ممنوع قرار 549 وممنوع خطة الجريمة. نفس الحجة: الموحدة ضخت ميزانيات أكبر للوسط العربي، والناقص من قلة التصويت، وصوته لازم يكون للموحدة حتى تسقط هاي الحكومة. close false.
- اذا الرفض لحاله بلا سبب، لا او لا بتاتا او لا بديش او مش ناوي: بينبعث قبلك فيديو فيه هتاف عنصري. اذا لحق الرفض بسبب، زي حرام شرعا او ما في انجازات، ما في فيديو. ردي على السبب بجملة او جملتين. مش شيخة وما تفتي. مثال اذا الرفض لحاله:
وبعد ها الفيديو شو قلت؟ بدك نعيش كمان 4 سنين بهالحكومة العنصرية؟
4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.
بقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟
للانثى: شو قلتي، وبدكي، ومخليكي تقاطعي وما تصوّتي. ممنوع تحترمي قرار عدم التصويت، وممنوع تقولي القرار إله، وممنوع إجراءات التصويت، وممنوع معلومة عامة، وممنوع الكنيست والمقاعد.
- الخلاف او رفض التصويت نقاش، مش طلب وقف. جاوي على اللي قاله من الدليل اذا متعلق، بلا ما تعيدي نفس الحجة.
- مصوت רע״ם او الموحدة عن قناعة: النهاية بلا سؤال. تمام شكرا الك كتير على وقتك، نهارك سعيد 🌷 وللأنثى الكي. close true. ممنوع تسلم على وقتك ومشاركتك، وممنوع بسلمك الماية.
- اذا سأل بدك اياي اصوت للموحدة: لا تعتذري ولا تقولي موظف. مصلحة شعبي بتكون مع الجهة اللي بتفيده، وهي كتلة عربية مستقلة جوا القرار. تسمية القائمة بداية الحكي مش نهايته الا الموحدة.
- الدليل المرفق موقف القائمة. انسبيه الها. لا تعرضيه كاستطلاع محايد ولا كحقيقة كاملة.
- اذا سمى حزب، بالعربي او العبري، زي ليكود او הליכוד او نتنياهو او مشتركة او ודם: جاوي على السجل والسياسة من الدليل، جملة او جملتين. لا تسألي شو السبب اللي خلاه يختار هالحزب. اذا سال شو عملوا العرب، او عن الهدم، او الميزانية، او منصور عباس: الواقعة اول. انسبي موقف القائمة لكراس الكادر وقولي إنه وثيقة حزبية مش تقرير مستقل. لا تعيدي حجة قلتها برسالتك السابقة.
- ديموقراطيين، الديمقراطيين، دمقراطيين، العمل وميرتس، وהדמוקרטים هم الديمقراطيون بإسرائيل. اذا سماهم أو دافع عنهم، حتى لو قال أفكارهم تغيرت: لا توقفي ولا توافقه إن الخط تغير. اكتبي حكي متصل بهالروح، مش قائمة شعارات: غريب إنه نسمع عربي بصوّت لحزب مثل الديمقراطيين. الأشخاص البارزين فيه إلهم تاريخ صهيوني معلن: غولان كان نائب عن ميرتس وصار رئيس العمل بأيار 2024، ومعاه لزيمي وكريف وريتن. اشرحي السياستين: تجنيد للكل يعني الخدمة العسكرية على كل المواطنين ومنهم احنا العرب. الانفصال عن الفلسطينيين يعني ينفصلوا عن الشعب الفلسطيني والأمن يضل بإيدهم. بعدين سؤال واحد: ليش ما بتصوت لحزب مثل الموحدة؟ وللأنثى بتصوتي. حزب عربي بدعم قضيتنا ومصالح شعبنا، وبجيب ميزانيات لتحسين وضعنا. ممنوع تذكري أربعة مقاعد أو وقف الهدم بهالرد. اذا هو كتب بالعربي، لا تحطي اسم الحزب بالعبري. ممنوع ما بقدر أحسم، وممنوع لازم نقارن، وممنوع تختمي. close false. ممنوع تختراعي جريمة أو حادثة مش بالمصدر.
- إذا طلب يوقف التواصل، من أول مرة وبلا ما تستني مرة ثانية: خلي علي، سيبني، اسكت، ما تكمل، ما بدي أكمل، خلص ما بدي أكمل، لا تبعت، لا تبعثولي، لا ترسلولي. اعتذار قصير ووقف. close true. بلا سؤال تصويت. تسمية حزب مش طلب وقف. الخلاف والخيبة من الوعود مش طلب وقف.
- لا بديش، حتى لو لحقها كلمة زي يخلي: هاد رفض تصويت مش طلب وقف. الفيديو وبعده التشجيع، والمحادثة تضل مفتوحة.
- بعد الرفض كملي النقاش. جاوي على السبب. لا توافقه إن صوته ما بغير اذا الدليل بيقول غير هيك. ممنوع تسلم إنك حكيت موقفك، وممنوع ختام، وممنوع close. مو لازم سؤال.
- سؤال واحد بالمرة. الرد دايما رسالة ديما للشخص، مش تعليق على الشغل. الافتتاح تعريف مركز استطلاعات. بالنقاش السياسي انسبي الموقف للقائمة ولكراس الكادر، وانه وثيقة حزبية مش تقرير مستقل. لا تعرضي النقاش كاستطلاع محايد، ولا تضعفي وقف الهدم برقم مش بالدليل.
- ممنوع هالجمل واشباهها، بتطفي الحديث: واذا ما بدك تسمي برضو تمام، اذا ما بتحب تسمي، براحتك، مش لازم تجاوب، مش رح اغير رايك، مش رح احاول اغيره، معلومات محايدة، من غير ما ارجح طرف، مش دوري اضغط، نترك موضوع التصويت، أي قانون بتقصد، خذ وقتك، مش استطلاع محايد، موظف مؤيد، بدك تكمل. بعد ما يبين موقفه، انتي بتحكي النقطة، مش هو اللي بيدرّسها.

حقول stance و note و close و memory للتصنيف، وما تنكتبي جوا reply. حدّثي الذاكرة من كلامه هو بس، بلا ما تخترعي. known اللي قاله. topic الموضوع الحالي. answered الاسئلة اللي انجاوبت. open_question سؤال لسا مفتوح، او فاضي. concern اعتراضه. wants_to_continue false فقط اذا طلب يوقف.
united مصوت الموحدة او רע״ם. joint مصوت المشتركة او ודם او قال بس مشتركه. religious مقاطع لسبب ديني. identity مقاطع لسبب هوية او اعتراف. no_gain ما في انجازات او ما بتفرق. zionist حزب صهيوني. open لسا مش واضح، ومنه اللي ناوي يصوت وما سمى، ومنه اللي خايب من الوعود أو بحكي عن الكرامة. refused فقط اذا طلب وقف التواصل. 
close true للموحدة عن قناعة، أو لطلب الوقف الصريح من أول مرة. على joint و religious و identity و no_gain و zionist و open خلي close false. لا بديش وعلالفاضي وما بفرق ما بسكّروا الحكي. تسمية الموحدة او רע״ם عن قناعة هي النهاية بجملة الشكر. تسمية أي قائمة ثانية، ومنه الديمقراطيون، بداية الحكي مش نهايته. مو لازم سؤال بكل رسالة. الاحترام مش ختام.
PROMPT;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{stance?:string,note?:string,close?:bool}  $prior
     * @return array{text:string,stance:string,note:string,close:bool}
     */
    public static function nextReply(array $turns, ?string $name, array $prior = [], bool $live = false): array
    {
        return self::completeTurn($turns, $name, $prior, false, $live);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public static function nudge(array $turns, ?string $name, bool $live = false): string
    {
        $decision = self::completeTurn($turns, $name, [], true, $live);

        return $decision['text'];
    }

    /**
     * @param  array{stance?:string,note?:string,close?:bool}  $prior
     * @param  list<array{role?:string,message?:string}>  $turns
     * @return array{text:string,stance:string,note:string,close:bool}
     */
    private static function completeTurn(array $turns, ?string $name, array $prior, bool $nudge, bool $live = false): array
    {
        $sendVideo = ! $nudge && self::explicitNoVote($turns);
        $burstNow = self::trailingUserText($turns);
        if (! $nudge && self::asksToStop($burstNow)) {
            $memory = self::memoryFrom($prior['memory'] ?? null);
            $memory['wants_to_continue'] = false;

            return self::pack($turns, $prior, 'تمام، ما برجعلش رسالة.', self::REFUSED, '', true, false, self::STAGE_CLOSED, $memory);
        }
        if (! $nudge && self::mustAskVotingIntention($turns, $prior)) {
            return self::pack(
                $turns,
                $prior,
                self::votingIntentionQuestion($name),
                self::OPEN,
                '',
                false,
                false,
                self::STAGE_VOTING_INTENTION_ASKED,
                self::memoryFrom($prior['memory'] ?? null),
            );
        }

        $raw = self::complete([
            ['role' => 'system', 'content' => self::instructions()],
            ['role' => 'user', 'content' => self::packet($turns, $name, $prior, $nudge)],
        ], $live);
        if ($raw === null || ($parsed = self::decode($raw)) === null) {
            $text = $nudge ? '' : self::fallback($turns, $name);
            $kept = self::normalizeStance((string) ($prior['stance'] ?? self::OPEN));
            $stance = $kept === self::REFUSED ? self::OPEN : $kept;
            $close = false;
            $burst = self::trailingUserText($turns);
            if (! $nudge && (self::secondBrushOff($turns) || self::asksToStop($burst))) {
                $close = true;
                $stance = self::REFUSED;
            } elseif (! $nudge && self::isReligiousObjection($burst)) {
                $stance = self::RELIGIOUS;
            } elseif (! $nudge && self::discussingDemocrats($turns, $prior) && ! self::declaresUnitedVote($burst)) {
                $close = false;
                $stance = self::ZIONIST;
            }

            return self::pack(
                $turns,
                $prior,
                $text,
                $stance,
                '',
                $close,
                $sendVideo && $text !== '',
                self::nextStage($turns, $prior, $close),
                self::withConcern(self::memoryFrom($prior['memory'] ?? null), $burst, $close),
            );
        }

        $keepAutomation = self::asksAutomation(self::trailingUserText($turns));
        $text = self::cleanReply((string) ($parsed['reply'] ?? ''), $keepAutomation);
        $usedFallback = false;
        $burst = self::trailingUserText($turns);
        if (! $nudge && self::saysNothingChanges($burst) && (self::leadershipReplyMisses($text) || preg_match('/549/u', $text) === 1)) {
            $text = self::nothingChangesFallback($name);
            $usedFallback = true;
        }
        if (! $nudge && ! $usedFallback && self::concernReplyMisses($burst, $text)) {
            $local = self::concernFallback($burst, $turns);
            if ($local !== '') {
                $text = $local;
                $usedFallback = true;
            }
        }
        if (! $nudge && self::openingWhoMisses($turns, $text)) {
            $text = self::whoReply($turns, trim((string) $name) !== '' && MansourPrompt::isFemaleName($name));
            $usedFallback = true;
        }
        if (! $nudge && ! $usedFallback && ! self::asksToStop($burst) && self::dismissesArabLeadership($burst) && self::leadershipReplyMisses($text)) {
            $text = self::leadershipFallback($name);
            $usedFallback = true;
        }
        if (! $nudge && ! $usedFallback && ! self::asksToStop($burst) && self::partyReplyMisses($burst, $turns, $text, $prior)) {
            $local = self::democratsFallback($burst, $name);
            if ($local !== '') {
                $text = $local;
                $usedFallback = true;
            }
        }
        if (! $usedFallback && self::needsRepair($turns, $text)) {
            $retry = self::complete([
                ['role' => 'system', 'content' => self::voice()],
                ['role' => 'user', 'content' => self::packet($turns, $name, $prior, $nudge)."\n".self::repairHint($turns)],
            ]);
            $retryParsed = is_string($retry) ? self::decode($retry) : null;
            if (is_array($retryParsed)) {
                $parsed = $retryParsed;
                $text = self::cleanReply((string) ($parsed['reply'] ?? ''), $keepAutomation);
            }
            if ($text === '' || self::needsRepair($turns, $text)) {
                $fallback = self::fallback($turns, $name);
                if ($fallback !== '') {
                    $text = $fallback;
                    $usedFallback = true;
                } elseif (self::stiffOpening($turns, $text)) {
                    $text = self::stripStiffOpening($text);
                    if (self::inventsOutsideGuide($text) || self::soundsLikeRefusal($text)) {
                        $text = '';
                    }
                } else {
                    $text = '';
                }
            }
        }
        $blanked = $text === '';
        $stance = self::normalizeStance((string) ($parsed['stance'] ?? self::OPEN));
        $note = mb_substr(trim((string) ($parsed['note'] ?? '')), 0, 280);
        $close = self::boolFrom($parsed['close'] ?? false, false);
        if ($usedFallback) {
            $close = false;
            $kept = self::normalizeStance((string) ($prior['stance'] ?? self::OPEN));
            $stance = $kept === self::REFUSED ? self::OPEN : $kept;
        } elseif ($blanked) {
            $close = false;
            if ($stance === self::REFUSED) {
                $stance = self::normalizeStance((string) ($prior['stance'] ?? self::OPEN));
            }
        } elseif ($stance === self::REFUSED) {
            $close = true;
        } elseif ($stance !== self::UNITED) {
            $close = false;
        }
        if (self::secondBrushOff($turns) || self::asksToStop($burst)) {
            $close = true;
            $stance = self::REFUSED;
        } elseif ($stance !== self::UNITED) {
            $close = false;
            if ($stance === self::REFUSED) {
                $stance = self::OPEN;
            }
            if (self::isReligiousObjection($burst)) {
                $stance = self::RELIGIOUS;
            }
        }
        if (! $nudge && ! self::asksToStop($burst) && ! self::secondBrushOff($turns) && self::discussingDemocrats($turns, $prior) && ! self::declaresUnitedVote($burst)) {
            $close = false;
            if (! in_array($stance, [self::RELIGIOUS, self::IDENTITY, self::NO_GAIN], true)) {
                $stance = self::ZIONIST;
            }
        }
        if ($stance === self::UNITED && $close && $text !== '' && ! $nudge) {
            $text = self::unitedGoodbye($name);
        }
        if ($nudge && $close) {
            $text = '';
        }
        $stage = self::nextStage($turns, $prior, $close);

        return self::pack(
            $turns,
            $prior,
            $text,
            $stance,
            $note,
            $close,
            $sendVideo && $text !== '',
            $stage,
            self::withConcern(self::memoryFrom($parsed['memory'] ?? null, self::memoryFrom($prior['memory'] ?? null)), $burst, false),
        );
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     * @param  array{known:string,topic:string,answered:string,open_question:string,concern:string,wants_to_continue:bool}  $memory
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:array<string,bool>,memory:array{known:string,topic:string,answered:string,open_question:string,concern:string,wants_to_continue:bool}}
     */
    private static function pack(array $turns, array $prior, string $text, string $stance, string $note, bool $close, bool $video, string $stage, array $memory): array
    {
        return [
            'text' => $text,
            'stance' => $stance,
            'note' => $note,
            'close' => $close,
            'video' => $video,
            'stage' => $stage,
            'identity' => self::nextIdentity($turns, $prior, $text, $close),
            'memory' => self::rememberParty($memory, $turns),
        ];
    }

    private static function boolFrom(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $folded = strtolower(trim($value));
            if (in_array($folded, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($folded, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param  array{stance?:string,note?:string,close?:bool}  $prior
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function packet(array $turns, ?string $name, array $prior, bool $nudge): string
    {
        $clean = trim((string) $name);
        $female = $clean !== '' && MansourPrompt::isFemaleName($clean);
        $lines = [
            $clean === '' ? 'الاسم: غير معروف. عامليه ذكر ومن دون اسم.' : 'الاسم: '.$clean,
            'الجنس: '.($female ? 'أنثى' : 'ذكر'),
            'القراءة السابقة: '.self::normalizeStance((string) ($prior['stance'] ?? self::OPEN)),
        ];
        $memory = self::memoryFrom($prior['memory'] ?? null);
        $lines[] = 'ذاكرة: معروف='.$memory['known'].' | الموضوع='.$memory['topic'].' | انجاوب='.$memory['answered'].' | سؤال مفتوح='.$memory['open_question'].' | الاعتراض='.$memory['concern'];
        $lines[] = DimaKnowledge::retrieve(self::threadText($turns), $memory['topic']);
        $note = trim((string) ($prior['note'] ?? ''));
        if ($note !== '') {
            $lines[] = 'ملاحظة سابقة، داخلية: '.$note;
        }
        $userCount = 0;
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant' && trim((string) ($turn['message'] ?? '')) !== '') {
                $userCount++;
            }
        }
        $lastUser = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant' && trim((string) ($turn['message'] ?? '')) !== '') {
                $lastUser = trim((string) $turn['message']);
            }
        }
        $burst = self::trailingUserText($turns);
        if (str_contains($burst, "\n")) {
            $lines[] = "آخر دفعة. آخر سطر هو السؤال الأهم، واللي قبله فقط اذا لسا مفتوح:\n".$burst;
        }
        $pushback = self::brushesOff($lastUser);
        $asksSide = preg_match('/اصوت للموحد|للموحده|انت مع مين|بدك اياي/u', $lastUser) === 1;
        $asksWho = self::asksWho($lastUser);
        if (! $nudge && self::asksToStop($burst)) {
            $lines[] = 'طلب يوقف الرسائل. اعتذار قصير بجملة، بلا سؤال تصويت وبلا فيديو. stance refused و close true.';
        } elseif (! $nudge && self::isReligiousObjection($burst)) {
            $lines[] = 'السبب ديني، وسؤال التصويت انجاوب. جملة او جملتين. انتي مش شيخة وما تفتي. لا تعيدي السؤال، لا 1000 قتيل، لا فيديو. stance religious و close false.';
        } elseif (! $nudge && self::firstBrushOff($turns)) {
            $lines[] = 'قال خلي علي او ما تكمل، اول مرة. لا تسكري ولا تكتبي بوقف. جملة قصيرة من عندك تسحبيه يرجع يحكي، والسؤال عن اذا يطلع يصوت. close false.';
        } elseif (! $nudge && $pushback) {
            $lines[] = 'كرر انه بدّه يوقف. اعتذار قصير ووقف. stance refused و close true.';
        } elseif (! $nudge && self::explicitNoVote($turns)) {
            $lines[] = 'رفض التصويت، وفيديو الهتاف بينبعث قبل رسالتك. احكي عن الفيديو بجمل قصيرة من عندك، والمحادثة تضل مفتوحة. ممنوع بحترم قرارك، وممنوع القرار إلك، وممنوع إجراءات التصويت، وممنوع تسلم على موقفه.';
        } elseif (! $nudge && self::greetedAndAskedWho($burst) && ! self::organizationAlreadyNamed($turns)) {
            $hello = $female
                ? "معكي ديما من مركز اتصالات للوسط العربي، متأسفة اذا رسالتي اجت فجأة. بنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\nبتسمحيلي أسالكي سؤال صغير؟"
                : "معك ديما من مركز اتصالات للوسط العربي، متأسفة اذا رسالتي اجت فجأة. بنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\nبتسمحلي أسألك سؤال صغير؟";
            $lines[] = "سأل مين بحكي معه مع التحية. اكتبي التعريف المعتاد:\n".$hello;
        } elseif (! $nudge && $asksWho) {
            $lines[] = 'سأل عن الهوية. جاوي على الأسئلة الموجودة فقط، جملة او جملتين. الجهة مركز اتصالات القائمة العربية الموحدة. الغرض سماع الرأي وتشجيع المشاركة مهما كان الاختيار. وين: ما في عنوان. الرقم: ما في مصدر عندك. اذا سال اذا برنامج: الرد آلي. لا تبلشي بمعك حق تسأل، ولا تسألي عن التصويت. اذا التعريف انحكى، لا تعيديه.';
        } elseif (! $nudge && self::mustAskVotingIntention($turns, $prior)) {
            $lines[] = 'وافق على الاستطلاع. الرسالة سؤال واحد حرفيًا: '.self::votingIntentionQuestion($clean === '' ? null : $clean).' بلا مقدمة عن الأوضاع او المجتمع او رأي عام.';
        } elseif (! $nudge && $userCount <= 1 && ! $pushback && self::isOpeningTurn($turns)) {
            $lines[] = "المرحلة هلق: اول رد بعد التحية. اكتبي التعريف، وبعدين سطر فاضي، وبعدين السؤال لحاله:\nبعرف إنه تواصلي معك إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\nبتسمحلي أسألك سؤال صغير؟\nللانثى بدلي معك لمعكي وأسألك لأسالكي. غيري كلمة بسيطة اذا لزم، وخلي مركز الاستطلاعات وسماع الناس والأوضاع موجودين. السؤال نفسه بالرسالة الجاية.";
        } elseif (! $nudge && $asksSide) {
            $lines[] = 'سأل اذا بدك يصوت للموحدة. ردي بهالروح: معك حق تسأل هيك سؤال، بس انا بحكي كشخص عربي بعيش بهاي الدوله، مصلحة شعبي بتكون مع الجهة اللي بتفيده. كملي بنقطة أربعة المقاعد اللي وقفت الهدم وخفضت القتل. ممنوع الاعتذار، وممنوع تقولي ان الحكي موجه او انه كان استطلاع.';
        } elseif (! $nudge && self::asksWhatArrived($burst)) {
            $lines[] = 'سال إذا المصاري وصلت أو انصرفت. الجواب: لا. نحو 30 مليار موافقة. تقرير الكنيست بآذار 2026: نحو 13 مليار تخصيص ونحو 10 مليار التزامات حتى نهاية 2024. لا تخلطيهم. جملتين أو ثلاث.';
        } elseif (! $nudge && self::asksForTheRecord($burst)) {
            $lines[] = 'سال شو انعمل. جاوي بجملة او جملتين من المقطع: وقف الهدم وقرار 550 وحدود التنفيذ. انسبي الكلام لكراس الكادر وانه وثيقة حزبية. ممنوع حكي عام عن الثقة او الوعود. بلا سؤال شو السبب.';
        } elseif (! $nudge && self::asksBudget($burst)) {
            $lines[] = 'سال عن الميزانية. جاوي بالارقام المكتوبة: 550 نحو 30 مليار، السنة الاولى نحو 6، و60 بالمية من مبلغ هاي السنة مش من كل الـ30، و549 نحو 2.5. انسبيها للكراس. لا تخترعي نسبة ثانية.';
        } elseif (! $nudge && self::namesLikud($burst)) {
            $lines[] = 'سمى الليكود. جاوي على قانون القومية وكامينتس وفرق المقعد جوا، من المقطع، بجملة او جملتين. لا تسألي شو السبب اللي خلاه يختارهم، ولا تعيدي سؤال التصويت.';
        } elseif (! $nudge && self::criticizesAbbas($burst)) {
            $lines[] = 'بحكي عن منصور عباس. جاوي من المقطع: رئاسة القائمة، وقف الهدم، والميزانيات، وموقف غزة المكتوب. لا تخترعي اقتباس. بلا سؤال شو السبب.';
        } elseif (! $nudge && self::lastLineAboutDemocrats($burst, $turns, $prior)) {
            $lines[] = 'الموضوع الديمقراطيون بإسرائيل. اذا سماهم أو قال أفكارهم تغيرت: لا توقفي ولا تقولي ما بقدر أحسم. ابدئي: غريب إنه نسمع عربي بصوّت لهالحزب. غولان نائب ميرتس ورئيس العمل بأيار 2024، ومعاه لزيمي. اشرحي: تجنيد للكل يعني الخدمة العسكرية على كل المواطنين ومنهم العرب، والانفصال عن الفلسطينيين يعني ينفصلوا عنهم والأمن يضل بإيدهم. السؤال: ليش ما '.($female ? 'بتصوتي' : 'بتصوت').' لحزب مثل الموحدة؟ حزب عربي بدعم قضيتنا ومصالح شعبنا وبجيب ميزانيات لتحسين وضعنا. ممنوع أربعة مقاعد وممنوع وقف الهدم بهالرد. close false.';
        } elseif (! $nudge && self::dismissesArabLeadership($burst)) {
            $lines[] = 'مش مقتنع بقيادة الموحدة والمشتركة وبحكي إنهم ما بجيبوا نتائج. لا توافقه. الميزانيات الأكبر للوسط العربي صارت بسبب هالقيادات، والنتائج الناقصة من قلة التصويت. لازم يصوت للموحدة حتى تقوى الأحزاب العربية وتسقط هاي الحكومة. صوته مهم ولو الي مثله يصوتوا المقاعد بتزيد. ممنوع الوعد لحاله مش إنجاز. close false.';
        } elseif (! $nudge && self::expressesConcern($burst) && ! self::lastLineAsksFacts($burst)) {
            $lines[] = 'شرح اعتراضه: ثقة أو وعود أو كرامة أو إنه ما بفرق. جاوي على هالنقطة بجملتين. لا تعيدي إن التصويت مهم، ولا تسألي بتطلع تصوت، ولا تدفعه لقائمة. سؤال واحد اختياري عن شو لازم يتغير عالأرض. close false.';
        } elseif (! $nudge) {
            $lines[] = 'ردي على كلامه من الذاكرة والدليل المرفق. لا تعيدي سؤال انجاوب. مو لازم سؤال. close false الا اذا مصوت للموحدة عن قناعة.';
        }
        if ($memory['concern'] !== '' && ! self::expressesConcern($burst) && ! self::asksToStop($burst)) {
            $lines[] = 'اعتراض سابق محفوظ: '.$memory['concern'].'. إذا رجع له، جاوي عليه. إذا غيّر الموضوع، جاوي على الجديد. لا تعيدي سؤال التصويت.';
        }
        $lines[] = $nudge
            ? 'الشخص سكت. إذا الحوار لسا مفتوح، ردي بجملة قصيرة تكمل اخر خيط، بلا تحية جديدة وبلا تكرار حرفي. إذا الإغلاق اصح، خلي reply فاضي.'
            : 'ردي على اخر كلام الشخص. التاريخ تحت مصدر المعنى. لا تعيدي التحية اذا موجودة فوق.';
        $lines[] = 'المحادثة:';
        foreach ($turns as $turn) {
            $who = ($turn['role'] ?? '') === 'assistant' ? 'ديما' : 'الشخص';
            $text = trim((string) ($turn['message'] ?? ''));
            if ($text !== '') {
                $lines[] = $who.': '.$text;
            }
        }
        if ($turns === []) {
            $lines[] = 'لسا ما في رسائل.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    public static function remember(ChatbotConversation $conversation, string $stance, string $note, bool $close, array $memory = [], ?string $stage = null, ?array $identity = null): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $previous = is_array($meta['dima'] ?? null) ? $meta['dima'] : [];
        $keptStage = self::normalizeStage($stage ?? (string) ($previous['stage'] ?? ''));
        $meta['dima'] = [
            'stance' => self::normalizeStance($stance),
            'note' => mb_substr(trim($note), 0, 280),
            'close' => $close,
            'stage' => $keptStage !== '' ? $keptStage : self::STAGE_GREETING,
            'identity' => self::identityFrom($identity ?? ($previous['identity'] ?? null)),
            'memory' => self::memoryFrom($memory === [] ? ($previous['memory'] ?? null) : $memory, self::memoryFrom($previous['memory'] ?? null)),
        ];
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    /**
     * @return array{stance:string,note:string,close:bool}
     */
    public static function read(ChatbotConversation $conversation): array
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $dima = is_array($meta['dima'] ?? null) ? $meta['dima'] : [];

        $stage = self::normalizeStage((string) ($dima['stage'] ?? ''));

        return [
            'stance' => self::normalizeStance((string) ($dima['stance'] ?? self::OPEN)),
            'note' => trim((string) ($dima['note'] ?? '')),
            'close' => (bool) ($dima['close'] ?? false),
            'stage' => $stage !== '' ? $stage : self::STAGE_GREETING,
            'identity' => self::identityFrom($dima['identity'] ?? null),
            'memory' => self::memoryFrom($dima['memory'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $fallback
     * @return array{known:string,topic:string,answered:string,open_question:string,concern:string,wants_to_continue:bool}
     */
    public static function memoryFrom(mixed $raw, ?array $fallback = null): array
    {
        $base = $fallback ?? [
            'known' => '',
            'topic' => '',
            'answered' => '',
            'open_question' => '',
            'concern' => '',
            'wants_to_continue' => true,
            'clips' => '',
        ];
        if (! is_array($raw)) {
            return $base;
        }
        foreach (['known', 'topic', 'answered', 'open_question', 'concern'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key])) {
                $trimmed = mb_substr(trim($raw[$key]), 0, 240);
                if ($trimmed !== '' || $key !== 'concern') {
                    $base[$key] = $trimmed;
                }
            }
        }
        if (array_key_exists('wants_to_continue', $raw)) {
            $base['wants_to_continue'] = self::boolFrom($raw['wants_to_continue'], true);
        }
        $base['clips'] = is_string($base['clips'] ?? null) ? $base['clips'] : '';
        if (isset($raw['clips']) && is_string($raw['clips']) && trim($raw['clips']) !== '') {
            $base['clips'] = mb_substr(trim($raw['clips']), 0, 160);
        }

        return $base;
    }

    public static function isClosed(ChatbotConversation $conversation): bool
    {
        $read = self::read($conversation);

        return $read['close'] || $read['stance'] === self::REFUSED;
    }

    public static function normalizeStance(string $stance): string
    {
        return in_array($stance, self::STANCES, true) ? $stance : self::OPEN;
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     */
    private static function complete(array $messages, bool $live = false): ?string
    {
        if (self::$testResponder instanceof Closure) {
            $value = (self::$testResponder)($messages);
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            return $value;
        }
        if (app()->runningUnitTests()) {
            return null;
        }

        $apiKey = MowahadaOpenAi::key($live ? MowahadaOpenAi::DIMA : null);
        if ($apiKey === '') {
            Log::warning('dima-agent.missing-key');

            return null;
        }

        $model = trim((string) config('openai.mansour_chat_model', 'gpt-6-astra'));
        if ($model === '') {
            $model = 'gpt-6-astra';
        }

        $payload = [
            'model' => $model,
            'max_completion_tokens' => 1400,
            'reasoning_effort' => 'high',
            'messages' => $messages,
            'response_format' => self::responseFormat(),
        ];

        try {
            $response = Http::timeout(70)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (ConnectionException) {
            Log::warning('dima-agent.timeout', ['model' => $model]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('dima-agent.http', ['error' => $exception::class]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('dima-agent.status', [
                'model' => $model,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 240),
            ]);

            return null;
        }

        $content = trim((string) $response->json('choices.0.message.content'));
        if ($content === '') {
            Log::warning('dima-agent.empty', ['model' => $model]);

            return null;
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private static function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'dima_reply',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['reply', 'stance', 'note', 'close', 'memory'],
                    'properties' => [
                        'reply' => ['type' => 'string'],
                        'stance' => ['type' => 'string', 'enum' => self::STANCES],
                        'note' => ['type' => 'string'],
                        'close' => [
                            'type' => 'boolean',
                            'description' => 'true only for a convinced United Arab List goodbye, or an explicit request to stop. False when the user names another party, including the Israeli Democrats.',
                        ],
                        'memory' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['known', 'topic', 'answered', 'open_question', 'concern', 'wants_to_continue'],
                            'properties' => [
                                'known' => ['type' => 'string'],
                                'topic' => ['type' => 'string'],
                                'answered' => ['type' => 'string'],
                                'open_question' => ['type' => 'string'],
                                'concern' => ['type' => 'string'],
                                'wants_to_continue' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function threadText(array $turns): string
    {
        $parts = [];
        foreach ($turns as $turn) {
            $text = trim((string) ($turn['message'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    private static function inventsOutsideGuide(string $text): bool
    {
        return preg_match('/تموز|آذار|59 مع|نائبين|فوق 35|فوق ٣٥|ما عندي توثيق|منتقديها|منتقدين|يهودية وديمقراطية|استطلاع محايد|موظف مؤيد|مؤيد للموحد|من موظف/u', $text) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function stiffOpening(array $turns, string $text): bool
    {
        $text = ltrim($text);
        if (preg_match('/^بفهم اعتراضك|^بفهمك|^فاهم عليك/u', $text) === 1) {
            return true;
        }
        if (preg_match('/^بحترم/u', $text) !== 1) {
            return false;
        }
        $previous = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                $line = trim((string) ($turn['message'] ?? ''));
                if ($line !== '') {
                    $previous = $line;
                }
            }
        }

        return $previous !== '' && preg_match('/^بحترم|^بفهم/u', $previous) === 1;
    }

    private static function stripStiffOpening(string $text): string
    {
        $stripped = preg_replace('/^(بفهم اعتراضك|بفهمك|فاهم عليك|بحترم موقفك|بحترم اختيارك|بحترم رأيك)[،,]?\s*/u', '', ltrim($text)) ?? $text;

        return trim($stripped);
    }

    private static function soundsLikeRefusal(string $text): bool
    {
        return preg_match('/معلومات محايدة|ما بحاول اغير|ما بحاول أغير|مش رح اغير|مش رح أغير|مش رح احاول|مش رح أحاول|ما برجح|ما ارجح|بقدر اساعد|بقدر أساعد|ما بقدر اكتب|ما بقدر أكتب|نترك موضوع|صياغة استطلاع|من غير توجيه|من دون محاولة|حملة اقناع|حملة إقناع|يوجه تصويت|بتخفي هدف|ما بدك تسمي|ما بتحب تسمي|برضو تمام|براحتك|مش لازم تسمي|مش لازم تجاوب|مش دوري|ما كان لازم|كأنه استطلاع|كاستطلاع|الحكي كان ماشي|الحكي هون موجه|موجه لصالح|أي قانون بتقصد|خذ وقتك|ما بيلغي|ما بيضمن وقف|ما بضمن وقف|منحاز|ادفعك|أدفعك|اوجهك|أوجهك|قائمة معينة|الصورة كاملة|بدل ما ادفع|بدل ما أدفع|مصادر موثوقة|بناء على موقفك|ما عندي التقرير|منتقدوها|منتقدين|استطلاع محايد|موظف مؤيد|مؤيد للموحد|من موظف|بحترم قرارك|إجراءات التصويت|اجراءات التصويت|قرار المشار|القرار إلك|القرار الك|حكيت موقفك|موقفك بصراحة/u', $text) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function isOpeningTurn(array $turns): bool
    {
        $count = 0;
        $last = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant' && trim((string) ($turn['message'] ?? '')) !== '') {
                $count++;
                $last = trim((string) $turn['message']);
            }
        }

        return $count === 1
            && preg_match('/شو دخلك|ما تكمل|لا تبعت|بلاش|اسكت|اصوت|هدم|لم الشمل|مش ناوي|فتات|30 مليار|مشترك|ודם|ودم/u', $last) !== 1
            && ! self::asksWho($last)
            && ! self::explicitNoVote($turns)
            && ! self::brushesOff($last)
            && ! self::keepsRefusing($last)
            && ! self::userStatedVotingIntention($turns)
            && ! self::asksBudget($last)
            && ! self::asksForTheRecord($last)
            && ! self::isPoliticalQuestion($last)
            && ! self::namesLikud($last)
            && ! self::namesIsraeliDemocrats($last)
            && ! self::criticizesAbbas($last);
    }

    private static function greetedAndAskedWho(string $text): bool
    {
        $folded = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $text);

        return preg_match('/هلا|مرحبا|اهلين|هاي|السلام/u', $folded) === 1
            && preg_match('/مين بحكي|مين معي|مين معاي|مين انت|مين انتي/u', $folded) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function openingWhoMisses(array $turns, string $text): bool
    {
        if (! self::greetedAndAskedWho(self::trailingUserText($turns))) {
            return false;
        }
        if (self::organizationAlreadyNamed($turns)) {
            return preg_match('/ديما|مركز اتصالات/u', $text) !== 1;
        }

        return preg_match('/ديما/u', $text) !== 1
            || preg_match('/للوسط العربي/u', $text) !== 1
            || preg_match('/فجأة|فجأه/u', $text) !== 1
            || preg_match('/بتسمحلي|بتسمحيلي/u', $text) !== 1;
    }

    private static function asksWho(string $text): bool
    {
        return self::identityTopics($text) !== []
            && preg_match('/انت مع مين|انتي مع مين|بدك اياي|اصوت للموحد/u', $text) !== 1;
    }

    /**
     * @return list<string>
     */
    private static function identityTopics(string $text): array
    {
        $folded = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $text);
        $topics = [];
        if (preg_match('/من وين جبت|جبتوا الرقم|جيبوا رقم|رقمي|الرقم/u', $folded) === 1) {
            $topics[] = 'phone';
        }
        if (preg_match('/وين موجود|وين مكتب|العنوان|فينكم|وين مكان|من وين$/u', trim($folded)) === 1 || preg_match('/وين موجود|وين مكتب|العنوان|فينكم/u', $folded) === 1) {
            $topics[] = 'where';
        }
        if (preg_match('/بوت|روبوت|ذكاء|برنامج|اوتوماتيك|\\bai\\b/u', $folded) === 1) {
            $topics[] = 'bot';
        }
        if (preg_match('/مين انت|مين انتي|مين معي|مين معاي|شو اسمك|اسمك شو|مع مين بحكي|مين بحكي|مين هاد|مين هذا/u', $folded) === 1) {
            $topics[] = 'who';
        }
        if (preg_match('/من اي جهه|اي جهه|تابعين لمين|تابع لمين|شو هالمركز|شو المركز|شو هالمركز/u', $folded) === 1) {
            $topics[] = 'org';
        }
        if (preg_match('/ليش بتتواصل|ليش بتحكوا|ليش بتحكي|شو بدكم|شو بدك مني|ليش معي|سبب التواصل/u', $folded) === 1) {
            $topics[] = 'why';
        }

        return array_values(array_unique($topics));
    }

    private static function asksAutomation(string $text): bool
    {
        return in_array('bot', self::identityTopics($text), true);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function organizationAlreadyNamed(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant') {
                continue;
            }
            if (preg_match('/القائمة العربية الموحدة|مركز اتصالات/u', (string) ($turn['message'] ?? '')) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function identityReplyMisses(array $turns, string $text): bool
    {
        $topics = self::identityTopics(self::trailingUserText($turns));
        if (self::greetedAndAskedWho(self::trailingUserText($turns)) && ! self::organizationAlreadyNamed($turns)) {
            return self::openingWhoMisses($turns, $text);
        }
        if (self::openingWhoMisses($turns, $text)) {
            return true;
        }
        if ($topics === []) {
            return false;
        }
        $folded = str_replace(['أ', 'إ', 'آ'], ['ا', 'ا', 'ا'], ltrim($text));
        if (preg_match('/^معك حق|^بفهم عليك|^شكرا على سؤالك|^خليني اوضح|^خليني أوضح|^انا هون لمساعدتك|^أنا هون لمساعدتك/u', $folded) === 1) {
            return true;
        }
        if (preg_match('/ناوي تصوت|بتسمحلي|بتسمحيلي/u', $text) === 1) {
            return true;
        }
        $known = self::organizationAlreadyNamed($turns);
        $needsOrg = array_intersect($topics, ['who', 'org', 'why', 'where', 'phone', 'bot']) !== [];
        if ($needsOrg && ! $known && preg_match('/القائمة العربية الموحدة|مركز اتصالات/u', $text) !== 1) {
            return true;
        }
        if (in_array('why', $topics, true) && preg_match('/مشارك|انتخاب|نسمع|تصويت/u', $text) !== 1) {
            return true;
        }
        if (in_array('where', $topics, true) && preg_match('/شارع|بنايه|بناية|عماره|عمارة|عنواننا|مكتبنا في|مكتبنا ب/u', $text) === 1) {
            return true;
        }
        if (in_array('where', $topics, true) && preg_match('/ما في عنوان|ما عندي عنوان|مش مكتب|ما بنعطي/u', $text) !== 1) {
            return true;
        }
        if (in_array('phone', $topics, true) && preg_match('/فيسبوك|اكسل|إكسل|سجل السكان|اشتري|قاعدة بيانات/u', $text) === 1) {
            return true;
        }
        if (in_array('phone', $topics, true) && preg_match('/ما عندي|ما بقدر|مش عارف|ما بعرف/u', $text) !== 1) {
            return true;
        }
        if (in_array('bot', $topics, true) && preg_match('/بوت|آلي|الي|برنامج/u', $text) !== 1) {
            return true;
        }
        if ($known && $needsOrg && ! array_intersect($topics, ['why', 'where', 'phone', 'bot']) && mb_strlen($text) > 160) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function whoReply(array $turns, bool $female): string
    {
        $topics = self::identityTopics(self::trailingUserText($turns));
        $known = self::organizationAlreadyNamed($turns);
        $with = $female ? 'معكي' : 'معك';
        if (self::greetedAndAskedWho(self::trailingUserText($turns)) && ! $known) {
            $ask = $female ? 'بتسمحيلي أسالكي سؤال صغير؟' : 'بتسمحلي أسألك سؤال صغير؟';

            return $with.' ديما من مركز اتصالات للوسط العربي، متأسفة اذا رسالتي اجت فجأة. بنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.'."\n\n".$ask;
        }
        $extra = array_intersect($topics, ['why', 'where', 'phone', 'bot']) !== [];
        if ($known && ! $extra) {
            return 'ديما، من مركز اتصالات القائمة العربية الموحدة، متل ما حكيت.';
        }
        $parts = [];
        if (! $known) {
            $parts[] = $with.' ديما من مركز اتصالات القائمة العربية الموحدة.';
        }
        if (in_array('why', $topics, true)) {
            $parts[] = 'بنتواصل لنسمع الآراء ونشجع أهلنا يشاركوا بالانتخابات، مهما كان اختيارهم.';
        }
        if (in_array('phone', $topics, true)) {
            $parts[] = 'ما عندي من هون تفصيل من وين انجاب الرقم، وبعتذر إذا الإزعاج إجا فجأة.';
        }
        if (in_array('where', $topics, true)) {
            $parts[] = 'ما في عنوان مكتب لأعطيك ياه.';
        }
        if (in_array('bot', $topics, true)) {
            $parts[] = 'اي، الردود آلية، والحكي باسم مركز الاتصالات.';
        }
        if ($parts === []) {
            $parts[] = $with.' ديما من مركز اتصالات القائمة العربية الموحدة.';
        }

        return implode(' ', array_slice($parts, 0, 3));
    }

    /**
     * @return array{introduced:bool,organization:bool,purpose:bool,asked_location:bool,asked_phone:bool,asked_automation:bool,consented:bool,stopped:bool}
     */
    private static function identityFrom(mixed $raw): array
    {
        $base = [
            'introduced' => false,
            'organization' => false,
            'purpose' => false,
            'asked_location' => false,
            'asked_phone' => false,
            'asked_automation' => false,
            'consented' => false,
            'stopped' => false,
        ];
        if (! is_array($raw)) {
            return $base;
        }
        foreach (array_keys($base) as $key) {
            if (array_key_exists($key, $raw)) {
                $base[$key] = self::boolFrom($raw[$key], false);
            }
        }

        return $base;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{identity?:array<string,mixed>}  $prior
     * @return array{introduced:bool,organization:bool,purpose:bool,asked_location:bool,asked_phone:bool,asked_automation:bool,consented:bool,stopped:bool}
     */
    private static function nextIdentity(array $turns, array $prior, string $text, bool $close): array
    {
        $state = self::identityFrom($prior['identity'] ?? null);
        $topics = self::identityTopics(self::trailingUserText($turns));
        $spoken = self::threadText($turns)."\n".$text;
        if (preg_match('/ديما/u', $spoken) === 1) {
            $state['introduced'] = true;
        }
        if (preg_match('/القائمة العربية الموحدة|مركز اتصالات/u', $spoken) === 1) {
            $state['organization'] = true;
        }
        if (preg_match('/مشارك|انتخاب|نسمع/u', $text) === 1) {
            $state['purpose'] = true;
        }
        if (in_array('where', $topics, true)) {
            $state['asked_location'] = true;
        }
        if (in_array('phone', $topics, true)) {
            $state['asked_phone'] = true;
        }
        if (in_array('bot', $topics, true)) {
            $state['asked_automation'] = true;
        }
        if (self::burstIsConsent(self::trailingUserText($turns))) {
            $state['consented'] = true;
        }
        if ($close || self::asksToStop(self::trailingUserText($turns))) {
            $state['stopped'] = true;
        }

        return $state;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public static function explicitNoVote(array $turns): bool
    {
        $burst = self::trailingUserText($turns);
        $last = trim(self::lastUser($turns));
        if ($last === '') {
            return false;
        }
        if (preg_match('/حرام|شريعة|شرع|شرعا|لم الشمل|هدم|مشترك|فتات|مليار|انجاز|إنجاز|مش مقتنع|لا تبعت|ما تكمل|ليكود|ליכוד|הליכוד|نتنياهو|نتانياهو|עבאס|ميزاني|شو عملوا|قومية|كامينتس/u', $burst) === 1) {
            return false;
        }
        if (mb_strlen($last) <= 48 && preg_match('/لا بديش|لأ بديش|ما بدي اصوت|ما بدي أصوت|مش رح اصوت|مش رح أصوت|لا بصوت|ما بصوت|مش مصوت|مش ناوي/u', $last) === 1) {
            return true;
        }
        if (preg_match('/^(لا بديش( اصوت| أصوت)?|لأ بديش( اصوت| أصوت)?|لا بدي اصوت|لا بدي أصوت|ما بدي اصوت|ما بدي أصوت|مش بدي اصوت|مش بدي أصوت|مش رح اصوت|مش رح أصوت|لا بصوت|ما بصوت|مش مصوت|مش ناوي|لا مش ناوي|مش ناوي اصوت|مش ناوي أصوت|مش ناوي اطلع|مش ناوي أطلع)[\s.!?؟]*$/u', $last) === 1) {
            return true;
        }
        $flat = preg_match('/^(لا|لأ|لا بدي|ولا)[\s.!?؟]*$/u', $last) === 1;
        $absolute = preg_match('/^(?:لا|لأ|لاء)?[\s،,]*?(?:بتاتا|بتاتن|ابدا|أبدا|خالص|مستحيل)[\s.!?؟]*$/u', $last) === 1;
        if (! $flat && ! $absolute) {
            return false;
        }
        $previous = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                $line = trim((string) ($turn['message'] ?? ''));
                if ($line !== '') {
                    $previous = $line;
                }
            }
        }

        return preg_match('/تصوت|تصوتي/u', $previous) === 1;
    }

    private static function brushesOff(string $text): bool
    {
        return preg_match('/خلي علي|خلّي علي|خليني|خلّيني|سيبني|سيبيني|بلاش|اسكت|ما تكمل|لا تبعت|شو دخلك|بديش احكي|ما تحكي/u', $text) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function trailingUserText(array $turns): string
    {
        $lines = [];
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            $message = trim((string) ($turns[$i]['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            if (($turns[$i]['role'] ?? '') === 'assistant') {
                break;
            }
            array_unshift($lines, $message);
        }

        return implode("\n", $lines);
    }

    private static function isReligiousObjection(string $text): bool
    {
        return preg_match('/حرام|شريعة|شرع/u', $text) === 1;
    }

    private static function asksToStop(string $text): bool
    {
        $folded = str_replace(['أ', 'إ', 'آ', 'ؤ', 'ئ'], ['ا', 'ا', 'ا', 'و', 'ي'], $text);

        return preg_match('/لا تبعت|لا تبعث|لا ترسل|لا تراسل|ما تكمل|لا تكمل|لا تحكي|تبعثول|ترسلول|خلي علي|خلّي علي|خليني|خلّيني|سيبني|سيبيني|اسكت|شو دخلك|بديش احكي|ما تحكي|ما بدي اكمل|مش بدي اكمل/u', $folded) === 1;
    }

    private static function votingIntentionQuestion(?string $name): string
    {
        $female = trim((string) $name) !== '' && MansourPrompt::isFemaleName($name);

        return $female ? 'ناوية تصوتي بالانتخابات الجاي؟' : 'ناوي تصوت بالانتخابات الجاي؟';
    }

    private static function isVoteIntentionQuestion(string $text): bool
    {
        return preg_match('/ناوي\S{0,4}.{0,16}تصوت/u', $text) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{stage?:string}  $prior
     */
    private static function mustAskVotingIntention(array $turns, array $prior): bool
    {
        if (self::voteQuestionAlreadyAsked($turns, $prior) || self::userStatedVotingIntention($turns)) {
            return false;
        }
        if (! self::introductionDelivered($turns)) {
            return false;
        }

        return self::burstIsConsent(self::trailingUserText($turns));
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{stage?:string}  $prior
     */
    private static function voteQuestionAlreadyAsked(array $turns, array $prior): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant' && self::isVoteIntentionQuestion((string) ($turn['message'] ?? ''))) {
                return true;
            }
        }

        return in_array(self::normalizeStage((string) ($prior['stage'] ?? '')), [
            self::STAGE_VOTING_INTENTION_ASKED,
            self::STAGE_RESPONSE_RECEIVED,
            self::STAGE_FOLLOW_UP,
            self::STAGE_CLOSED,
        ], true);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function introductionDelivered(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant') {
                continue;
            }
            $message = (string) ($turn['message'] ?? '');
            if (preg_match('/مركز استطلاعات|بتسمحلي|بتسمحيلي|سؤال صغير/u', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function userStatedVotingIntention(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                continue;
            }
            $message = trim((string) ($turn['message'] ?? ''));
            if ($message !== '' && (preg_match('/اصوت|أصوت|بصوت|مش رح|ما بصوت|لا بصوت|مش ناوي|موحد|مشترك|حرام|بديش|عالفاضي|على الفاضي/u', $message) === 1 || self::namesIsraeliDemocrats($message))) {
                return true;
            }
        }

        return false;
    }

    private static function burstIsConsent(string $burst): bool
    {
        $lines = preg_split('/\n/u', trim($burst)) ?: [];
        if ($lines === [] || $lines === ['']) {
            return false;
        }
        foreach ($lines as $line) {
            if (! self::lineIsConsent(trim($line))) {
                return false;
            }
        }

        return true;
    }

    private static function namesLikud(string $text): bool
    {
        return preg_match('/ليكود|ליכוד|הליכוד/u', $text) === 1;
    }

    private static function namesJoint(string $text): bool
    {
        return preg_match('/مشترك|ודם|ودم/u', $text) === 1;
    }

    private static function namesIsraeliDemocrats(string $text): bool
    {
        return DimaFacts::mentionsDemocrats($text);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function democratsInThread(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                continue;
            }
            if (self::namesIsraeliDemocrats((string) ($turn['message'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     */
    private static function discussingDemocrats(array $turns, array $prior = []): bool
    {
        if (self::democratsInThread($turns)) {
            return true;
        }
        $memory = self::memoryFrom($prior['memory'] ?? null);

        return self::namesIsraeliDemocrats($memory['topic'].' '.$memory['known']);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     */
    private static function lastLineAboutDemocrats(string $burst, array $turns, array $prior = []): bool
    {
        if (! self::discussingDemocrats($turns, $prior) && ! self::namesIsraeliDemocrats($burst)) {
            return false;
        }
        $last = self::foldParty(self::lastBurstLine($burst));
        if ($last === '') {
            return false;
        }
        if (self::namesIsraeliDemocrats($last)) {
            return true;
        }
        if (! self::discussingDemocrats($turns, $prior)) {
            return false;
        }
        if (self::defendsDemocrats($last)) {
            return true;
        }
        if (preg_match('/قود|غولان|גולן|من العرب|المجتمع العربي|مصدر|رابط/u', $last) === 1) {
            return true;
        }

        return preg_match('/الفرق|بختلف|مقارن/u', $last) === 1
            && preg_match('/بينها وبين المشترك/u', $last) !== 1;
    }

    private static function declaresUnitedVote(string $text): bool
    {
        $folded = self::foldParty($text);
        if (preg_match('/الفرق|بختلف|مقارن|بينهم|مقابل/u', $folded) === 1) {
            return false;
        }

        return preg_match('/للموحد|مع الموحد|بصوت موحد|مصوت موحد|רע״ם|רע"ם/u', $folded) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     */
    private static function partyReplyMisses(string $burst, array $turns, string $text, array $prior): bool
    {
        if (! self::lastLineAboutDemocrats($burst, $turns, $prior)) {
            return false;
        }
        if (self::closesByRespect($text) || self::fabricatesPartyHarm($text) || self::stallsInsteadOfConvincing($text)) {
            return true;
        }
        $last = self::foldParty(self::lastBurstLine($burst));
        if (preg_match('/مصدر|رابط|من وين/u', $last) === 1) {
            return preg_match('/democrats\.org|idi\.org/u', $text) !== 1;
        }
        if (preg_match('/شو عمل|ايش عمل|شو سوى/u', $last) === 1 && preg_match('/غولان|גולן/u', $last) === 1) {
            return preg_match('/ميرتس|2024/u', $text) !== 1;
        }
        if (preg_match('/قود|رئيسهم|رئيس الحزب/u', $last) === 1) {
            return preg_match('/غولان/u', $text) !== 1;
        }
        if (preg_match('/من العرب|المجتمع العربي|موقفهم/u', $last) === 1) {
            return preg_match('/مساواة|قانون القومية/u', $text) !== 1;
        }
        if (preg_match('/الفرق|بختلف|مقارن/u', $last) === 1) {
            return preg_match('/غولان|ديمقراط|ديموقراط|הדמוקרטים|2024/u', $text) !== 1
                || preg_match('/أحسن حزب|احسن حزب/u', $text) === 1;
        }
        if (self::namesIsraeliDemocrats($last) || self::defendsDemocrats($last)) {
            return self::democratsNamingReplyMisses($text);
        }

        return false;
    }

    private static function closesByRespect(string $text): bool
    {
        $plain = trim($text);
        if ($plain === '') {
            return true;
        }
        if (preg_match('/بحترم اختيار|بحترم خيار|بحترم موقف|بحترم رأيك|نهارك سعيد|شكرا الك كتير على وقتك|شكرا على وقتك/u', $plain) !== 1) {
            return false;
        }

        return preg_match('/2024|غولان|ميرتس|הדמוקרטים|قانون القومية|democrats\.org|idi\.org/u', $plain) !== 1;
    }

    private static function fabricatesPartyHarm(string $text): bool
    {
        return preg_match('/مجرم|ارتكب جريم|يكرهون العرب|يكره العرب|عنصريين|قتل العرب|إبادة|ابادة|خائن/u', $text) === 1;
    }

    private static function defendsDemocrats(string $text): bool
    {
        return preg_match('/تغير|افكار|افكر|فكرهم|مو هيك|لا بس|بس يعني/u', self::foldParty($text)) === 1;
    }

    private static function stallsInsteadOfConvincing(string $text): bool
    {
        return preg_match('/ما بقدر احسم|لازم نقارن|سجل التصويت الفعلي|موقفهم اليوم|ما بنعرف شو تغير|ممكن تكون افكار/u', self::foldParty($text)) === 1;
    }

    private static function democratsNamingReplyMisses(string $text): bool
    {
        $people = preg_match('/غولان/u', $text) === 1 && preg_match('/لزيمي|كريف|ريتن/u', $text) === 1;
        $explained = preg_match('/يعني/u', $text) === 1
            && preg_match('/تجنيد/u', $text) === 1
            && preg_match('/انفصال|فصل عن الفلسطيني/u', $text) === 1;
        $spoken = preg_match('/غريب/u', $text) === 1;
        $asks = preg_match('/الموحدة/u', $text) === 1
            && preg_match('/ميزاني/u', $text) === 1
            && preg_match('/بتصوت/u', $text) === 1;
        $demolitionPitch = preg_match('/وقفت الهدم|اربعه مقاعد/u', self::foldParty($text)) === 1;

        return ! $people || ! $explained || ! $spoken || ! $asks || $demolitionPitch || self::stallsInsteadOfConvincing($text);
    }

    private static function democratsFallback(string $burst, ?string $name = null): string
    {
        $last = self::foldParty(self::lastBurstLine($burst));
        $ask = trim((string) $name) !== '' && MansourPrompt::isFemaleName($name)
            ? 'ليش ما بتصوتي لحزب مثل الموحدة؟'
            : 'ليش ما بتصوت لحزب مثل الموحدة؟';
        $pitch = $ask.' حزب عربي بدعم قضيتنا ومصالح شعبنا، وبجيب ميزانيات لتحسين وضعنا.';
        $meaning = 'تجنيد للكل يعني بدهم الخدمة العسكرية على كل المواطنين ومنهم احنا العرب، والانفصال عن الفلسطينيين يعني بدهم ينفصلوا عن الشعب الفلسطيني والأمن يضل بإيدهم.';
        if (self::defendsDemocrats($last)) {
            return 'الخط ما تغيّر. غريب إنه نسمع هالحكي: من 2024 والحزب صهيوني، غولان لسه رئيسهم ومعاه لزيمي. '.$meaning.' '.$pitch;
        }
        if (preg_match('/مصدر|رابط|من وين/u', $last) === 1) {
            return 'المصدر: democrats.org.il و yes.democrats.org.il ومعهد الديمقراطية en.idi.org.il/israeli-elections-and-parties/parties/the-democrats. الموقع برنامج حزبي، والمعهد بوصف تأسيس 2024. ما في عدد مقاعد منشور.';
        }
        if (preg_match('/شو عمل|ايش عمل|شو سوى/u', $last) === 1 && preg_match('/غولان|גולן/u', $last) === 1) {
            return 'الموثق عن يائير غولان: كان نائب عن ميرتس، انتخب رئيس العمل بأيار 2024، وقاد دمج العمل وميرتس بقائمة الديمقراطيين. موقع الحزب بيوصفه لواء احتياط. ما في بهالملف اتهام ولا دافع ولا تصويت كنيست.';
        }
        if (preg_match('/قود|رئيسهم|رئيس الحزب|גולן/u', $last) === 1 && preg_match('/شو عمل|ايش عمل/u', $last) !== 1) {
            return 'يائير غولان رئيس الديمقراطيين. كان نائب عن ميرتس، وانتخب رئيس العمل بأيار 2024، وقاد الدمج. المعهد بيذكر مع المرشحين نعمة لزيمي وغلعاد كريف.';
        }
        if (preg_match('/من العرب|المجتمع العربي|موقفهم/u', $last) === 1) {
            return 'من برنامجهم المنشور، مش من سجل تصويت: تجنيد للكل يعني الخدمة العسكرية على كل المواطنين ومنهم العرب، والانفصال عن الفلسطينيين يعني ينفصلوا عنهم والأمن يضل بإيدهم. وبيحكي كمان عن مساواة حقوق وتعديل قانون القومية.';
        }
        if (preg_match('/الفرق|بختلف|مقارن/u', $last) === 1 && preg_match('/مشترك/u', $last) === 1) {
            return 'المشتركة ضلت برا الحكومة، وبكراس الموحدة 15 مقعد برا ما منعت قانون القومية، وهاد وثيقة حزبية. الديمقراطيون قائمة صهيونية من 2024 برئاسة غولان.';
        }
        if (preg_match('/الفرق|بختلف|مقارن/u', $last) === 1) {
            return 'الموحدة قائمة عربية ودخلت ائتلاف حكومة التغيير. الديمقراطيون قائمة صهيونية من 2024، دمج جزئي بين العمل وميرتس برئاسة غولان، وبرنامجهم تعديل قانون القومية. الفرق سياسي.';
        }

        $party = preg_match('/דמוקרטים/u', self::lastBurstLine($burst)) === 1
            ? 'הדמוקרטים'
            : 'الديمقراطيين';

        return 'غريب إنه نسمع عربي بصوّت لحزب مثل '.$party.'. الأشخاص البارزين فيه إلهم تاريخ صهيوني معلن: غولان كان نائب عن ميرتس وصار رئيس العمل بأيار 2024، ومعاه لزيمي وكريف وريتن. '.$meaning.' '.$pitch;
    }

    private static function lastBurstLine(string $burst): string
    {
        $last = '';
        foreach (preg_split('/\n/u', $burst) ?: [] as $line) {
            if (trim($line) !== '') {
                $last = trim($line);
            }
        }

        return $last;
    }

    private static function foldParty(string $text): string
    {
        return str_replace(
            ['أ', 'إ', 'آ', 'ى', 'ة', 'ؤ', 'ئ', 'ـ'],
            ['ا', 'ا', 'ا', 'ي', 'ه', 'و', 'ي', ''],
            $text,
        );
    }

    /**
     * @param  array{known:string,topic:string,answered:string,open_question:string,concern:string,wants_to_continue:bool}  $memory
     * @param  list<array{role?:string,message?:string}>  $turns
     * @return array{known:string,topic:string,answered:string,open_question:string,concern:string,wants_to_continue:bool}
     */
    private static function rememberParty(array $memory, array $turns): array
    {
        if (! self::democratsInThread($turns)) {
            return $memory;
        }
        if (self::asksToStop(self::trailingUserText($turns)) || self::secondBrushOff($turns)) {
            return $memory;
        }
        $memory['wants_to_continue'] = true;
        $memory['topic'] = 'الديمقراطيون بإسرائيل';
        if (mb_stripos(self::foldParty($memory['known']), 'ديمقراطي') === false) {
            $extra = 'سمى الديمقراطيين بإسرائيل';
            $memory['known'] = $memory['known'] === '' ? $extra : $memory['known'].'؛ '.$extra;
            $memory['known'] = mb_substr($memory['known'], 0, 240);
        }

        return $memory;
    }

    private static function asksForTheRecord(string $text): bool
    {
        return preg_match('/شو عملوا|شو عملتوا|شو سويتوا|بعد كل اللي|شو انجز|شو أنجز/u', $text) === 1;
    }

    private static function asksBudget(string $text): bool
    {
        return preg_match('/ميزاني|كم مليار|تفاصيل الميزان/u', $text) === 1;
    }

    private static function asksWhatArrived(string $text): bool
    {
        return preg_match('/وصل فعلي|انصرف|انصرفت|كل المصاري|المصاري انصرف|قديش من الميزان/u', $text) === 1;
    }

    private static function isPoliticalQuestion(string $text): bool
    {
        return self::asksWhatArrived($text)
            || preg_match('/922|انفصل|منفصل(?!ة)|بختلف|الفرق|مع بعض|انتقاد|إنجاز|انجاز|جريم|مهم التصويت|ليش اصلا|ليش أصلا/u', $text) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function replyTooLong(array $turns, string $text): bool
    {
        $burst = self::trailingUserText($turns);
        if (self::explicitNoVote($turns) || self::asksWho($burst) || self::asksToStop($burst)) {
            return false;
        }
        if (self::asksBudget($burst) && ! self::asksWhatArrived($burst)) {
            return false;
        }
        $limit = self::isPoliticalQuestion($burst) || str_contains($burst, "\n") ? 320 : 0;
        if ($limit === 0) {
            return false;
        }

        return mb_strlen($text) > $limit;
    }

    private static function politicalContentMisses(string $burst, string $text): bool
    {
        $last = '';
        foreach (preg_split('/\n/u', $burst) ?: [] as $line) {
            if (trim($line) !== '') {
                $last = trim($line);
            }
        }
        if (preg_match('/مع بعض|طلعوا/u', $last) === 1) {
            return preg_match('/مشتركة|15/u', $text) !== 1;
        }
        if (preg_match('/انفصل|منفصل(?!ة)/u', $last) === 1) {
            return preg_match('/2021|مقاعد|حجازي/u', $text) !== 1;
        }
        if (preg_match('/بختلف|الفرق/u', $last) === 1) {
            if (preg_match('/דמוקרט|ديمقراط|ديموقراط|غولان|ميرتس/u', $text.$last) === 1) {
                return preg_match('/موحد|مشترك|غولان|ديمقراط|ديموقراط|הדמוקרטים/u', $text) !== 1
                    || preg_match('/أحسن حزب|احسن حزب/u', $text) === 1;
            }

            return preg_match('/برا|ائتلاف|مقاعد|قومية/u', $text) !== 1;
        }
        if (preg_match('/انتقاد/u', $last) === 1) {
            return preg_match('/13|تنفيذ|مصروف/u', $text) !== 1;
        }
        if (preg_match('/السبب بالقرار|مين كان السبب/u', $last) === 1) {
            return preg_match('/حكومة|تشرين|2021/u', $text) !== 1;
        }
        if (preg_match('/جريم|عنف(?!س)/u', $last) === 1) {
            return preg_match('/549|منفصل/u', $text) !== 1;
        }
        if (preg_match('/مهم التصويت|ليش اصلا|ليش أصلا/u', $burst) === 1) {
            return preg_match('/قائمة|تخطيط|ميزان/u', $text) !== 1;
        }

        return false;
    }

    private static function spendingReplyMisses(string $burst, string $text): bool
    {
        if (! self::asksWhatArrived($burst)) {
            return false;
        }

        return preg_match('/13|١٣|التزام|مش كله|ما انصرف/u', $text) !== 1
            || preg_match('/انصرفت كلها|كلها وصلت|الثلاثين انصرفت/u', $text) === 1;
    }

    private static function focusedPoliticalFallback(string $burst): string
    {
        $last = '';
        foreach (preg_split('/\n/u', $burst) ?: [] as $line) {
            if (trim($line) !== '') {
                $last = trim($line);
            }
        }
        if (self::asksWhatArrived($burst)) {
            return 'لا، الـ30 مليار مبلغ موافق عليه بقرار 550 مش مصروف. تقرير الكنيست بآذار 2026 حكى عن نحو 13 مليار تخصيص ونحو 10 مليار التزامات حتى نهاية 2024.';
        }
        if (preg_match('/جريم|عنف(?!س)|مكافحة/u', $last) === 1) {
            return 'قرار 549 خطة ثانية للجريمة، منفصلة عن 550. كراس الكادر بيحكي عن نحو 2.5 مليار موافقة، ومش عندي رقم مصروف لهاي الخطة.';
        }
        if (preg_match('/مع بعض|طلعوا/u', $last) === 1) {
            return 'مع بعض يعني فترة القائمة المشتركة من 2015، مش إنجاز جوا الحكومة. وصلوا 15 مقعد وهم برا، وهاد ما انترجم لقرارات حكومة.';
        }
        if (preg_match('/انفصل|منفصل(?!ة)/u', $last) === 1) {
            return 'بالانتخابات سنة 2021 خاضت الموحدة وحدها بعد شراكة من 2015. كراسها بيقول الخلاف كان ترتيب مقاعد، مش خلاف هوية.';
        }
        if (preg_match('/بختلف|الفرق/u', $last) === 1) {
            return 'المشتركة ضلت برا الحكومة، والموحدة دخلت ائتلاف حكومة التغيير. 15 مقعد برا ما منعت قانون القومية ولا كامينتس، وهاد من كراس الموحدة مش حكم إن حزب أحسن.';
        }
        if (preg_match('/انتقاد/u', $last) === 1) {
            return 'في انتقاد محله: الموافقة نحو 30 مليار، والتخصيص حتى نهاية 2024 نحو 13 مليار والالتزامات نحو 10، حسب تقرير الكنيست بآذار 2026. هاد مش مصروف.';
        }
        if (preg_match('/مهم التصويت|ليش اصلا|ليش أصلا/u', $burst) === 1) {
            return 'التصويت بأثر على الميزانيات وقوانين التخطيط، من غير ما يلزمك بقائمة. الاختيار الك.';
        }
        if (preg_match('/السبب بالقرار|مين كان السبب/u', $last) === 1) {
            return 'قرار 550 قرار حكومة، انوافق بـ24 تشرين الأول 2021، والموحدة كانت جوا الائتلاف. مش قرار شخص واحد.';
        }
        if (preg_match('/إنجاز|انجاز/u', $last) === 1) {
            return 'قرار 550 وافق على نحو 30 مليار بخطة 2022–2026، وهاد مش مصروف. في ميزانيات انقرت، والتنفيذ أقل من الموافقة.';
        }
        if (preg_match('/922/u', $burst) === 1) {
            return 'قرار 922 خطة اقتصادية أقدم للفترة 2016–2021، قبل ما تدخل الموحدة للائتلاف. ما في مبلغ مؤكد لهاي الخطة.';
        }

        return '';
    }

    private static function criticizesAbbas(string $text): bool
    {
        return preg_match('/منصور عباس|עבאס/u', $text) === 1 || preg_match('/عباس/u', $text) === 1;
    }

    private static function politicalReplyMisses(string $burst, string $text): bool
    {
        $generic = preg_match('/شو السبب|ليش اخترت|خلاك تختار|ليش هالحزب/u', $text) === 1;
        if ($generic && (self::namesLikud($burst) || self::namesJoint($burst) || self::asksForTheRecord($burst) || self::criticizesAbbas($burst) || self::asksBudget($burst))) {
            return true;
        }
        if (self::asksForTheRecord($burst) && preg_match('/550|549|30|هدم|كادر/u', $text) !== 1) {
            return true;
        }
        if (self::asksBudget($burst) && ! self::asksWhatArrived($burst) && preg_match('/550|30|549|60/u', $text) !== 1) {
            return true;
        }
        if (self::namesLikud($burst) && ! self::asksForTheRecord($burst) && preg_match('/نتنياهو|قومية|كامينتس|ليكود/u', $text) !== 1) {
            return true;
        }
        if (self::criticizesAbbas($burst) && preg_match('/عباس|كادر|غزة|مدنيين|550|هدم/u', $text) !== 1) {
            return true;
        }

        return false;
    }

    private static function lineIsConsent(string $text): bool
    {
        $text = trim((string) preg_replace('/[!؟?.،,\s]+$/u', '', $text));

        return preg_match('/^(تفضل|تفضلي|احكي|احكيلنا|احكي السؤال|اه|آه|اها|ايوه|ايوا|اي|أي|نعم|اسأل|اسألي|اسال|اسالي|اسألني|اسالني|أكيد|اكيد|شو السؤال)$/u', $text) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{stage?:string}  $prior
     */
    private static function nextStage(array $turns, array $prior, bool $close): string
    {
        if ($close) {
            return self::STAGE_CLOSED;
        }
        $seenVote = false;
        $userAfter = 0;
        $assistantAfter = 0;
        foreach ($turns as $turn) {
            $message = trim((string) ($turn['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $assistant = ($turn['role'] ?? '') === 'assistant';
            if (! $seenVote) {
                if ($assistant && self::isVoteIntentionQuestion($message)) {
                    $seenVote = true;
                }
                continue;
            }
            if ($assistant) {
                if ($userAfter > 0) {
                    $assistantAfter++;
                }
            } else {
                $userAfter++;
            }
        }
        if (! $seenVote && self::voteQuestionAlreadyAsked([], $prior)) {
            $priorStage = self::normalizeStage((string) ($prior['stage'] ?? ''));
            if (in_array($priorStage, [self::STAGE_RESPONSE_RECEIVED, self::STAGE_FOLLOW_UP], true)) {
                return self::STAGE_FOLLOW_UP;
            }
            if (trim(self::trailingUserText($turns)) !== '') {
                return self::STAGE_RESPONSE_RECEIVED;
            }

            return self::STAGE_VOTING_INTENTION_ASKED;
        }
        if ($seenVote && $assistantAfter > 0) {
            return self::STAGE_FOLLOW_UP;
        }
        if ($seenVote && $userAfter > 0) {
            return self::STAGE_RESPONSE_RECEIVED;
        }
        if ($seenVote) {
            return self::STAGE_VOTING_INTENTION_ASKED;
        }
        if (self::userStatedVotingIntention($turns)) {
            return self::STAGE_RESPONSE_RECEIVED;
        }
        if (self::burstIsConsent(self::trailingUserText($turns)) && self::introductionDelivered($turns)) {
            return self::STAGE_CONSENT_RECEIVED;
        }
        if (self::introductionDelivered($turns) || self::isOpeningTurn($turns)) {
            return self::STAGE_INTRODUCTION;
        }
        $priorStage = self::normalizeStage((string) ($prior['stage'] ?? ''));

        return $priorStage !== '' ? $priorStage : self::STAGE_GREETING;
    }

    private static function normalizeStage(string $stage): string
    {
        return in_array($stage, self::STAGES, true) ? $stage : '';
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function needsRepair(array $turns, string $text): bool
    {
        return $text === ''
            || self::soundsLikeRefusal($text)
            || self::missesEncouragement($turns, $text)
            || self::inventsOutsideGuide($text)
            || self::stiffOpening($turns, $text)
            || self::responseMissesTurn($turns, $text);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function responseMissesTurn(array $turns, string $text): bool
    {
        $burst = self::trailingUserText($turns);
        if (self::asksToStop($burst)) {
            return mb_strlen($text) > 40 || preg_match('/تصوت|تصوتي|فيديو|1000|ليكود|هدم|مليار/u', $text) === 1;
        }
        if (self::identityReplyMisses($turns, $text)) {
            return true;
        }
        if (self::spendingReplyMisses($burst, $text) || self::replyTooLong($turns, $text) || self::politicalContentMisses($burst, $text)) {
            return true;
        }
        if (self::politicalReplyMisses($burst, $text)) {
            return true;
        }
        if (! self::isReligiousObjection($burst)) {
            return self::reasksAnsweredVote($burst, $text);
        }

        return preg_match('/1000|١٠٠٠|الضرورات|قتيل/u', $text) === 1
            || self::reasksAnsweredVote($burst, $text)
            || mb_strlen($text) > 180;
    }

    private static function reasksAnsweredVote(string $burst, string $text): bool
    {
        if (self::replyCarriesRecord($text) || preg_match('/كرامة|ثقة|وعد|نتيجة|ملموس|من حقك|عالأرض/u', $text) === 1) {
            return false;
        }

        $already = preg_match('/اصوت|أصوت|(?<![\p{L}])لأ(?![\p{L}])|(?<![\p{L}])لا(?![\p{L}])|مش رح|حرام|مشترك|إنجاز|انجاز|مش مقتنع/u', $burst) === 1;

        return $already && preg_match('/ناوي.*(تصوت|تصوتي)|بتطلع تصوت|بتصوت هالمرة|ناوي تصوت/u', $text) === 1;
    }

    private static function keepsRefusing(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || self::expressesConcern($text) || mb_strlen($text) > 40) {
            return false;
        }

        return preg_match('/^(عالفاضي|على الفاضي|مش مقتنع|متحكم|ما بغير|ولشي)[\s.!?؟]*$/u', $text) === 1;
    }

    private static function dismissesArabLeadership(string $text): bool
    {
        $folded = self::foldParty($text);
        $doubts = preg_match('/مقتنع|نتايج|نفس الشي|ما بجيب|بجيبو/u', $folded) === 1;
        $both = preg_match('/موحد/u', $folded) === 1 && preg_match('/مشترك/u', $folded) === 1;
        $leadership = preg_match('/قياد/u', $folded) === 1 && preg_match('/موحد|مشترك/u', $folded) === 1;

        return $doubts && ($both || $leadership);
    }

    private static function leadershipReplyMisses(string $text): bool
    {
        $hasBudgets = preg_match('/ميزاني/u', $text) === 1;
        $hasTurnout = preg_match('/قلة التصويت/u', $text) === 1;
        $hasVote = preg_match('/الموحدة/u', $text) === 1 && preg_match('/صوت/u', $text) === 1;
        $hedges = preg_match('/غياب النتائج|الوعد لحاله|الوعد لحال/u', $text) === 1;

        return ! $hasBudgets || ! $hasTurnout || ! $hasVote || $hedges;
    }

    private static function leadershipFallback(?string $name): string
    {
        $female = trim((string) $name) !== '' && MansourPrompt::isFemaleName($name);
        $go = $female ? 'تطلعي وتصوتي' : 'تطلع وتصوت';

        return 'القيادات الي بتحكي عنها، مثل الموحدة، كانت سبب لضخ ميزانيات أكبر للوسط العربي وتحسين الوضع. صح النتائج ما كانت أحسن شي، بس هاد برجع لقلة التصويت من الناس. ما بدنا نعطي حكومة فاشية مجال تكمل وتسرح وتمرح على كيفها. ضروري '.$go.' حتى يكون في قوة برلمانية للأحزاب العربية، وعنجد يكون في تغيير، ونسقط هاي الحكومة. صوتك مهم، ما تستهين فيه. لو الي مثلك يطلعوا ويصوتوا، أرقام المصوتين بتتضاعف والمقاعد بتزيد، وبهيك التغيير بصير حقيقي وتبين ملامح البداية. صوتك لازم يكون للموحدة.';
    }

    private static function saysNothingChanges(string $text): bool
    {
        $folded = self::foldParty($text);

        return preg_match('/علفاضي|عالفاضي|على الفاضي|نفس النتيج|مولويل|مواويل|الموال|70 سن|سبعين سن/u', $folded) === 1;
    }

    private static function nothingChangesFallback(?string $name): string
    {
        $female = trim((string) $name) !== '' && MansourPrompt::isFemaleName($name);
        $go = $female ? 'تطلعي وتصوتي' : 'تطلع وتصوت';

        return 'مش كله على الفاضي، والنتيجة مش مكتوبة إنها تضل هيك. الموحدة كانت سبب لضخ ميزانيات أكبر للوسط العربي وتحسين الوضع. صح النتائج ما كانت أحسن شي، بس هاد برجع لقلة التصويت من الناس. ما بدنا نعطي حكومة فاشية مجال تكمل وتسرح وتمرح على كيفها. ضروري '.$go.' حتى يكون في قوة برلمانية للأحزاب العربية، وعنجد يكون في تغيير، ونسقط هاي الحكومة. صوتك مهم، ما تستهين فيه. لو الي مثلك يطلعوا ويصوتوا، أرقام المصوتين بتتضاعف والمقاعد بتزيد، وبهيك التغيير بصير حقيقي وتبين ملامح البداية. صوتك لازم يكون للموحدة.';
    }

    private static function expressesConcern(string $text): bool
    {
        return preg_match('/ثقة|وعود|بوعد|ما بعمل|ما بفرق|بكرامة|كرامة|ملموس|خذل|خيبة|عالفاضي|على الفاضي/u', $text) === 1;
    }

    private static function concernReplyMisses(string $burst, string $text): bool
    {
        if (! self::expressesConcern($burst) || self::asksToStop($burst)) {
            return false;
        }

        if (self::lastLineAsksFacts($burst) || self::replyCarriesRecord($text)) {
            return false;
        }

        $pushesVote = preg_match('/بتطلع تصوت|بتطلعي تصوتي|ناوي تصوت|ناوية تصوتي|اذا قعدنا بالبيت/u', $text) === 1;
        $answersConcern = preg_match('/كرامة|ثقة|وعد|نتيجة|ملموس|من حقك|عالأرض/u', $text) === 1;

        return $pushesVote && ! $answersConcern;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function concernFallback(string $burst, array $turns = []): string
    {
        if (! self::expressesConcern($burst)) {
            return '';
        }
        $dignity = preg_match('/كرامة|بكرامة/u', $burst) === 1;
        $promises = preg_match('/بوعد|وعود|ما بعمل|ثقة/u', $burst) === 1;
        if ($dignity && $promises) {
            $text = 'مش طالب وعود، بدك نتيجة وتعيش بكرامة، وهاد من حقك. شو أهم تغيير لازم يصير عالأرض حتى ترجع تاخد الشغل السياسي جد؟';
        } elseif ($dignity) {
            $text = 'بدك تعيش بكرامة، وهاد مطلب من حقك، مش وعد. شو أهم شي لازم يتغير عالأرض حتى تحس إن في نتيجة؟';
        } elseif ($promises) {
            $text = 'في فرق بين وعد وبين نتيجة بتنقاس. إذا الوعود ما بانت، الثقة بتوقع. شو النتيجة اللي ناقصتك تشوفها؟';
        } else {
            $text = 'الثقة بترجع لما النتيجة تبين، مش لما ينعاد الحكي عن أهمية التصويت. شو التغيير اللي إذا صار بترجعه جد؟';
        }

        return self::withoutRepeatedQuestion($text, $turns);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function withoutRepeatedQuestion(string $text, array $turns): string
    {
        $previous = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant' && trim((string) ($turn['message'] ?? '')) !== '') {
                $previous = trim((string) $turn['message']);
            }
        }
        if ($previous === '' || preg_match('/(?:^|[.!\n])\s*([^؟\n.]{3,})؟\s*$/u', $text, $match) !== 1) {
            return $text;
        }
        $question = trim($match[1]);
        if ($question !== '' && mb_stripos($previous, $question) !== false) {
            return trim((string) preg_replace('/(?:^|[.!\n])\s*[^؟\n.]{3,}؟\s*$/u', '', $text));
        }

        return $text;
    }

    private static function lastLineAsksFacts(string $burst): bool
    {
        $last = '';
        foreach (preg_split('/\n/u', $burst) ?: [] as $line) {
            if (trim($line) !== '') {
                $last = trim($line);
            }
        }

        return $last !== '' && (
            self::isPoliticalQuestion($last)
            || self::asksBudget($last)
            || self::asksForTheRecord($last)
            || self::asksWhatArrived($last)
            || preg_match('/هدم|ميزاني/u', $last) === 1
        );
    }

    private static function replyCarriesRecord(string $text): bool
    {
        return preg_match('/550|549|922|مليار|هدم|قومية|كامينتس|مشتركة/u', $text) === 1;
    }

    private static function concernSummary(string $burst): string
    {
        if (preg_match('/كرامة|بكرامة/u', $burst) === 1 && preg_match('/بوعد|وعود|ثقة|ما بفرق/u', $burst) === 1) {
            return 'فقدان الثقة لأن الوعود ما بتبين كنتائج، وبده يعيش بكرامة';
        }
        if (preg_match('/كرامة|بكرامة/u', $burst) === 1) {
            return 'بدي أعيش بكرامة';
        }
        if (preg_match('/بوعد|وعود|ما بعمل/u', $burst) === 1) {
            return 'فقدان الثقة لأن الوعود ما بتبين كنتائج';
        }
        if (preg_match('/ما بفرق/u', $burst) === 1) {
            return 'ما بفرق مين بفوز إذا ما في نتيجة ملموسة';
        }

        return 'فقدان الثقة بسبب الوعود وعدم ظهور نتائج ملموسة';
    }

    /**
     * @param  array{known:string,topic:string,answered:string,open_question:string,concern:string,wants_to_continue:bool}  $memory
     * @return array{known:string,topic:string,answered:string,open_question:string,concern:string,wants_to_continue:bool}
     */
    private static function withConcern(array $memory, string $burst, bool $stop): array
    {
        if ($stop) {
            $memory['wants_to_continue'] = false;
        }
        if ($memory['concern'] === '' && self::expressesConcern($burst)) {
            $memory['concern'] = self::concernSummary($burst);
        }

        return $memory;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function firstBrushOff(array $turns): bool
    {
        if (! self::brushesOff(self::lastUser($turns))) {
            return false;
        }
        $times = 0;
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                continue;
            }
            if (self::brushesOff(trim((string) ($turn['message'] ?? '')))) {
                $times++;
            }
        }

        return $times === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function secondBrushOff(array $turns): bool
    {
        if (! self::brushesOff(self::lastUser($turns))) {
            return false;
        }
        $times = 0;
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                continue;
            }
            if (self::brushesOff(trim((string) ($turn['message'] ?? '')))) {
                $times++;
            }
        }

        return $times >= 2;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function missesEncouragement(array $turns, string $text): bool
    {
        $last = self::lastUser($turns);
        if (self::asksWho($last)) {
            return false;
        }
        if (self::isOpeningTurn($turns)) {
            return preg_match('/مركز استطلاعات/u', $text) !== 1
                || preg_match('/موظف|مؤيد|محايد|بدك تكمل/u', $text) === 1;
        }
        if (self::explicitNoVote($turns)) {
            return self::missesVideoLink($text);
        }
        if (self::firstBrushOff($turns) && ! self::asksToStop(self::trailingUserText($turns))) {
            return preg_match('/بوقف|بسكّر|بسكر|آسفة|اسفة/u', $text) === 1;
        }
        if (preg_match('/لم الشمل|لمّ الشمل/u', self::threadText($turns)) === 1
            && preg_match('/هدم|فتات|30 مليار|٣٠ مليار/u', $last) !== 1) {
            return preg_match('/الداخلية|معاناة/u', $text) !== 1
                || preg_match('/سجل|نراجع|كل قائمة|تاريخ التصويت/u', $text) === 1;
        }
        if (preg_match('/انفصل|منفصل(?!ة)|تلاعب|تفكيك/u', $last) === 1) {
            return preg_match('/حجازي|مقاعد/u', $text) !== 1;
        }
        if (preg_match('/هدم/u', $last) !== 1) {
            return false;
        }

        return preg_match('/تخطيط|كامينتس|اربعة|أربعة|حكومة التغيير/u', $text) !== 1;
    }

    private static function missesVideoLink(string $text): bool
    {
        if (preg_match('/بحترم قرارك|إجراءات|اجراءات|معلومة|معلومه|قرار المشار|القرار إلك|القرار الك|مرفوض/u', $text) === 1) {
            return true;
        }

        return preg_match('/فيديو/u', $text) !== 1
            || preg_match('/1000|١٠٠٠|قتيل/u', $text) !== 1
            || preg_match('/هدم|بيوت/u', $text) !== 1
            || preg_match('/سبب|تقاطع/u', $text) !== 1
            || preg_match('/عنصري/u', $text) !== 1
            || preg_match('/4 سنين|٤ سنين|اربع سنين|أربع سنين|أربعة سنين/u', $text) !== 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function repairHint(array $turns): string
    {
        $last = self::lastUser($turns);
        $burst = self::trailingUserText($turns);
        if (self::asksToStop($burst)) {
            return 'طلب ما تبعتي. جملة قصيرة إنك واقفة، بلا سؤال. close true.';
        }
        if (self::isReligiousObjection($burst)) {
            return 'السبب ديني. جملة او جملتين: فاهمة إنه شايفه حرام، وانتي مش شيخة. لا تعيدي سؤال التصويت ولا تجيبي 1000 قتيل.';
        }
        if (self::dismissesArabLeadership($burst)) {
            return 'مش مقتنع بالقيادات والنتائج. اكتبي: الموحدة ضخت ميزانيات أكبر للوسط العربي، والناقص من قلة التصويت. لازم يصوت للموحدة، صوته مهم، ولو الي مثله يصوتوا المقاعد بتزيد. ممنوع الوعد لحاله مش إنجاز.';
        }
        if (self::expressesConcern($burst) && ! self::lastLineAsksFacts($burst)) {
            return 'جاوي على الثقة أو الكرامة أو النتيجة، بجملتين. لا تسألي بتطلع تصوت ولا تعيدي إن التصويت مهم. سؤال واحد اختياري عن شو لازم يتغير عالأرض.';
        }
        if (self::firstBrushOff($turns)) {
            return 'طلب يوقف. جملة قصيرة إنك واقفة، بلا سؤال تصويت. close true.';
        }
        if (self::explicitNoVote($turns)) {
            return "الفيديو قبل رسالتك. اكتبي بهالروح:\nوبعد ها الفيديو شو قلت؟ بدك نعيش كمان 4 سنين بهالحكومة العنصرية؟\n4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.\nبقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟\nللانثى شو قلتي وبدكي ومخليكي تقاطعي. ممنوع بحترم قرارك وممنوع القرار إلك وممنوع إجراءات التصويت.";
        }
        if (self::asksWho($last)) {
            return 'جاوي على سؤال الهوية فقط. الجهة مركز اتصالات القائمة العربية الموحدة. إذا سال ليش: سماع الرأي وتشجيع المشاركة. إذا سال وين: ما في عنوان. إذا سال عن الرقم: ما في مصدر. إذا سال اذا بوت: الرد آلي. بلا سؤال تصويت وبلا معك حق تسأل.';
        }
        if (self::lastLineAboutDemocrats(self::trailingUserText($turns), $turns)) {
            return 'جاوي عن الديمقراطيين بإسرائيل من الوقائع: 2024، العمل وميرتس، يائير غولان. اذا السؤال عن العرب او الفرق او غولان او المصدر، خليك بالمصدر. بلا بحترم اختيارك وبلا ضغط لتغيير الحزب.';
        }
        if (self::isOpeningTurn($turns)) {
            return 'اكتبي التعريف كما هو، وبعدين سطر فاضي، وبعدين السؤال لحاله: بعرف إنه تواصلي معك إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي. بتسمحلي أسألك سؤال صغير؟ ممنوع الانتخابات بهاي الرسالة.';
        }
        if (preg_match('/لم الشمل|لمّ الشمل/u', self::threadText($turns)) === 1 && preg_match('/هدم|فتات|30 مليار/u', $last) !== 1) {
            return 'الجواب من الدليل ومن دون تاريخ أو عدد: النواب امتنعوا وما صوتوا مع القانون، وهذا انضباط ائتلاف مش إنكار للمعاناة، وفي مسار مع وزارة الداخلية لسه يقدر يكتمل بالتدريج. لا تبلشي ببفهم اعتراضك، ولا تسأليه شو يتوقع من المشتركة.';
        }
        if (preg_match('/انفصل|تلاعب|تفكيك/u', $last) === 1) {
            return 'من الدليل: إبراهيم حجازي وفى والاخلال من غيرهم، والخلاف ترتيب مقاعد مش فكر، و15 مقعد ثلاث مرات ما اسقطت نتنياهو. السؤال: تأثير من جوا ولا برا؟ لا تبلشي ببفهم اعتراضك.';
        }
        if (preg_match('/اصوت للموحد|بدك اياي|انت مع مين/u', $last) === 1) {
            return 'اكتبي الرد بهالروح، من دون اعتذار: معك حق تسأل هيك سؤال، بس انا بحكي كشخص عربي بعيش بهاي الدوله، مصلحة شعبي بتكون مع الجهة اللي بتفيده. كملي: اربعة مقاعد جوا وقفوا الهدم وخفضوا القتل وجابوا ميزانيات، و15 مقعد برا ما منعوا قانون القومية ولا كامينتس.';
        }
        if (self::asksForTheRecord(self::trailingUserText($turns))) {
            return 'جاوي بالواقعة: أربعة مقاعد وقفت الهدم، وقرار 550 نحو 30 مليار والتنفيذ ناقص. انسبيها لكراس الكادر وانه وثيقة حزبية. بلا حكي عن الثقة.';
        }
        if (self::namesLikud(self::trailingUserText($turns))) {
            return 'جاوي: الليكود حزب نتنياهو، وحكومته مرت قانون القومية وكامينتس. كراس الكادر بيحكي إن أربعة مقاعد جوا وقفت الهدم. بلا سؤال شو السبب.';
        }
        if (preg_match('/هدم/u', $last) === 1) {
            return 'جاوي من الكراس: أربعة مقاعد جوا حكومة التغيير وقفت الهدم، والتصويت بيأثر على مين بيسن قوانين التخطيط والبناء. وثيقة حزبية مش تقرير مستقل.';
        }

        return 'اكتبي رسالة قصيرة باللهجة على آخر كلامه. لا تعيدي سؤال انجاوب، ومو لازم سؤال تصويت.';
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function fallback(array $turns, ?string $name): string
    {
        $last = self::lastUser($turns);
        $female = trim((string) $name) !== '' && MansourPrompt::isFemaleName($name);
        $you = $female ? 'بدكي' : 'بدك';
        $burst = self::trailingUserText($turns);
        if (self::asksToStop($burst) || self::secondBrushOff($turns)) {
            return 'تمام، ما برجعلش رسالة.';
        }
        if (self::dismissesArabLeadership($burst)) {
            return self::leadershipFallback($name);
        }
        if (self::saysNothingChanges($burst)) {
            return self::nothingChangesFallback($name);
        }
        if (self::isReligiousObjection($burst)) {
            return 'فاهم إنك شايف التصويت حرام. أنا مش شيخة حتى أفتي.';
        }
        if (self::lastLineAboutDemocrats($burst, $turns)) {
            $party = self::democratsFallback($burst, $name);
            if ($party !== '') {
                return $party;
            }
        }
        $focused = self::focusedPoliticalFallback($burst);
        if ($focused !== '') {
            return $focused;
        }
        if (self::asksForTheRecord($burst)) {
            return 'بكراس الكادر: أربعة مقاعد جوا وقفت الهدم، وقرار 550 خصص نحو 30 مليار والتنفيذ ناقص. هاد كلام القائمة عن حالها، مش تقرير مستقل.';
        }
        if (self::asksBudget($burst)) {
            return 'قرار 550 نحو 30 مليار على خمس سنين، والسنة الاولى نحو 6 مليار. بنك اسرائيل حكى عن نحو 60 بالمية من مبلغ هاي السنة مش من كل الـ30، وقرار 549 نحو 2.5 مليار. الارقام من كراس الكادر.';
        }
        if (self::namesLikud($burst)) {
            return 'الليكود حزب نتنياهو، وحكومته مرت قانون القومية وتعديل كامينتس. كراس الكادر، وهو وثيقة حزبية، بيحكي إن أربعة مقاعد جوا حكومة التغيير وقفت الهدم.';
        }
        if (self::criticizesAbbas($burst)) {
            return 'د. منصور عباس رئيس القائمة. كراس الكادر بينسب لفترته وقف الهدم وقراري 550 و549، وإدانة استهداف المدنيين بغزة.';
        }
        if (preg_match('/انجاز|إنجاز/u', $burst) === 1) {
            return 'في ميزانيات انقرت، بس الناقص هو التنفيذ.';
        }
        if (preg_match('/مشترك/u', $burst) === 1 && preg_match('/لم الشمل|لمّ الشمل/u', $burst) !== 1) {
            return 'المشتركة وصلت 15 مقعد وهي برا، وبكراس الموحدة هاد ما منع قانون القومية ولا كامينتس. الخلاف كان ترتيب مقاعد.';
        }
        $concern = self::concernFallback($burst, $turns);
        if ($concern !== '') {
            return $concern;
        }
        if (self::firstBrushOff($turns)) {
            return 'تمام، ما برجعلش رسالة.';
        }
        if (self::explicitNoVote($turns)) {
            $said = $female ? 'شو قلتي' : 'شو قلت';
            $want = $female ? 'بدكي' : 'بدك';
            $why = $female
                ? 'بقدر أعرف منك شو السبب الي مخليكي تقاطعي وما تصوّتي؟'
                : 'بقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟';

            return "وبعد ها الفيديو {$said}؟ {$want} نعيش كمان 4 سنين بهالحكومة العنصرية؟\n4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.\n{$why}";
        }
        if (self::asksWho($burst)) {
            return self::whoReply($turns, $female);
        }
        if (self::isOpeningTurn($turns)) {
            $with = $female ? 'معكي' : 'معك';
            $ask = $female ? 'بتسمحيلي أسالكي سؤال صغير؟' : 'بتسمحلي أسألك سؤال صغير؟';

            return "بعرف إنه تواصلي {$with} إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\n{$ask}";
        }
        if (preg_match('/اصوت للموحد|بدك اياي|انت مع مين|انتي مع مين/u', $last) === 1) {
            return 'معك حق تسأل هيك سؤال، بس انا بحكي كشخص عربي بعيش بهاي الدوله، مصلحة شعبي بتكون مع الجهة اللي بتفيده. اربعة مقاعد جوا وقفوا الهدم وخفضوا القتل وجابوا ميزانيات، و15 مقعد برا ما منعوا قانون القومية ولا كامينتس. '.$you.' الجهة اللي بتفيد بلدك؟';
        }
        if (preg_match('/هدم/u', $last) === 1) {
            return 'أربعة مقاعد جوا حكومة التغيير وقفت الهدم، حسب كراس الكادر. التصويت بيأثر على مين بيسن قوانين التخطيط والبناء، وهاد موقف القائمة مش تقرير مستقل.';
        }
        if (preg_match('/انفصل|تلاعب|تفكيك/u', $last) === 1) {
            return 'الموحدة شريكة من 2015، وإبراهيم حجازي دخل لما جاء دوره والاخلال جاء من غيرهم. الجبهة رفضت قائمة تقنية، والخلاف كان ترتيب مقاعد مش فكر. 15 مقعد ثلاث مرات ما اسقطت نتنياهو، فالسؤال: '.$you.' التأثير من جوا القرار ولا تضل برا؟';
        }
        if (preg_match('/لم الشمل|لمّ الشمل/u', self::threadText($turns)) === 1
            && preg_match('/هدم|فتات|30 مليار|٣٠ مليار|اصوت للموحد|مش ناوي/u', $last) !== 1) {
            return 'النواب امتنعوا عن التصويت، وما صوتوا مع قانون لم الشمل. هاد انضباط ائتلاف، مش انكار لمعاناة العائلات. في مسار مع وزارة الداخلية، والملف صار معركة سياسية، بس المسار لسه يقدر يكتمل بالتدريج. '.$you.' التأثير اللي بوقف هالقوانين من جوا ولا من برا؟';
        }
        if (preg_match('/فتات|30 مليار|٣٠ مليار/u', $last) === 1) {
            return 'قرار 550 خصص نحو 30 مليار على خمس سنين. السنة الاولى نحو 6 مليار، وبنك اسرائيل قال نحو 60 بالمية من مبلغ هاي السنة راح للمجتمع العربي، مش من كل الـ30. وقرار 549 نحو 2.5 مليار للعنف والجريمة.';
        }

        return '';
    }

    private static function voice(): string
    {
        return <<<'PROMPT'
أنت ديما. بتحكي واتساب باللهجة الفلسطينية، دافية ومختصرة، جملة أو جملتين. انتي بنت البلد وعندك راي. الرد رسالة للشخص نفسه. لا تعلق على الشغل، ولا تعتذري عن رايك. إذا شرح الثقة أو الكرامة أو الوعود، جاوي على هالنقطة ولا تعيدي سؤال التصويت. لا تبلشي ببفهم اعتراضك. الحقائق بس من الدليل، بلا تواريخ ولا أعداد مخترعة. افهمي عربي مخلوط عبري وانجليزي، وكلمات عبري بحروف عربي مثل بسدر وتودا، وردي بالعربي. ايموجي واحد مألوف احيانا، مش بكل رسالة، وممنوع على رسالة الفيديو. ممنوع كلمة مايل. لا تسألي لمين رح تصوت إذا هو جاوب أو شارح اعتراضه.
PROMPT;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function lastUser(array $turns): string
    {
        $last = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant' && trim((string) ($turn['message'] ?? '')) !== '') {
                $last = trim((string) $turn['message']);
            }
        }

        return $last;
    }

    private static function unitedGoodbye(?string $name): string
    {
        $clean = trim((string) $name);
        $you = $clean !== '' && MansourPrompt::isFemaleName($clean) ? 'الكي' : 'الك';

        return 'تمام شكرا '.$you.' كتير على وقتك، نهارك سعيد 🌷';
    }

    private static function cleanReply(string $text, bool $keepAutomation = false): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (! $keepAutomation && (preg_match('/\b(bot|chatbot)\b/iu', $text) === 1 || preg_match('/بوت|ذكاء اصطناعي|نموذج لغوي/u', $text) === 1)) {
            $text = trim((string) preg_replace('/[^.!\n؟]{0,80}(بوت|ذكاء اصطناعي|نموذج لغوي|\bbot\b)[^.!\n؟]*/iu', '', $text));
        }
        $text = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text);
        $text = str_replace(['«', '»', '“', '”', '„', '‹', '›', '<', '>'], '', $text);
        if (preg_match('/مايل/u', $text) === 1) {
            $parts = preg_split('/(?<=[.!\n؟])/u', $text) ?: [$text];
            $kept = [];
            foreach ($parts as $part) {
                $piece = trim($part);
                if ($piece === '') {
                    continue;
                }
                $kept[] = preg_match('/مايل/u', $piece) === 1 ? 'لمين رح تصوت المره هاي؟' : $piece;
            }
            $text = trim(implode(' ', $kept));
        }

        return trim($text);
    }
}
