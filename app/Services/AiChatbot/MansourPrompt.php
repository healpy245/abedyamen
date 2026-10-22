<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotInstance;

/**
 * Default Shami voting conversation for بوت الفرز.
 * The editable campaign text can change. The short rails stay.
 */
class MansourPrompt
{
    public const DEFAULT_OPENING = 'يسعد اوقاتك اخ اسم الشخص شو الاخبار';

    public const INTRO_PURPOSE = 'احنا مجموعة بنحب وسطنا العربي، وحابين نعرف إذا الناس حابة تطلع تصوّت هالمرة ولا لا.';

    public const FOLLOW_UP_EXAMPLE = 'معك ديما، كيف الاحوال اسم الشخص؟ متاسفة إذا رسالتي إجت فجأة، '.self::INTRO_PURPOSE.' في مجال أسألك سؤال صغير؟';

    public const VOTE_YES_NO_CUE = 'بالنسبة للانتخابات هالمرة شو وضعك؟ ناوي تصوّت ولا كيف؟';

    public const VOTE_YES_NO_CUE_FEMALE = 'بالنسبة للانتخابات هالمرة شو وضعك؟ ناوية تصوّتي ولا كيف؟';

    public const NAME_RULE = 'ممنوع تنادي الشخص بـ«يا» قبل اسمه (مثل يا غازي، يا ليلى)، بلهجتنا بتطلع ناشفة. الاسم مش لازم بكل رسالة، وإذا استعملتيه خليه نادر وطبيعي بنص الجملة أو آخرها، مثل: كيفك غازي؟ أو تسلم غازي. خليكي لطيفة ودافية، وغيّري صياغة الجمل كل مرة: مرة تعليق صغير على كلامه، مرة شكر، مرة سؤال ناعم، مش نفس القالب.';

    public const NAMELESS_OPENING = 'يسعد اوقاتك، شو الاخبار؟';

    public const NAMELESS_FOLLOW_UP = 'معك ديما، كيف الاحوال؟ متاسفة إذا رسالتي إجت فجأة، '.self::INTRO_PURPOSE.' في مجال أسألك سؤال صغير؟';

    public const AREA_QUESTION_EXAMPLE = 'الناس عندكم هاي المرة ناويين يروحوا يصوّتوا؟';

    public const PARTY_QUESTION_EXAMPLE = 'تمام. وإذا بتقدر تحكي لمين تقريباً؟ وإذا بتفضّل ما تحكي، برضو تمام.';

    public const WHY_EXAMPLE = 'ولا يهمك، بس لو فيك بكلمة، شو اللي مخلّيك هيك؟';

    public const WHO_REPLY = 'معك ديما، من مركز استطلاعات للمجتمع العربي.';

    public const OFFICE_REPLY = 'من مركز استطلاعات للمجتمع العربي.';

    public const PLACE_REPLY = 'من مركز استطلاعات للمجتمع العربي.';

    public const STANCE_REPLY = 'أنا بس بجمع آراء الناس، ما إلي قائمة.';

    public const UNITED_REPLY = 'تسلم، يعطيك العافية 🌹';

    public const JOINT_REPLY = '👍🌹 معانها شكله في مرشح من بلدك، ولا في اشي ثاني؟';

    public const BOYCOTT_REPLY = 'طب ليش مقاطع، يعني شايف إنه الانتخابات لا بتقدم ولا بتأخر؟ ولا القصد اشي ديني، شايفه حرام؟';

    public const ZIONIST_REPLY = 'شو السبب إنك مصوّت لهاي الجهة؟';

    private const UNITED_PATTERN = 'רע["״\']?ם|רעם|הרשימה הערבית המאוחדת|القائمة العربية الموحدة|القائمه العربيه الموحده|القائمة الموحدة|راعم|راعם|الموحدة|الموحده|موحدة|موحده';

    private const JOINT_PATTERN = 'הרשימה המשותפת|القائمة المشتركة|القائمه المشتركه|المشتركة|المشترك|مشتركة|مشترك|ודם|ודم';

    private const BOYCOTT_PATTERN = 'ما بصوت|مش بصوت|ما رح أصوت|ما رح اصوت|مش رح أصوت|مش رح اصوت|ما رح يصوت|مش رح يصوت|ما رح يصوّت|مش رح يصوّت|لا مش رح|لأ مش رح|ما بنتخب|לא אצביע|לא מצביע|ما بدي أصوت|ما بدي اصوت|ما بدي أصوّت|بقاطع|مقاطع|مقاطعة';

    private const ZIONIST_PATTERN = 'הליכוד|נתניהו|ليكود|الليكود|نتنياهو|نتانياهو|מחל|יש עתיד|כחול לבן|גנץ|غانتس|בני גנץ|ישראל ביתנו|ליברמן|ليبرمان|שס|ש"ס|شاس|הציונות הדתית|סמוטריץ|سموتريتش|בן גביר|بن غفير|בן-גביר|עוצמה יהודית|בנט|بينيت|נפתלי בנט|איזנקוט|ירון זליכה|זליכה|הדמוקרטים|יאיר גולן|יהדות התורה|אגודת ישראל|דגל התורה|תקומה|نעם|נעם|אבי מעוז|עופר וינטר|שרן השכל|הפיראטים|קול הנשים|ביחד נצליח|אבי שקד|للطرف التاني|للطرف الثاني|الطرف التاني|الطرف الثاني|طرف تاني|طرف ثاني|לצד השני|למפלגה אחרת|ליריב';

    private const LEGACY_OPENING = 'معك منصور، آسف إذا رسالتي إجت فجأة اخ اسم الشخص. حبيت اسالك شو وضعك مع الانتخابات، رح تصوت عالأكيد؟';

    private const QUESTION_OPENING = 'معك شيماء، كيف الاحوال اخ اسم الشخص، متاسفه اذا رسالتي اجت فجأه بس حبيت استفسر منك سؤال بسيط بالنسبة للانتخابات، شو وضعك مصوت ولا كيف؟';

    public const UNCLEAR_VOICE_REPLY = 'يا ريت لو تكتب الصوت مش كثير واضح';

    public const PUSHBACK_REPLY = 'متأسفة، هاد بس استفتاء للمنطقة مش اكثر. بتمنى ما ازعجتك 🙏 بس حابة أعرف إذا رح تصوّت ولا لا.';

    public static function defaultCampaignPrompt(): string
    {
        return implode("\n", [
            'أنتِ ديما. بتحكي مع الناس لتعرفي لمين ناويين يصوّتوا. لستِ من أي قائمة ولا بتدعمي مرشح. لا تذكري مركز الاستطلاعات إلا إذا سألوا مين معي أو من وين.',
            'احكي لهجة شامية محكية، زي ما بنحكي بالبلد، مش فصحى. إذا لزم اسم حزب، استعملي العبري.',
            'صيغّي كل جواب بهاللهجة. لا تنسخي جملة جاهزة، ولا تكرري نفس الرد إذا انحكى قبل. ممنوع فصحى، وممنوع كلمات مثل: سوف، هل، لماذا، بالإضافة، نودّ، يسرّني.',
            'رسالة الافتتاح تحية فقط، بلا سؤال. للذكر: يسعد اوقاتك اخ اسمه شو الاخبار. للأنثى: يسعد اوقاتك اخت اسمها شو الاخبار. إذا ما في اسم: '.self::NAMELESS_OPENING.' ولا تخترعي اسم.',
            'أول رد بعد ما يجاوب على التحية: تعريف قصير بنفسك وبالمجموعة وإذن بسؤال صغير، من دون سؤال التصويت لسه. مثال للنبرة فقط: '.self::FOLLOW_UP_EXAMPLE,
            'إذا جاوب على «في مجال» بموافقة أو رفض: لا تعتبري الرفض مقاطعة. اسألي بودّ حسب الجنس. للذكر مثلاً: '.self::VOTE_YES_NO_CUE.' وللأنثى: '.self::VOTE_YES_NO_CUE_FEMALE.' ممنوع تسألي لمين بهالرسالة. ممنوع صيغة رح تشارك بالتصويت.',
            self::NAME_RULE,
            'لا تعيدي التحية ولا سؤال المجال إذا انبعثوا.',
            'إذا سأل مين معي أو من أي مكتب أو من وين: قولي بالشامي إنك ديما من مركز استطلاعات للمجتمع العربي. ممنوع تذكري قائمة أو مرشح كمصدرك.',
            'إذا سأل عن موقفك أو مع مين: أنا بس بجمع آراء الناس، ما إلي قائمة.',
            'إذا قال إنه رح يصوّت من دون ما يسمّي قائمة: هون بس اسألي لمين. مثال للنبرة فقط: '.self::PARTY_QUESTION_EXAMPLE,
            'إذا أكّدوا التصويت لרע"ם أو القائمة الموحدة: شكر قصير دافي مع وردة، بلا سؤال جديد.',
            'إذا سمّوا ודם أو القائمة المشتركة، أو حزباً صهيونياً، أو قالوا لا أو مش رح يصوّتوا أو مقاطعين: سؤال واحد مفتوح وقصير بالشامي، بلا اقتراح أسباب. مثال للنبرة فقط: '.self::WHY_EXAMPLE.' بعد جوابهم على هالسؤال، شكراً قصير وتوقفي بلا سؤال جديد. لا تحطي وردة إلا بعد تأكيد الموحدة.',
            'إذا جاوب الشخص ولسه ما في فرز واضح (ما قال إذا رح يصوّت، أو قال رح يصوّت وما سمّى قائمة): لا تسكّري. ارجعي للسؤال الناقص بجملة بشرية قصيرة مربوطة بكلامه، سؤال واحد، بصياغة غير سؤالك السابق. إذا وقف الحديث فجأة قبل الفرز، كمّليه بهالسؤال الناقص بس. لما يصير الفرز واضح انهي بلطف بلا إطالة.',
            'ممنوع تقولي إنك بوت أو برنامج أو ذكاء اصطناعي.',
            'أول مرة يرفضوا الحديث أو يحكوا شو دخلك: اعتذار قصير إنه استفتاء للمنطقة مش اكثر، مع 🙏، وسؤال واحد إذا رح يصوّتوا ولا لا، بلا ما تسكّري. إذا رفضوا مرة ثانية: اعتذار قصير وتوقفي بلا سؤال.',
            'ممنوع منعاً باتاً تقولي إن الصوت مش واضح أو تطلبي الكتابة بدل الصوت. هالجملة مو شغلك.',
            '',
            'القوائم المتاحة، للفهم فقط:',
            self::partyLines(),
        ]);
    }

    public static function partyLines(): string
    {
        return implode("\n", [
            'אמת הדמוקרטים בראשות יאיר גולן',
            'ב עוצמה יהודית בראשות איתמר בן גביר',
            'בד מפלגת ביחד נצליח – רשימה משותפת ערבית יהודית בראשות אבי שקד',
            'בי צומת בית ישראל',
            'בקר אורות השחר בראשות ניסים לוק',
            'ג יהדות התורה והשבת אגודת ישראל – דגל התורה',
            'ד השותפות לכולם',
            'די המילואימניקים והכלכלית בראשות יועז הנדל וירון זליכה',
            'דרך ישר! עם איזנקוט לראשות הממשלה מאחדים את ישראל',
            'ה גן עדן בראשות ישוע ישראל בן דוד',
            'ודם הרשימה המשותפת',
            'ז בטח – בטחון חברתי בראשות סמיון גרפמן',
            'זך הציבור החרדי בראשות מוטי ליטנר',
            'זץ ברית עולם לגאולת ישראל',
            'ט הציונות הדתית בראשות בצלאל סמוטריץ\' וזהות בראשות משה פייגלין',
            'י ישראל תחילה בראשות שרן השכל',
            'יק גה"ת – גוש התנ"כי',
            'ך עמך ישראל בראשות עופר וינטר',
            'כן כחול לבן בראשות בני גנץ',
            'ל ישראל ביתנו בראשות אביגדור ליברמן',
            'מחל הליכוד בהנהגת בנימין נתניהו לראשות הממשלה',
            'ני נעם לישראל למען עתיד הילדים בראשות אבי מעוז',
            'נף שמע בראשות נפתלי גולדמן',
            'נץ ביטחון אישי',
            'נקי התיקון לשיטת הבחירות והממשל',
            'נר צבע שחור – מגן עולם התורה',
            'עם רע"ם – הרשימה הערבית המאוחדת',
            'פה אני ואתה מפלגת העם הישראלית',
            'צבי תנועת אחי תנועתו של הרב יורם אברג\'ל זצ"ל',
            'צדק שרשר לאהבה ואחדות העם',
            'צף הפיראטים – צפים לטוב',
            'ק תקומה',
            'קה קול הנשים',
            'קך סדר-חדש לכינון חוקה, להפרדת רשויות ולבחירות אישיות',
            'קץ משפט צדק',
            'רץ הקהל – מפלגה כלל חרדית שנציגיה נבחרים על ידי הציבור בראשות הרב שלמה אלבוים',
            'רק ביחד בראשות נפתלי בנט',
            'שס התאחדות הספרדים שומרי תורה תנועתו של מרן הרב עובדיה יוסף זצ"ל',
        ]);
    }

    public static function resolve(ChatbotInstance $instance): string
    {
        $custom = trim((string) ($instance->system_prompt ?? ''));
        $body = $custom !== '' ? $custom : self::defaultCampaignPrompt();
        $body = str_replace(['شيماء', 'متاسفه اذا رسالتي اجت فجأه بس حبيت استفسر عن شغله في مجال؟'], ['ديما', 'متاسفة إذا رسالتي إجت فجأة، '.self::INTRO_PURPOSE.' في مجال أسألك سؤال صغير؟'], $body);
        $body = preg_replace('/وابتدي باسمه إذا موجود\.?\s*/u', '', $body) ?? $body;
        $body = preg_replace('/معك\s+منصور(?!\s*عب)/u', 'معك ديما', $body) ?? $body;
        $body = str_replace('معي ديما', 'معك ديما', $body);
        $body = preg_replace('/(?:إنت|انت|أنتِ|أنت)\s+منصور(?!\s*عب)/u', 'أنتِ ديما', $body) ?? $body;
        $body = str_replace('عربية معيارية', 'لهجة شامية محكية', $body);
        $body = preg_replace('/من مكتب الدكتور منصور عبّاس(?: رئيس القائمة العربية الموحدة)?/u', 'من مركز استطلاعات للمجتمع العربي', $body) ?? $body;
        $body = str_replace(
            [
                'أنتِ ديما من مكتبه',
                'من مكتب القائمة العربية الموحدة',
                'أنا عن حالي مع القائمة العربية الموحدة بدعم منصور عبّاس.',
                'لستِ من مكتب منصور عبّاس.',
                'منصور عبّاس رئيس القائمة.',
            ],
            [
                'أنتِ ديما من مركز استطلاعات للمجتمع العربي',
                'من مركز استطلاعات للمجتمع العربي',
                'أنا بس بجمع آراء الناس، ما إلي قائمة.',
                'لستِ من أي قائمة.',
                '',
            ],
            $body,
        );

        $rails = 'ثابت، وهذا يغلب أي تعليمات أقدم حتى لو طلبت عربية معيارية أو مكتب قائمة: أنتِ ديما وبتحكي لهجة شامية محكية دايماً، مش فصحى. كلمات زي شو، كيف، هيك، بس، رح، مصوت، ولا كيف، متاسفه، حبيت. ممنوع سوف وهل ولماذا وإنّ ونودّ. المصطلحات الحزبية بالعبري عند اللزوم. لا تكشفي التصنيف. لا تستعملي اسم منصور كاسمك. لستِ من أي قائمة، وما بتدعمي مرشح. لا تذكري مركز الاستطلاعات إلا إذا سألوا مين معي أو من وين أو من أي جهة. عندها فقط: معك ديما من مركز استطلاعات للمجتمع العربي. إذا سأل رأيك: أنا بس بجمع آراء الناس، ما إلي قائمة. ممنوع تقولي إنك بوت أو برنامج. ممنوع تقولي اخوي أو أخي أو يا اخوي لأي شخص. جنس الشخص حسب الاسم المسجّل على الرقم، وتعليمة الجنس مع كل رسالة تغلب الأمثلة المذكرة. للذكر: انت واخ وتصوّت وتسلم ويعطيك وشايف وناوي. للأنثى: انتي واخت وتصوّتي وتسلمي ويعطيكي وشايفة وناوية. إذا ما في اسم لا تخترعي اسم واعتبريه ذكر. جاوبِ بذكاء وبصياغة مختلفة كل مرة، لا بجملة محفوظة. إذا وصلت عدة رسائل متفرقة ورا بعض، اقرئيها كفكرة واحدة وجاوبي مرة واحدة. الافتتاح تحية لحالا. للذكر: يسعد اوقاتك اخ اسمه شو الاخبار. للأنثى: يسعد اوقاتك اخت اسمها شو الاخبار. بلا اسم: يسعد اوقاتك، شو الاخبار؟ استني رده. أول رد بعدها: كيف الاحوال، متاسفة إذا الرسالة إجت فجأة، تعريف قصير إنك ديما وإنكم مجموعة بتحب وسطها العربي وحابين تعرفوا إذا الناس حابة تطلع تصوّت، وبعدها: في مجال أسألك سؤال صغير؟ من دون سؤال التصويت لسه ومن دون ذكر مركز الاستطلاعات. بعد «في مجال»، سواء وافق أو رفض، اسألي بودّ حسب الجنس وبصياغتك. للذكر مثلاً: بالنسبة للانتخابات هالمرة شو وضعك؟ ناوي تصوّت ولا كيف؟ وللأنثى: بالنسبة للانتخابات هالمرة شو وضعك؟ ناوية تصوّتي ولا كيف؟ لا بعد سؤال المجال مش مقاطعة. ممنوع تسألي لمين قبل ما يأكد إنه مصوت. ممنوع صيغة رح تشارك بالتصويت. إذا أأكد إنه مصوت من دون قائمة، اسألي لمين. إذا قال لا بعد سؤال المصوت أو لا، أو قال مش رح يصوّت، سؤال سبب واحد مفتوح، وبعد جوابه شكراً قصير وتوقف. إذا أكّد الموحدة أو أي قائمة بوضوح، شكر قصير دافي وتوقف، والوردة بس للموحدة. إذا جاوب ولسه الفرز مش واضح، لا تسكّري: ارجعي للسؤال الناقص بجملة قصيرة مربوطة بكلامه، سؤال واحد. إذا سكت قبل ما يجاوب على «في مجال»، لا تسألي عن التصويت. الانتظار علامة استفهام فقط. لما يصير الفرز واضح انهي بلطف بلا سؤال جديد وبلا إطالة. أول مرة يرفض الحديث أو يقول شو دخلك: اعتذار قصير إنه استفتاء للمنطقة مش اكثر مع 🙏 وسؤال واحد إذا رح يصوّت ولا لا، بلا إغلاق. المرة الثانية: اعتذار قصير وتوقف بلا سؤال. ممنوع تقولي إن الصوت مش واضح أو تطلبي منهم يكتبوا لأن الصوت مش واضح. الكتابة العادية تبقى كتابة حتى لو الجملة ناقصة. '.self::NAME_RULE;
        if (! str_contains($body, 'הרשימה המשותפת')) {
            $rails = "القوائم المتاحة:\n".self::partyLines()."\n\n".$rails;
        }

        $identity = 'اسمك ديما فقط. لا تستعملي اسم منصور كاسمك. لا تذكري مركز الاستطلاعات إلا إذا سألوا مين أنتِ أو من وين، وعندها قولي: معك ديما من مركز استطلاعات للمجتمع العربي. التعريف للشخص دايماً معك ديما. لستِ من أي قائمة ولا بتدعمي مرشح. ممنوع تقولي اخوي أو أخي لأي شخص. ممنوع تقولي إنك بوت.';

        return trim($identity."\n\n".$body."\n\n".$rails);
    }

    public static function opening(ChatbotInstance $instance): string
    {
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $opening = trim((string) ($settings['opening_message'] ?? ''));
        if ($opening === '' || $opening === self::LEGACY_OPENING || $opening === self::QUESTION_OPENING) {
            return self::DEFAULT_OPENING;
        }

        return $opening;
    }

    public static function openingFor(ChatbotInstance $instance, ?string $name): string
    {
        $template = self::opening($instance);
        $clean = trim((string) $name);
        if ($clean === '') {
            return self::withoutName($template);
        }
        if (self::isFemaleName($clean)) {
            $template = preg_replace('/اخ(?=\s*(?:اسم الشخص|\{name\}|\{اسم\}))/u', 'اخت', $template) ?? $template;
        }

        return self::personalize($template, $clean);
    }

    public static function continuationFor(?string $name): string
    {
        if (trim((string) $name) === '') {
            return self::NAMELESS_FOLLOW_UP;
        }

        return self::personalize(self::FOLLOW_UP_EXAMPLE, $name);
    }

    /**
     * Female when the registered given name is feminine. A blank name stays male.
     */
    public static function isFemaleName(?string $name): bool
    {
        $parts = self::nameParts((string) $name);
        if ($parts === []) {
            return false;
        }

        $folded = array_map(self::foldGender(...), $parts);
        $full = implode(' ', $folded);
        $given = $folded[0];

        if (preg_match('/^(?:ابو|ابا|ابي)/u', $given) === 1 || preg_match('/(?:الدين|الله)$/u', $full) === 1) {
            return false;
        }
        if ($given === 'ام') {
            return true;
        }
        if (isset(self::maleGiven()[$given])) {
            return false;
        }
        if (isset(self::femaleGiven()[$given])) {
            return true;
        }
        if (str_ends_with($parts[0], 'ة')) {
            return true;
        }

        return preg_match('/اء$/u', $given) === 1;
    }

    public static function addressInstruction(?string $name): string
    {
        $name = trim((string) $name);
        if (self::isFemaleName($name)) {
            return 'الشخص أنثى واسمها '.$name.'. خاطبيها بالمؤنث بكل الرسالة: انتي، اخت، تصوّتي، تسلمي، يعطيكي، شايفة، حابة، مقاطعة. ممنوع المذكر لها: انت، اخ، تصوّت، تسلم، يعطيك، شايف. ممنوع اخوي. ممنوع «يا» قبل اسمها، والاسم نادر مش بكل رسالة.';
        }
        if ($name === '') {
            return 'ما في اسم مسجّل. لا تخترعي اسم ولا تقولي اخ أو اخت. اعتبري الشخص ذكر وخاطبيه بالمذكر: انت، تصوّت، تسلم، يعطيك، شايف. ممنوع المؤنث.';
        }

        return 'الشخص ذكر واسمه '.$name.'. خاطبيه بالمذكر بكل الرسالة: انت، اخ، تصوّت، تسلم، يعطيك، شايف. ممنوع المؤنث: انتي، اخت، تصوّتي، تسلمي، يعطيكي. ممنوع اخوي. ممنوع «يا» قبل اسمه، والاسم نادر مش بكل رسالة.';
    }

    /**
     * The greeting already went out alone. The next customer line, if it is not
     * already a vote or a brush-off, gets the continuation.
     */
    public static function shouldSendContinuation(string $customerText, ?string $previousAssistant, bool $customerAlreadySpoke): bool
    {
        if ($customerAlreadySpoke || $previousAssistant === null) {
            return false;
        }

        $previousAssistant = trim($previousAssistant);
        if ($previousAssistant === ''
            || ! preg_match('/يسعد اوقاتك|شو الاخبار/u', $previousAssistant)
            || str_contains($previousAssistant, 'مصوت ولا كيف')) {
            return false;
        }

        if ((self::voteGroup($customerText) !== null && ! self::isBareNo($customerText)) || self::isPushback($customerText)) {
            return false;
        }

        if (self::isIdentityAsk($customerText)) {
            return false;
        }

        return preg_match('/مكتب|من\s*وين|وينكم|وين\s*مقر|من\s*أي\s*مكان|من\s*اي\s*مكان|عن\s*حال|بتدعمي/u', $customerText) !== 1;
    }

    private static function personalize(string $template, ?string $name): string
    {
        $name = trim((string) $name);
        $text = str_replace(
            ['{name}', '{اسم}', 'اسم الشخص'],
            $name,
            $template,
        );
        if ($name === '') {
            $text = preg_replace('/\s*اخ\s*(?=[\.،!?؟]|\s|$)/u', '', $text) ?? $text;
            $text = preg_replace('/اخ\s*(?=شو)/u', '', $text) ?? $text;
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+([،,])/u', '$1', $text) ?? $text;

        return trim($text);
    }

    private static function withoutName(string $template): string
    {
        if ($template === self::DEFAULT_OPENING || preg_match('/يسعد اوقاتك/u', $template) === 1) {
            return self::NAMELESS_OPENING;
        }

        return self::personalize($template, '');
    }

    /**
     * @return list<string>
     */
    private static function nameParts(string $name): array
    {
        $name = trim(preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $name) ?? $name);
        $name = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $name);
        $name = str_replace('ى', 'ي', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $parts = $name === '' ? [] : (preg_split('/\s+/u', $name) ?: []);
        if ($parts !== [] && preg_match('/^د[.．۔](.+)$/u', $parts[0], $doctor) === 1 && trim($doctor[1]) !== '') {
            $parts[0] = trim($doctor[1]);
        }
        $skip = ['د', 'د.', 'دكتور', 'الدكتور', 'دكتوره', 'الدكتوره', 'الحاج', 'حاج', 'الشيخ', 'شيخ', 'الاستاذ', 'استاذ', 'السيد', 'سيد'];
        while ($parts !== [] && in_array(self::foldGender($parts[0]), $skip, true)) {
            array_shift($parts);
        }

        return array_values($parts);
    }

    private static function foldGender(string $word): string
    {
        $word = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $word);
        $word = str_replace(['ى', 'ة'], ['ي', 'ه'], $word);

        return $word;
    }

    /**
     * @return array<string, true>
     */
    private static function femaleGiven(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }

        $names = 'مريم زينب ليلي رنا هدي مني سلمي لينا امل ايمان هنا سوسن بتول لمي عبير نسرين دانا تالا يارا جني هلا الاء اسماء شيماء لمياء علياء وفاء هيفاء رجاء حسناء ميساء عبلاء غيداء بيداء مرح جود ليان ليال بيان رزان رند شذي دينا ديما ياسمين حنان اماني سحر رولا سمر سهي وئام ولاء ريما ريماس حلا احلام انتصار انعام فدوي سلوي نجوي منال سعاد زهور ابتسام افراح هند دعد عنود لجين جوري ميلا لين ميس ايه هيا تيا لارا نانسي الين ماريا جوليا كريستين ساندرا ميرا رغد شيرين هديل رنين كوثر مرام ايات نور فاطمه ساره خديجه عائشه نوره هبه لينه غاده خوله اميمه رقيه حليمه جميله سميره نبيله كريمه صفيه امنه زهره اسراء زهراء';
        $set = array_fill_keys(preg_split('/\s+/u', $names) ?: [], true);

        return $set;
    }

    /**
     * Masculine names that look feminine because they end with ة or اء.
     *
     * @return array<string, true>
     */
    private static function maleGiven(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }

        $names = 'حمزه طلحه عطيه عبيده حذيفه عكاشه معاويه اسامه عروه قبيصه حنظله عنتره علقمه علاء بهاء ضياء';
        $set = array_fill_keys(preg_split('/\s+/u', $names) ?: [], true);

        return $set;
    }

    public static function isPermissionAsk(?string $previousAssistant): bool
    {
        return $previousAssistant !== null && str_contains($previousAssistant, 'في مجال');
    }

    /**
     * After «في مجال», agree or refuse both lead to one question: voting or not on 27.10.
     */
    public static function voteYesNoInstruction(string $gender = 'male', ?string $name = null): string
    {
        $cue = $gender === 'female' ? self::VOTE_YES_NO_CUE_FEMALE : self::VOTE_YES_NO_CUE;

        return 'جاوب على سؤال المجال بجملة شامية دافية قصيرة. سواء وافق أو رفض، لا تعتبري الرفض مقاطعة ولا تسكّري الشات. اسألي عن نيّة التصويت بهالانتخابات بصياغة لطيفة من عندك وحسب الجنس، وخلّي كلمة الانتخابات أو التصويت موجودة. مثال للمعنى بس: '.$cue.' بلا «يا» قبل الاسم. ممنوع تسألي لمين بهالرسالة. ممنوع صيغة رح تشارك بالتصويت.';
    }

    /**
     * A voice transcript that is too broken to guess a vote from, like "يا الواحد يا".
     */
    public static function voiceTranscriptIsBroken(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $text = trim($text, " \t\n\r\0\x0B.،!?؟…");
        if ($text === '') {
            return false;
        }

        if (preg_match('/راعم|רע|ודם|ودم|المشترك|الموحدة|الموحده|ليكود|הליכוד|بصوت|أصوت|اصوت|انتخاب|בטוח|אצביע/u', $text) === 1) {
            return false;
        }

        if (preg_match('/(?:^|\s)يا\s*$/u', $text) === 1) {
            return true;
        }

        if (str_contains($text, '...') || str_contains($text, '…')) {
            return true;
        }

        $words = preg_split('/\s+/u', $text) ?: [];

        return count($words) <= 3 && preg_match('/^يا\b/u', $text) === 1;
    }

    /**
     * Drop the "please type, the voice is unclear" line from a typed reply.
     */
    public static function withoutVoiceComplaint(string $text): string
    {
        $parts = preg_split('/(?<=[\.!\?؟])\s+/u', trim($text)) ?: [];
        $kept = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/صوت.{0,24}واضح|واضح.{0,12}صوت|لو تكتب/u', $part) === 1) {
                continue;
            }
            $kept[] = $part;
        }

        $clean = trim(implode(' ', $kept));

        return $clean !== '' ? $clean : 'وضّحلي شو قصدك لو سمحت.';
    }

    /**
     * A brush-off like "شو دخلك" or "شو خصك ليش بتسأل".
     */
    /**
     * Said they will vote, without naming a list and without refusing.
     */
    public static function affirmsVoting(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || self::voteGroup($text) === 'not_voting') {
            return false;
        }

        return preg_match('/رح\s*(?:أ|ا|ي)?صو|ب(?:أ|ا)?صوت|بصوّت|ناوي\s*(?:أ|ا)?صو|بناوي\s*(?:أ|ا)?صو|אצביע|מצביע/u', $text) === 1
            && preg_match('/ما\s*رح|مش\s*رح|لا\s*رح|ما\s*ب(?:أ|ا)?صوت|مش\s*ب(?:أ|ا)?صوت|לא\s*אצביע/u', $text) !== 1;
    }

    public static function isPushback(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return false;
        }

        return preg_match('/(?:شو|ما|مش|مو)\s*(?:دخلك|خصك|تخصك)ش?|(?:^|\s)مدخلكش?|ليش\s*(?:عم\s*)?(?:بتسأل|بتسال|تسأل|تسال|سألت)|ما\s*(?:الك|إلك)\s*(?:علاق|دعو)|حل[يى]\s*عن[يى]|بدي?ش\s*(?:ا|أ)?(?:قلك|حكي|حكيلك)|(?:لا|ما)?\s*تبعث(?:يلي)?ش|מה\s*אכפת|למה\s*(?:אתה|את)\s*שואל/u', $text) === 1;
    }

    /**
     * A fixed reply for identity questions, a brush-off, or a clear vote. Null lets the model talk.
     */
    public static function scriptedReply(string $text, ?string $previousAssistant = null): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/مين\s*(معي|معك|انت|انتي|أنت)|من\s*معي/u', $text) === 1) {
            return self::WHO_REPLY;
        }

        if (preg_match('/مكتب|مين\s*انت|من\s*طرف\s*مين|لمين\s*بتشتغل|لمن\s*بتشتغل/u', $text) === 1) {
            return self::OFFICE_REPLY;
        }

        if (preg_match('/من\s*أي\s*مكان|من\s*اي\s*مكان|من\s*وين|وينكم|وين\s*مقر/u', $text) === 1) {
            return self::PLACE_REPLY;
        }

        if (preg_match('/عن\s*حال|بتدعمي|مع\s*مين/u', $text) === 1) {
            return self::STANCE_REPLY;
        }

        if (self::isPushback($text)) {
            return self::previousIsPushbackAsk($previousAssistant)
                ? self::pushbackClose(null, 'male')
                : self::pushbackAsk(null, 'male');
        }

        if (self::isGreeting($text) && self::previousIsOpening($previousAssistant)) {
            return self::FOLLOW_UP_EXAMPLE;
        }

        if (self::isBareNo($text)) {
            if (self::previousAskedAboutVoting($previousAssistant) && ! self::previousIsBoycottQuestion($previousAssistant)) {
                return self::BOYCOTT_REPLY;
            }

            return null;
        }

        $group = self::voteGroup($text);
        if ($group === 'not_voting' && self::isBareNo($text)) {
            if (self::previousIsBoycottQuestion($previousAssistant) || ! self::previousAskedAboutVoting($previousAssistant)) {
                return null;
            }

            return self::BOYCOTT_REPLY;
        }

        return match ($group) {
            'certain' => self::UNITED_REPLY,
            'unsure' => self::JOINT_REPLY,
            'not_voting' => self::BOYCOTT_REPLY,
            'other_side' => self::ZIONIST_REPLY,
            default => null,
        };
    }

    /**
     * United list, Joint List, boycott, or a Zionist party. Null when the vote is still unnamed.
     */
    public static function voteGroup(string $text): ?string
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (self::isOpenQuestion($normalized) && ! self::hasVoteChoice($normalized)) {
            return null;
        }

        $unitedAt = self::lastAffirmation($normalized, self::UNITED_PATTERN);
        $jointAt = self::lastAffirmation($normalized, self::JOINT_PATTERN);

        if ($unitedAt !== null || $jointAt !== null) {
            if ($jointAt === null || ($unitedAt !== null && $unitedAt > $jointAt)) {
                return 'certain';
            }

            return 'unsure';
        }

        if (preg_match('/'.self::BOYCOTT_PATTERN.'/iu', $normalized) === 1) {
            return 'not_voting';
        }

        if (preg_match('/'.self::ZIONIST_PATTERN.'/iu', $normalized) === 1) {
            return 'other_side';
        }

        return null;
    }

    /**
     * Last clear vote in the chat. A bare «لا» counts only after the voting question, not after «في مجال».
     *
     * @param  list<array{role:string,message:string}>  $turns
     * @return array{group:?string,line:string}
     */
    public static function dialogueResult(array $turns): array
    {
        $group = null;
        $line = '';
        $pushbackLine = '';
        $affirmedVote = false;
        $previousAssistant = null;

        foreach ($turns as $turn) {
            $role = (string) ($turn['role'] ?? '');
            $message = trim((string) ($turn['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            if ($role === 'assistant') {
                $previousAssistant = $message;

                continue;
            }
            if ($role !== 'user') {
                continue;
            }

            $found = self::voteGroup($message);
            if ($found === null && self::answersNoToVote($message, $previousAssistant)) {
                $found = 'not_voting';
            }
            if ($found !== null) {
                $group = $found;
                $line = $found === 'not_voting' ? self::boycottQuote($message) : $message;
                if ($found !== 'not_voting') {
                    $affirmedVote = true;
                }

                continue;
            }
            if (self::affirmsVoting($message)) {
                $affirmedVote = true;

                continue;
            }
            if (self::isPushback($message)) {
                $pushbackLine = $message;
            }
        }

        if ($group === null && ! $affirmedVote && $pushbackLine !== '') {
            return ['group' => 'refused', 'line' => $pushbackLine];
        }

        return ['group' => $group, 'line' => $line];
    }

    public static function orientationNote(string $text): string
    {
        $line = '';
        foreach (preg_split('/\n+/u', trim($text)) ?: [] as $row) {
            $row = trim((string) $row);
            if ($row !== '' && self::voteGroup($row) !== null) {
                $line = $row;
            }
        }

        return self::noteForGroup(self::voteGroup($text), $line);
    }

    public static function noteForGroup(?string $group, string $line = ''): string
    {
        $label = match ($group) {
            'certain' => 'مصوت للقائمة العربية الموحدة (רע"ם) بشكل أكيد',
            'unsure' => 'مصوت للقائمة المشتركة (ודם)',
            'not_voting' => 'مقاطع، لا يريد التصويت',
            'other_side' => 'مصوت لحزب صهيوني',
            'no_reply' => 'أُرسلت الرسالة ولم يرد',
            'refused' => 'رفض يكمل الحديث',
            default => '',
        };
        if ($label === '' || $group === 'no_reply') {
            return $label;
        }

        $line = trim($line);

        return $line !== '' ? $label.'. قال: '.$line : $label.'.';
    }

    public static function answersNoToVote(string $text, ?string $previousAssistant): bool
    {
        if (self::isPermissionAsk($previousAssistant) || ! self::previousAskedAboutVoting($previousAssistant)) {
            return false;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (preg_match('/^(?:لا|لأ|لاء|لاا+|لا والله|لأ والله|لا ابدا|لا أبدا|مش|ما رح|مش رح|ما بدي|مش بدي|ما بديش|مش ناوي|ما ناوي)(?:\s*[\.\!\?؟])*$/u', $text) === 1) {
            return true;
        }

        if (preg_match('/(?:لا|لأ)\s*(?:أعوذ|اعوذ)\s+بالله/u', $text) === 1) {
            return true;
        }

        return preg_match('/ولا واحد|ما بستاهل|مش بستاهل|ما بستاهلوا/u', $text) === 1;
    }

    public static function boycottQuote(string $message): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        if (preg_match('/ولا واحد|بستاهل/u', $text) === 1) {
            return 'ولا واحد منهم بستاهل';
        }
        if (preg_match('/(?:أعوذ|اعوذ)\s+بالله/u', $text) === 1) {
            return 'لا أعوذ بالله';
        }
        if (mb_strlen($text) > 80) {
            return rtrim(mb_substr($text, 0, 77)).'…';
        }

        return $text;
    }

    private static function lastAffirmation(string $text, string $pattern): ?int
    {
        if (preg_match_all('/'.$pattern.'/iu', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $last = null;
        foreach ($matches[0] as $hit) {
            $offset = (int) $hit[1];
            $before = substr($text, 0, $offset);
            if (preg_match('/(?:^|\s)(?:مش|ما|לא)\s*$/u', $before) === 1) {
                continue;
            }
            $last = $offset;
        }

        return $last;
    }

    private static function isBareNo(string $text): bool
    {
        return preg_match('/^(?:لا|لأ|لاء|لاا)\s*[\.\!\?؟]*$/u', $text) === 1;
    }

    private static function isGreeting(string $text): bool
    {
        return preg_match('/^(?:اهلين|أهلين|اهلا|أهلا|يا هلا|هلا|مرحبا|مرحبًا)\s*[\.\!\?؟]*$/u', $text) === 1;
    }

    private static function previousIsOpening(?string $previous): bool
    {
        return $previous !== null && preg_match('/كيف الاحوال|مصوت ولا كيف|يسعد اوقاتك|شو الاخبار/u', $previous) === 1;
    }

    private static function previousAskedAboutVoting(?string $previous): bool
    {
        return $previous !== null && ! self::isPermissionAsk($previous) && preg_match('/تصو|انتخاب/u', $previous) === 1;
    }

    private static function previousIsBoycottQuestion(?string $previous): bool
    {
        return $previous !== null && str_contains($previous, 'ليش مقاطع');
    }

    /**
     * A question about a party is not a vote. «شو رأيك بالموحدة» stays unclassified.
     */
    public static function isOpenQuestion(string $text): bool
    {
        $text = trim($text);

        return preg_match('/[?؟]|^(?:شو|مين|كيف|ليش|وين|هل|قديش)\b/u', $text) === 1;
    }

    public static function isIdentityAsk(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return preg_match('/مين\s*(معي|معك|انت|انتي|أنت)|من\s*معي|من\s*وين|وينكم|وين\s*مقر|من\s*أي\s*مكان|من\s*اي\s*مكان|مكتب|عن\s*حال|بتدعمي|مع\s*مين|شو\s*رايك|شو\s*رأيك/u', $text) === 1;
    }

    /**
     * The first brush-off still asks once whether they will vote. The second one closes.
     */
    public static function pushbackAsk(?string $name, string $gender = 'male'): string
    {
        $name = trim((string) $name);
        $who = $name !== '' ? $name.'، ' : '';
        $verb = $gender === 'female' ? 'تصوّتي' : 'تصوّت';

        return 'متأسفة '.$who.'هاد بس استفتاء للمنطقة مش اكثر. بتمنى ما ازعجتك 🙏 بس حابة أعرف إذا رح '.$verb.' ولا لا.';
    }

    public static function pushbackClose(?string $name, string $gender = 'male'): string
    {
        $name = trim((string) $name);
        $who = $name !== '' ? ' '.$name : '';
        $blessing = $gender === 'female' ? 'يعطيكي' : 'يعطيك';

        return 'متأسفة'.$who.'، '.$blessing.' العافية.';
    }

    public static function previousIsPushbackAsk(?string $previous): bool
    {
        return $previous !== null && str_contains($previous, 'استفتاء للمنطقة');
    }

    /**
     * Stop after a clear united-list vote, after they answer the one why, or after a second brush-off.
     * The first «شو دخلك» still gets one voting question. A bare «رح أصوّت» does not stop the chat.
     */
    public static function shouldPauseAfterReply(string $customerText, ?string $previousAssistant): bool
    {
        if (self::voteGroup($customerText) === 'certain') {
            return true;
        }

        if (self::isPushback($customerText) && ! self::affirmsVoting($customerText)) {
            return self::previousIsPushbackAsk($previousAssistant);
        }

        if (self::previousIsProbe($previousAssistant) && trim($customerText) !== '' && self::voteGroup($customerText) === null) {
            return true;
        }

        return self::voteGroup($customerText) === 'certain';
    }

    public static function steerInstruction(): string
    {
        return 'لسه ما في فرز واضح. جاوبِ على كلامه أولاً بجملة شامية قصيرة، وبعدين ارجعي للسؤال الناقص بسياق الحديث. سؤال واحد فقط، بصياغة مختلفة عن سؤالك السابق. لا تسكّري المحادثة ولا تكرري التحية.';
    }

    public static function closeInstruction(): string
    {
        return 'الفرز صار واضح. انهي المحادثة بجملة لطيفة قصيرة وملائمة، بلا سؤال جديد وبلا إطالة وبلا وردة إلا إذا أكّد الموحدة.';
    }

    /**
     * A silence nudge stays on the open question. It does not advance to voting.
     */
    public static function silenceNudge(?string $previousAssistant): string
    {
        return '?';
    }

    public static function revivalInstruction(): string
    {
        return 'الشخص سكت. لا تسألي سؤال جديد ولا تنتقلي للتصويت. إذا آخر سؤال كان «في مجال»، الانتظار علامة استفهام فقط.';
    }

    private static function hasVoteChoice(string $text): bool
    {
        return preg_match('/بصوت|أصوت|اصوت|رح\s*(?:أ|ا)?صو|مصوت|بناوي|ناوي\s*(?:أ|ا)?صو|مع\s*ال?موحد|للموحد|للمشترك|مع\s*ال?مشترك/u', $text) === 1;
    }

    private static function previousIsProbe(?string $previous): bool
    {
        if ($previous === null) {
            return false;
        }

        return preg_match('/شو اللي|شو السبب|ليش مقاطع|مخليك|مخلّيك|ولا في اشي|ليش هيك/u', $previous) === 1;
    }
}
