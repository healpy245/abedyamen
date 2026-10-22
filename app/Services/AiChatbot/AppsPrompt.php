<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp agent for بوت التطبيقات.
 *
 * The model writes the reply. Laravel loads history, calls the model,
 * stores memory, and checks links, secrets, and unverified claims.
 */
class AppsPrompt
{
    public const MUWAHADA_URL = 'https://play.google.com/store/apps/details?id=com.mitech.almowahada';

    public const MUWAHADA_APPLE_URL = 'https://apps.apple.com/il/app/%D8%A7%D9%84%D9%85%D9%88%D8%AD%D8%AF%D8%A9/id6790904899';

    public const ASWATKUM_APPLE_URL = 'https://apps.apple.com/il/app/%D8%A3%D8%B5%D9%88%D8%A7%D8%AA%D9%83%D9%85/id6812716211';

    public const ASWATKUM_ANDROID_URL = 'https://play.google.com/store/apps/details?id=com.mitech.aswatkom';

    public const GUIDE_VIDEO_URL = 'https://kaman-workspace.com/videos/aswatkum-guide.mp4';

    /** Splits one reply into separate WhatsApp messages. */
    public const BUBBLE = "\n\n‖\n\n";

    /** Short typing pause for every message after the opening. */
    public const TYPING_MS = 900;

    public const SCREENSHOT_MARK = '[أرسل الزبون صورة/ملف عبر WhatsApp]';

    public const NOT_REGISTERED_REPLY = 'شكله لسه ما تم تسجيلك عنا، أغلبك ابعتلي اسمك الكامل ورقم هويتك والبلد، وببعت للفريق إنهم يسجلوك.';

    public const DEFAULT_OPENING = "بتواصل معك من مركز الاتصالات في القائمة العربية الموحدة.\nبحاجة لدقيقة من وقتك 🙏 عنا خطوة واحدة لازم إتمامها استعداداً للحملة الانتخابية:\nتنزيل تطبيق «الموحدة»\nهل نزّلت تطبيق «الموحدة» على هاتفك؟";

    public const GREETING = 'مرحبا، كيف الأحوال.';

    /** Second hello for someone who never answered the first one. */
    public const FOLLOW_UP_GREETING = 'يسعد اوقاتك، شو الاخبار؟';

    public const IMPORTANCE = 'بحسب توجيهات إدارة الحملة، مهم جدا جدا التعاون الان لضمان الاستعداد ليوم الانتخابات.';

    public const HANDOFF = 'سأمرر رقمك ليتواصل معك مندوب من الحزب.';

    public const NUMBER_SOURCE_REPLY = "رقمك من قوائم كوادر القائمة العربية الموحدة، وعم بتواصل معك من مركز الاتصالات.\nالمطلوب هلق تنزيل تطبيق «الموحدة». هل نزّلته على هاتفك؟";

    public const ACCOUNT_QUESTION = 'خلص تمام. في عندك حساب داخل التطبيق قبل تسجيل الدخول؟';

    public const COLLECT_ACCOUNT = 'خلص تمام، ابعتلي اسمك الكامل والبلدة ورقم الهوية من ٩ خانات، وبس نسجلك بالنظام برجعك برسالة.';

    public const DETAILS_RECEIVED = 'خلص تمام، وصلني اسمك والبلدة ورقم الهوية. شكرا على وقتك، رح نجهزلك الحساب وبنرجعلك.';

    public const DETAILS_CHECK = 'إذا المعلومات صحيحة ابعتلي تم، وإذا في غلط ابعتلي الصح.';

    public const DETAILS_RETRY = 'ابعثلي الاسم الكامل والبلدة ورقم الهوية الصح.';

    public const LIST_QUESTION = 'تمام. هلق افتح «اللوائح الانتخابية» وجهّز لائحة الناخبين، وابعثلي تم بس تخلص.';

    public const ID_REQUIRED = 'آسفة، ما بقدر أتقدّم معك ولا أسجّلك بالنظام بدون رقم الهوية. لازم تبعثلي كل التفاصيل: اسمك الكامل، والبلدة، ورقم الهوية.';

    public const MISSING_ACCOUNT_CUE = 'هذا يعني ما في يوزر. اطلب الاسم الكامل والبلدة ورقم الهوية من ٩ خانات. ممنوع الرد بعلامة سؤال. لما يكتملوا لا تخزّنهم قبل التأكيد: ابعت الاسم والبلدة ورقم الهوية كما رح ينتخزنوا واطلب تم. بعد التأكيد فقط ختم: شكرا على وقتك، رح نجهزلك الحساب وبنرجعلك.';

    public const ACCOUNT_CREATED = 'يعطيك العافيه، جرب هلأ تعمل تسجيل دخول، وتذكر انك تعطي صلاحية كاملة لمشاركة سجل الأسماء، أنا بانتظارك ابعثلي تم لما تخلص.';

    public const ACCOUNT_CREATED_FEMALE = 'يعطيكي العافية، جربي هلأ تعملي تسجيل دخول، وتذكري انك تعطي صلاحية كاملة لمشاركة سجل الأسماء، أنا بستناكي ابعثيلي تم لما تخلصي.';

    public const FALLBACK = 'صار تأخير بسيط من عندي. ابعتلي آخر رسالة مرة ثانية وبكمل معك من نفس النقطة.';

    public const GROUP_DOWNLOADED = 'downloaded';

    public const GROUP_WAITING = 'waiting_account';

    public const GROUP_NO_REPLY = 'no_reply';

    public const GROUP_FOLLOW_UP = 'follow_up';

    public static ?Closure $testResponder = null;

    private static bool $schemaUnsupported = false;

    public static function defaultCampaignPrompt(): string
    {
        return implode("\n", [
            'أنت ممثل دعم من مركز الاتصالات في القائمة العربية الموحدة على واتساب. لهجة فلسطينية قصيرة ومهنية، غالباً جملة لثلاث. قول هلق أو هلأ، وممنوع كلمة هسه. لا تقول إنك بوت، ولا تحط «يا» قبل الاسم.',
            'المهمة الوحيدة: تنزيل تطبيق «الموحدة» فقط. ممنوع ذكر تطبيق أصواتكم، وممنوع لائحة الناخبين، وممنوع الحساب أو اسم المستخدم أو رقم الهوية أو صور الشاشات أو فيديو الشرح. إذا الشخص حكى عن أصواتكم أو اللائحة أو الحساب، لا تكمل هالمسار. رجّعه بهدوء إن المطلوب هلق بس تنزيل الموحدة.',
            'أول رسالة سلام فقط، واحدة من اثنتين: مرحبا والاسم وكيف الأحوال، أو يسعد اوقاتك اخ أو اخت والاسم وشو الاخبار. وتستنى الرد. بعد ما يرد، كمّل بسؤال تنزيل الموحدة من غير ما تعيد السلام عليكم ولا الاسم. لا تعيد السلام إذا انبعت.',
            'بفهم عربي فلسطيني وعبري وإنجليزي وخلطهم. נכון وכן وyes وcorrect تأكيد مش اسم. «كمل» يعني كمّل تنزيل الموحدة. «اندرويد» نوع الجهاز. «لعا» و«لسا» و«لسه» تعني لسّا. «منزلش» و«نزلتش» و«ما نزلتش» تعني ما نزّل الموحدة: ابعت الرابطين ولا تعيد سؤال التنزيل. «كلشي نزله» أو «جاهز» أو «نزلته» يعني نزّل الموحدة. لا تعيد آخر رسالة إذا الشخص جاوب.',
            'رابط الموحدة ينبعت الاثنين مع بعض بنفس الرسالة، آيفون وأندرويد، ولا تسأل آيفون ولا أندرويد. إذا قال ما نزّل، ابعت الرابطين واطلب يبعت تم بس يخلّص. لما يقول إنه نزّل الموحدة أو يبعت تم بعد الرابط، اشكره وخلّص بلا سؤال ومن غير فيديو. لا تطلب يفتح الموحدة، ولا تسأل شو ظاهر على الشاشة، ولا تكمل أي خطوة جوّاها.',
            '«ضروري هسه؟» سؤال استعجال مش رفض. الجواب إن تنزيل الموحدة مطلوب اليوم، بجملة قصيرة، ومن غير روابط إذا ما طلبها.',
            'بدنا نخلّص تنزيل الموحدة اليوم بأسرع وقت استعداداً ليوم الانتخابات، من غير ما نأجل. إذا قال بشتغل عليه اليوم أو بعدين أو مشغول أو لما أفضى، هذا مش إنهاء ومش وداع. ممنوع تقول «بنكمّل لما تفضى» أو «يعطيك العافية» أو تقفل المحادثة. جاوبه بهدوء: مع اقتراب يوم الانتخابات، مهم جداً اليوم تنزيل تطبيق الموحدة لنكون بكامل الجاهزية. إذا قال إنه عارف، لا تعيد الشرح، واطلب يبعت تم بس ينزّل. إذا عم يسوق هلق، لا تطلب يستخدم التلفون وهو سائق، بس ذكّره يبعث تم أول ما يوقف. إذا شكرك والمهمة لسا مفتوحة، ارجعه لنفس الطلب.',
            'احترم الرفض أو الإيقاف أو طلب شخص من أول مرة، من غير ضغط. لا تطلب كلمة سر أو رمز أو رقم هوية. طلب الشخص يعني قال بدي أحكي مع شخص أو حوّلني. «تفضل» لحاله مش طلب تحويل.',
            'إذا سأل مين عطاك رقمي أو من وين رقمك أو مصدر الرقم، جاوب بهالجملة وبالضبط: رقمك من قوائم كوادر القائمة العربية الموحدة، وعم بتواصل معك من مركز الاتصالات. وبعدين بنفس الرسالة كمّل: المطلوب هلق تنزيل تطبيق «الموحدة». هل نزّلته على هاتفك؟ ممنوع تعرض تحويل، وممنوع تقول بحوّلك لشخص، وممنوع توقف المحادثة بسبب هالسؤال.',
            'لا تقنع الشخص سياسياً ولا تسأله لمين بصوّت. إذا طلع عن المهمة، جاوب بجملة قصيرة إذا السؤال عن الرقم، وارجع فوراً لطلب تنزيل الموحدة. لا تحوّل المحادثة لشخص إلا إذا طلب هو شخص من أول مرة.',
        ]);
    }

    /**
     * @return list<string>
     */
    public static function bubbles(string $text): array
    {
        $parts = preg_split('/\n*‖\n*/u', $text) ?: [];
        $parts = array_values(array_filter(
            array_map(static fn ($part): string => trim((string) $part), $parts),
            static fn (string $part): bool => $part !== '',
        ));

        return $parts !== [] ? $parts : [trim($text)];
    }

    /**
     * @return array{text:string,video:?string}
     */
    public static function videoDelivery(string $text, string $videoUrl): array
    {
        $urls = array_values(array_unique(array_filter([
            trim($videoUrl),
            self::GUIDE_VIDEO_URL,
        ])));
        foreach ($urls as $url) {
            if (! str_contains($text, $url)) {
                continue;
            }
            $caption = trim((string) preg_replace('/\s*'.preg_quote($url, '/').'\s*/u', "\n", $text));
            $caption = trim((string) preg_replace("/\n{3,}/u", "\n\n", $caption));

            return ['text' => $caption, 'video' => $url];
        }

        return ['text' => $text, 'video' => null];
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @param  array{aswatkum?:string,muwahada?:string,video?:string}  $links
     * @param  array<string, mixed>  $memory
     * @return array{text:string,pause:bool,memory:array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}}
     */
    public static function nextReply(array $turns, ?string $name, array $links, array $memory = [], ?ChatbotInstance $instance = null, bool $live = false): array
    {
        if (app()->runningUnitTests()) {
            self::$schemaUnsupported = false;
        }

        $links = self::normalizeLinks($links);
        $verified = (bool) data_get($memory, 'verified.account', false);
        $memory = self::sanitizeMemory($memory);
        $turns = self::currentCampaign(self::transcript($turns));
        if (self::latestUser($turns) === '') {
            return self::fallback($turns, $memory, $links, 'empty-user');
        }

        if (self::shouldSendOpening($turns)) {
            $text = $instance instanceof ChatbotInstance
                ? self::openingFor($instance, $name)
                : self::openingForName($name);

            return ['text' => $text, 'pause' => false, 'memory' => $memory];
        }

        $parsed = self::attempt(self::messages($turns, $name, $links, $memory, $verified), $memory, $live);
        if ($parsed === null) {
            return self::fallback($turns, $memory, $links, 'unusable-model');
        }

        $guarded = self::guard($parsed['text'], $links, $verified, $parsed['pause']);
        if ($guarded['text'] === '') {
            return self::fallback($turns, $memory, $links, 'empty-after-guard');
        }

        $latest = self::latestUser($turns);
        if (! self::wantsAPerson($latest) && (self::asksNumberSource($latest) || self::isNumberHandoff($guarded['text']))) {
            $guarded = ['text' => self::NUMBER_SOURCE_REPLY, 'pause' => false];
        }

        return [
            'text' => $guarded['text'],
            'pause' => $guarded['pause'] || str_contains($guarded['text'], self::HANDOFF),
            'memory' => $parsed['memory'],
        ];
    }

    /**
     * @return array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>,verified:array{account:bool}}
     */
    public static function memoryOf(ChatbotConversation $conversation): array
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $memory = self::sanitizeMemory(is_array($meta['apps_memory'] ?? null) ? $meta['apps_memory'] : []);
        $memory['verified'] = [
            'account' => (bool) data_get($meta, 'apps_verified.account', false),
        ];

        return $memory;
    }

    public static function memoryVersion(ChatbotConversation $conversation): int
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];

        return (int) ($meta['apps_memory_version'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    public static function storeMemory(ChatbotConversation $conversation, array $memory, ?int $basedOnVersion = null): bool
    {
        $saved = false;
        DB::transaction(function () use ($conversation, $memory, $basedOnVersion, &$saved): void {
            $fresh = ChatbotConversation::query()->lockForUpdate()->find($conversation->id);
            if (! $fresh instanceof ChatbotConversation) {
                return;
            }
            $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
            $current = (int) ($meta['apps_memory_version'] ?? 0);
            if ($basedOnVersion !== null && $basedOnVersion !== $current) {
                return;
            }
            $meta['apps_memory'] = self::sanitizeMemory($memory);
            $meta['apps_memory_version'] = $current + 1;
            $fresh->forceFill(['metadata' => $meta])->save();
            $conversation->setRawAttributes($fresh->getAttributes(), true);
            $saved = true;
        });

        return $saved;
    }

    public static function accountCreatedFor(?string $name): string
    {
        return MansourPrompt::isFemaleName($name) ? self::ACCOUNT_CREATED_FEMALE : self::ACCOUNT_CREATED;
    }

    public static function isAccountCreated(string $text): bool
    {
        return $text === self::ACCOUNT_CREATED || $text === self::ACCOUNT_CREATED_FEMALE;
    }

    public static function recordAccountVerified(ChatbotConversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            $fresh = ChatbotConversation::query()->lockForUpdate()->find($conversation->id);
            if (! $fresh instanceof ChatbotConversation) {
                return;
            }
            $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
            $meta['apps_verified'] = ['account' => true];
            $fresh->forceFill(['metadata' => $meta])->save();
            $conversation->setRawAttributes($fresh->getAttributes(), true);
        });
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     */
    public static function nudge(array $turns): string
    {
        $turns = self::currentCampaign($turns);
        if (self::isFinished($turns)) {
            return '';
        }

        for ($i = count($turns) - 1; $i >= 0; $i--) {
            $text = trim((string) ($turns[$i]['message'] ?? ''));
            if (($turns[$i]['role'] ?? '') !== 'assistant') {
                return '';
            }
            if ($text === '' || $text === '؟' || $text === '?') {
                continue;
            }

            return self::waitingOnAnswer($text) ? '؟' : '';
        }

        return '';
    }

    private static function waitingOnAnswer(string $text): bool
    {
        if (preg_match('/سواق|مكان آمن|بنكمل لما|بنرجعلك|نجهزلك الحساب/u', $text) === 1) {
            return false;
        }

        return preg_match('/[؟?]\s*$/u', $text) === 1;
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     */
    public static function isFinished(array $turns): bool
    {
        $turns = self::currentCampaign($turns);
        if (self::waitingOnStaff($turns)) {
            return true;
        }

        $seen = self::observe($turns);

        return $seen['muwahada'] || $seen['handoff'];
    }

    /**
     * Details are in and the next step is ours. A silence nudge must not go out.
     *
     * @param  list<array{role:string,message:string}>  $turns
     */
    public static function waitingOnStaff(array $turns): bool
    {
        $turns = self::currentCampaign($turns);
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if (($turns[$i]['role'] ?? '') !== 'assistant') {
                continue;
            }
            $text = trim((string) ($turns[$i]['message'] ?? ''));
            if ($text === '' || $text === '؟' || $text === '?') {
                continue;
            }

            return str_contains($text, 'نجهزلك الحساب') || str_contains($text, 'بنرجعلك');
        }

        return false;
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @param  array{account?:bool}  $context
     * @return array{group:?string,groups:list<string>,note:string}
     */
    public static function result(array $turns, array $context = []): array
    {
        $presence = self::presence($turns);
        if ($presence['assistant'] && ! $presence['user']) {
            return ['group' => self::GROUP_NO_REPLY, 'groups' => [self::GROUP_NO_REPLY], 'note' => 'أُرسلت الرسالة ولم يرد'];
        }
        if (! $presence['user']) {
            return ['group' => null, 'groups' => [], 'note' => ''];
        }

        $seen = self::observe($turns, (bool) ($context['account'] ?? false));
        $groups = [];
        if ($seen['downloaded']) {
            $groups[] = self::GROUP_DOWNLOADED;
        }
        if ($seen['waiting']) {
            $groups[] = self::GROUP_WAITING;
        }
        if ($seen['handoff']) {
            $groups[] = self::GROUP_FOLLOW_UP;
        }

        $note = 'تنزيل: '.self::mark($seen['downloaded'] ? 'yes' : ($seen['refused_app'] ? 'no' : null))
            .' | حساب: '.self::mark($seen['has_account'] ? 'yes' : ($seen['waiting'] ? 'no' : null))
            .' | لائحة: '.self::mark($seen['list']);

        return ['group' => $groups[0] ?? null, 'groups' => $groups, 'note' => $note];
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @param  array{account?:bool}  $context
     * @return array{
     *     contacted:bool,
     *     interacted:bool,
     *     no_reply:bool,
     *     aswatkum_downloaded:bool,
     *     aswatkum_interacted:bool,
     *     waiting_registration:bool,
     *     waiting_delegate:bool,
     *     muwahada_downloaded:bool,
     *     muwahada_interacted:bool,
     *     muwahada_waiting_registration:bool,
     *     muwahada_waiting_delegate:bool
     * }
     */
    public static function profile(array $turns, array $context = []): array
    {
        $presence = self::presence($turns);
        $blank = [
            'contacted' => $presence['assistant'] || $presence['user'],
            'interacted' => false,
            'no_reply' => $presence['assistant'] && ! $presence['user'],
            'aswatkum_downloaded' => false,
            'aswatkum_interacted' => false,
            'waiting_registration' => false,
            'waiting_delegate' => false,
            'muwahada_downloaded' => false,
            'muwahada_interacted' => false,
            'muwahada_waiting_registration' => false,
            'muwahada_waiting_delegate' => false,
        ];
        if (! $presence['user']) {
            return $blank;
        }

        $seen = self::observe($turns, (bool) ($context['account'] ?? false));

        return [
            'contacted' => true,
            'interacted' => true,
            'no_reply' => false,
            'aswatkum_downloaded' => $seen['downloaded'],
            'aswatkum_interacted' => true,
            'waiting_registration' => $seen['waiting'],
            'waiting_delegate' => $seen['handoff'],
            'muwahada_downloaded' => $seen['muwahada'],
            'muwahada_interacted' => $seen['muwahada'],
            'muwahada_waiting_registration' => $seen['waiting'] && ! $seen['muwahada'],
            'muwahada_waiting_delegate' => $seen['handoff'] && ! $seen['muwahada'],
        ];
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @param  array{account?:bool}  $context
     * @return array{app:?string,username:?string,list:?string,details:string,done:bool,link_sent:bool,awaiting:string,handed_off:bool,muwahada:?string}
     */
    public static function read(array $turns, array $context = []): array
    {
        $seen = self::observe($turns, (bool) ($context['account'] ?? false));

        return [
            'app' => $seen['downloaded'] ? 'yes' : ($seen['refused_app'] ? 'no' : null),
            'username' => $seen['has_account'] ? 'yes' : ($seen['waiting'] ? 'no' : null),
            'list' => $seen['list'],
            'details' => '',
            'done' => $seen['muwahada'] || $seen['handoff'],
            'link_sent' => $seen['muwahada'],
            'awaiting' => $seen['muwahada'] ? 'muwahada' : '',
            'handed_off' => $seen['handoff'],
            'muwahada' => $seen['muwahada'] ? 'yes' : null,
        ];
    }

    public static function greetingFor(?string $name): string
    {
        $person = self::spokenName($name);
        if ($person === '') {
            return self::GREETING;
        }

        return 'مرحبا '.$person.'، كيف الأحوال.';
    }

    /**
     * Hello for a number that was already greeted and never answered.
     * Male: يسعد اوقاتك اخ الاسم شو الاخبار. Female: اخت. A kunya keeps every word.
     */
    public static function followUpGreeting(?string $name): string
    {
        $person = self::spokenName($name);
        if ($person === '') {
            return self::FOLLOW_UP_GREETING;
        }
        $kin = MansourPrompt::isFemaleName($name) ? 'اخت' : 'اخ';

        return 'يسعد اوقاتك '.$kin.' '.$person.' شو الاخبار';
    }

    /**
     * The greeting was sent and the person answered. The campaign opening comes next.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public static function shouldSendOpening(array $turns): bool
    {
        $turns = self::currentCampaign($turns);
        $userSpoke = false;
        $sawGreeting = false;
        foreach ($turns as $turn) {
            $text = trim((string) ($turn['message'] ?? ''));
            if (($turn['role'] ?? '') !== 'assistant') {
                if ($text !== '') {
                    $userSpoke = true;
                }

                continue;
            }
            if ($text === '' || $text === '؟' || $text === '?') {
                continue;
            }
            if (str_contains($text, 'هل نزّلت') || str_contains($text, 'ثلاث خطوات')) {
                return false;
            }
            if (! self::isGreeting($text)) {
                return false;
            }
            $sawGreeting = true;
        }

        return $userSpoke && $sawGreeting;
    }

    /**
     * A new trigger starts at its greeting. Older أصواتكم and voter-list turns stay out of the reply.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     * @return list<array{role?:string,message?:string}>
     */
    private static function currentCampaign(array $turns): array
    {
        $start = null;
        foreach ($turns as $index => $turn) {
            if (($turn['role'] ?? '') !== 'assistant') {
                continue;
            }
            $text = trim((string) ($turn['message'] ?? ''));
            if ($text === '' || $text === '؟' || $text === '?') {
                continue;
            }
            if (self::isGreeting($text)) {
                $start = $index;
            }
        }
        if ($start === null || $start === 0) {
            return $turns;
        }

        return array_values(array_slice($turns, $start));
    }

    public static function openingFor(ChatbotInstance $instance, ?string $name): string
    {
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $delegate = trim((string) ($settings['delegate_name'] ?? ''));

        return self::openingForName($name, $delegate);
    }

    public static function openingForName(?string $name, string $delegate = ''): string
    {
        $text = self::DEFAULT_OPENING;
        if ($delegate !== '') {
            $text = str_replace('معك من مركز', 'معك '.$delegate.' من مركز', $text);
        }

        return trim($text);
    }

    /**
     * @return array{aswatkum:string,muwahada:string,video:string}
     */
    public static function links(ChatbotInstance $instance): array
    {
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];

        return self::normalizeLinks([
            'aswatkum' => (string) ($settings['aswatkum_url'] ?? ''),
            'muwahada' => (string) ($settings['muwahada_url'] ?? ''),
            'video' => (string) ($settings['guide_video_url'] ?? ''),
        ]);
    }

    /**
     * The model failed. Stay on the current step instead of sending the delay line.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}  $memory
     * @param  array{aswatkum:string,muwahada:string,video:string}  $links
     * @return array{text:string,pause:bool,memory:array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}}
     */
    private static function fallback(array $turns, array $memory, array $links, string $reason): array
    {
        Log::warning('apps-agent.fallback', ['reason' => $reason]);

        return ['text' => self::FALLBACK, 'pause' => false, 'memory' => $memory];
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{aswatkum:string,muwahada:string,video:string}  $links
     */
    private static function continueTask(array $turns, array $links): string
    {
        if (self::registrationReceived($turns)) {
            return self::detailsPreview($turns);
        }
        if (self::detailsAccepted($turns)) {
            return self::DETAILS_RECEIVED;
        }
        if (self::detailsRejected($turns)) {
            return self::DETAILS_RETRY;
        }

        $latest = self::latestUser($turns);
        $previous = self::previousAssistant($turns);
        $topic = $previous === '' ? null : self::questionTopic($previous);

        if ($topic === 'download' && self::isNo($latest)) {
            return self::downloadLinksText();
        }
        if ($topic === 'download' && self::affirmsDownload($latest)) {
            return self::ACCOUNT_QUESTION;
        }
        if ($topic === 'account' && self::isNo($latest)) {
            return self::COLLECT_ACCOUNT;
        }
        if ($topic === 'account' && self::isYes($latest)) {
            return self::LIST_QUESTION;
        }
        if ($topic === 'list' && self::isYes($latest)) {
            return self::muwahadaLinks($links);
        }
        if (self::reportsMissingAccount($latest) || preg_match('/يوزر|اسم مستخدم/u', $latest) === 1) {
            return self::COLLECT_ACCOUNT;
        }
        if ($previous !== '' && $previous !== self::FALLBACK) {
            return $previous;
        }

        return self::openingForName(null);
    }

    /**
     * The person already answered the account request with a name and an ID.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function registrationReceived(array $turns): bool
    {
        $previous = self::previousAssistant($turns);
        if (preg_match('/رقم الهوية|اسمك الكامل|اسم مستخدم/u', $previous) !== 1) {
            return false;
        }

        $latest = self::latestUser($turns);
        if (self::isYes($latest) || self::isNo($latest)) {
            return false;
        }

        return self::detailsIn($latest) || (self::identityNumber($turns) !== '' && preg_match('/\p{L}{2,}/u', $latest) === 1);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function detailsAccepted(array $turns): bool
    {
        return str_contains(self::previousAssistant($turns), self::DETAILS_CHECK)
            && self::isYes(self::latestUser($turns));
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function detailsRejected(array $turns): bool
    {
        return str_contains(self::previousAssistant($turns), self::DETAILS_CHECK)
            && self::isNo(self::latestUser($turns));
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function detailsPreview(array $turns): string
    {
        $parts = self::splitDetails(self::latestUser($turns));
        if ($parts['id'] === '') {
            $parts['id'] = self::identityNumber($turns);
        }

        return "خلص تمام، هاد اللي رح نخزّنه:\n"
            .'الاسم: '.($parts['name'] !== '' ? $parts['name'] : '—')."\n"
            .'البلدة: '.($parts['town'] !== '' ? $parts['town'] : '—')."\n"
            .'رقم الهوية: '.$parts['id']."\n"
            .self::DETAILS_CHECK;
    }

    /**
     * @return array{name:string,town:string,id:string}
     */
    private static function splitDetails(string $text): array
    {
        $id = '';
        if (preg_match('/(?<!\d)(\d{9})(?!\d)/u', $text, $match) === 1
            && ! (str_starts_with($match[1], '5') && preg_match('/هوي/u', $text) !== 1)) {
            $id = $match[1];
        }

        $words = preg_split('/\s+/u', trim((string) (preg_replace('/\d+/u', ' ', $text) ?? ''))) ?: [];
        $words = array_values(array_filter(
            $words,
            static fn (string $word): bool => preg_match('/\p{L}{2,}/u', $word) === 1,
        ));
        $split = count($words) > 1 ? count($words) - 1 : count($words);
        foreach ($words as $index => $word) {
            if ($index > 0 && in_array($word, ['كفر', 'عين', 'دير', 'بيت', 'وادي', 'رأس', 'راس', 'عرب', 'خربة', 'أم', 'ام'], true)) {
                $split = $index;
                break;
            }
        }

        return [
            'name' => implode(' ', array_slice($words, 0, $split)),
            'town' => implode(' ', array_slice($words, $split)),
            'id' => $id,
        ];
    }

    private static function detailsIn(string $text): bool
    {
        if (preg_match('/(?<!\d)(\d{9})(?!\d)/u', $text, $match) !== 1) {
            return false;
        }
        if (str_starts_with($match[1], '5') && preg_match('/هوي/u', $text) !== 1) {
            return false;
        }

        $words = preg_replace('/\d+/u', ' ', $text) ?? '';

        return preg_match_all('/\p{L}{2,}/u', $words) >= 2;
    }

    private static function downloadLinksText(): string
    {
        return 'نزّل تطبيق «أصواتكم» هلق، هاي الروابط:'."\n"
            .'آيفون: '.self::ASWATKUM_APPLE_URL."\n"
            .'أندرويد: '.self::ASWATKUM_ANDROID_URL."\n"
            .'وبس تنزّله ابعتلي تم.';
    }

    /**
     * @param  array{aswatkum:string,muwahada:string,video:string}  $links
     */
    private static function muwahadaLinks(array $links): string
    {
        return 'هلق دور تطبيق «الموحدة»، هاي الروابط:'."\n"
            .'آيفون: '.self::MUWAHADA_APPLE_URL."\n"
            .'أندرويد: '.$links['muwahada']."\n"
            .'وبس تنزّله ابعتلي تم.';
    }

    /**
     * @param  array{aswatkum?:string,muwahada?:string,video?:string}  $links
     * @return array{aswatkum:string,muwahada:string,video:string}
     */
    private static function normalizeLinks(array $links): array
    {
        return [
            'aswatkum' => self::url((string) ($links['aswatkum'] ?? '')) ?: self::ASWATKUM_ANDROID_URL,
            'muwahada' => self::url((string) ($links['muwahada'] ?? '')) ?: self::MUWAHADA_URL,
            'video' => self::url((string) ($links['video'] ?? '')) ?: self::GUIDE_VIDEO_URL,
        ];
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     * @param  array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}  $memory
     * @return ?array{text:string,pause:bool,memory:array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}}
     */
    private static function attempt(array $messages, array $memory, bool $live = false): ?array
    {
        $structured = ! self::$schemaUnsupported;
        $result = self::complete($messages, $structured, $live);

        if ($result['error'] === 'compat') {
            self::$schemaUnsupported = true;
            $result = self::complete($messages, false, $live);

            return self::parse($result['content'], $memory);
        }

        if (self::shouldRetryTransport($result)) {
            $result = self::complete($messages, ! self::$schemaUnsupported, $live);

            return self::parse($result['content'], $memory);
        }

        $parsed = self::parse($result['content'], $memory);
        if ($parsed !== null || $result['error'] === 'http') {
            return $parsed;
        }

        $messages[] = [
            'role' => 'user',
            'content' => 'Previous output was not valid JSON. Return only the required JSON object with reply, pause, and memory.',
        ];
        $second = self::complete($messages, ! self::$schemaUnsupported, $live);

        return self::parse($second['content'], $memory);
    }

    /**
     * @param  array{error:?string,content:?string,status:?int}  $result
     */
    private static function shouldRetryTransport(array $result): bool
    {
        if (in_array($result['error'], ['timeout', 'empty'], true)) {
            return true;
        }

        $status = (int) ($result['status'] ?? 0);

        return $result['error'] === 'http' && ($status >= 500 || $status === 429);
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @param  array{aswatkum:string,muwahada:string,video:string}  $links
     * @param  array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}  $memory
     * @return list<array{role:string,content:string}>
     */
    private static function messages(array $turns, ?string $name, array $links, array $memory, bool $verified): array
    {
        $history = self::fullHistory($turns);
        $person = self::arabicName($name);
        $payload = [
            ['role' => 'system', 'content' => self::defaultCampaignPrompt()],
            ['role' => 'system', 'content' => self::contextBlock($links, $verified, $person)],
            ['role' => 'system', 'content' => self::memoryBlock($memory)],
        ];
        $payload[] = ['role' => 'system', 'content' => 'المحادثة تحت كاملة وبالترتيب، من أول رسالة لآخر كلمة. اقرأها كلها قبل الرد، ولا تحكم من آخر جملة لحالها. الرد لهجة فلسطينية طبيعية ومنطقية حسب السياق. ارجع JSON فقط: {"reply":"نص الواتساب","pause":false,"memory":{"facts":[],"open":[],"links_sent":[],"preferences":[]}}. facts من كلام الشخص فقط، من غير أرقام هوية أو هاتف أو كلمات سر. links_sent قيمتها muwahada فقط. pause true فقط إذا طلب شخص أو قال وقف. سؤال مصدر الرقم pause false، والجواب يرجع لتنزيل الموحدة. ممنوع تطلب رقم هوية أو اسم مستخدم أو لائحة ناخبين.'];
        foreach ($history as $turn) {
            $content = self::turnContent($turn);
            $payload[] = [
                'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $payload;
    }

    private static function reportsMissingAccount(string $text): bool
    {
        $missing = preg_match('/مش موجود|مو موجود|غير موجود/u', $text) === 1;
        $identity = preg_match('/هوي[ةه]/u', $text) === 1;
        $database = preg_match('/قاعدة|قاعده/u', $text) === 1;

        return ($identity && $missing) || ($missing && $database);
    }

    /**
     * @param  array{role?:string,message?:string,image?:string}  $turn
     * @return string|list<array<string, mixed>>
     */
    private static function turnContent(array $turn): string|array
    {
        $text = (string) ($turn['message'] ?? '');
        $image = $turn['image'] ?? null;
        if (! is_string($image) || ! str_starts_with($image, 'data:image/')) {
            return $text;
        }

        $instruction = 'صورة. لا تشرح شاشات أصواتكم ولا لائحة الناخبين ولا تطلب رقم هوية ولا تطلب صورة جديدة. المهمة الوحيدة تنزيل تطبيق الموحدة. إذا لسا ما نزّلها، ابعت رابط الآيفون ورابط الأندرويد. إذا نزّلها، اشكره وخلّص.';
        $caption = trim($text);
        if ($caption === '' || $caption === self::SCREENSHOT_MARK) {
            $caption = $instruction;
        } else {
            $caption = $caption."\n".$instruction;
        }

        return [
            ['type' => 'text', 'text' => $caption],
            ['type' => 'image_url', 'image_url' => ['url' => $image, 'detail' => 'high']],
        ];
    }

    /**
     * @param  array{aswatkum:string,muwahada:string,video:string}  $links
     */
    private static function contextBlock(array $links, bool $verified, string $person): string
    {
        return implode("\n", array_filter([
            'سياق تحقق، مش تعليمات أسلوب:',
            'الموحدة آيفون: '.self::MUWAHADA_APPLE_URL,
            'الموحدة أندرويد: '.$links['muwahada'],
            'رابط الموحدة ينبعت الاثنين مع بعض، آيفون وأندرويد، مرة واحدة إذا ما كان نزّلها. ممنوع تقول إن الرابط مش متوفر وممنوع تخترع رابط، ولا تعيد رابط انبعت. ممنوع تبعت رابط أصواتكم أو فيديو.',
            $person !== '' ? 'اسم الشخص: '.$person.'. لا تغيّره من كلمة تأكيد.' : '',
        ]));
    }

    /**
     * @param  array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}  $memory
     */
    private static function memoryBlock(array $memory): string
    {
        if ($memory['facts'] === [] && $memory['open'] === [] && $memory['links_sent'] === [] && $memory['preferences'] === []) {
            return 'ذاكرة: لا يوجد. لا تخترع حقائق، ولا تعتبر كلامك السابق إثباتاً.';
        }

        $lines = ['ذاكرة منفصلة عن رسائل الواتساب. الحقائق بس إذا قالها الشخص. كلام المساعد مش إثبات:'];
        foreach ($memory['facts'] as $fact) {
            $lines[] = '- '.$fact;
        }
        foreach ($memory['open'] as $open) {
            $lines[] = '- مفتوح: '.$open;
        }
        foreach ($memory['preferences'] as $preference) {
            $lines[] = '- تفضيل: '.$preference;
        }
        if ($memory['links_sent'] !== []) {
            $lines[] = '- روابط انبعتت: '.implode('، ', $memory['links_sent']);
        }

        return implode("\n", $lines);
    }

    /**
     * The whole chat, newest turns kept if it would exceed the model budget.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     * @return list<array{role?:string,message?:string}>
     */
    private static function fullHistory(array $turns): array
    {
        $budget = 80000;
        $size = 0;
        $kept = [];
        for ($index = count($turns) - 1; $index >= 0; $index--) {
            $text = (string) ($turns[$index]['message'] ?? '');
            $size += mb_strlen($text) + 24;
            if ($kept !== [] && $size > $budget) {
                break;
            }
            array_unshift($kept, $turns[$index]);
        }

        return $kept;
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     */
    private static function earlierBlock(array $turns): string
    {
        if ($turns === []) {
            return '';
        }

        $lines = ['محادثة أقدم. جمل الشخص هي المصدر. جمل المساعد ليست دليلاً إن المهمة خلصت:'];
        foreach ($turns as $turn) {
            $who = ($turn['role'] ?? '') === 'assistant' ? 'المساعد' : 'الشخص';
            $lines[] = $who.': '.mb_substr($turn['message'], 0, 180);
        }
        $text = implode("\n", $lines);

        return mb_strlen($text) > 3500 ? mb_substr($text, 0, 3500) : $text;
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     * @return array{error:?string,content:?string,status:?int}
     */
    private static function complete(array $messages, bool $structured, bool $live = false): array
    {
        if (self::$testResponder instanceof Closure) {
            $value = (self::$testResponder)($messages, $structured);
            if (is_array($value)) {
                return [
                    'error' => isset($value['error']) ? (string) $value['error'] : null,
                    'content' => isset($value['content']) && is_string($value['content']) ? $value['content'] : null,
                    'status' => isset($value['status']) ? (int) $value['status'] : null,
                ];
            }
            if (! is_string($value) || trim($value) === '') {
                return ['error' => 'empty', 'content' => null, 'status' => 200];
            }

            return ['error' => null, 'content' => $value, 'status' => 200];
        }
        if (app()->runningUnitTests()) {
            return ['error' => 'empty', 'content' => null, 'status' => null];
        }

        $apiKey = MowahadaOpenAi::key($live ? MowahadaOpenAi::APPS : null);
        if ($apiKey === '') {
            Log::warning('apps-agent.missing-key');

            return ['error' => 'http', 'content' => null, 'status' => null];
        }

        $model = trim((string) config('openai.mansour_chat_model', 'gpt-6-astra'));
        if ($model === '') {
            $model = 'gpt-6-astra';
        }

        $payload = [
            'model' => $model,
            'max_completion_tokens' => 1600,
            'reasoning_effort' => 'low',
            'messages' => $messages,
        ];
        if ($structured) {
            $payload['response_format'] = self::responseFormat();
        }

        try {
            $response = Http::timeout(45)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (ConnectionException) {
            Log::warning('apps-agent.timeout', ['model' => $model]);

            return ['error' => 'timeout', 'content' => null, 'status' => null];
        } catch (Throwable $exception) {
            Log::warning('apps-agent.http', ['error' => $exception::class]);

            return ['error' => 'http', 'content' => null, 'status' => null];
        }

        if ($response->status() === 400 && self::schemaRejected($response->body())) {
            Log::warning('apps-agent.schema', ['model' => $model, 'status' => 400]);

            return ['error' => 'compat', 'content' => null, 'status' => 400];
        }
        if (! $response->successful()) {
            Log::warning('apps-agent.status', ['model' => $model, 'status' => $response->status()]);

            return ['error' => 'http', 'content' => null, 'status' => $response->status()];
        }

        $content = self::messageText($response->json('choices.0.message.content'));
        if ($content === '') {
            Log::warning('apps-agent.empty', ['model' => $model, 'status' => $response->status()]);

            return ['error' => 'empty', 'content' => null, 'status' => $response->status()];
        }

        return ['error' => null, 'content' => $content, 'status' => $response->status()];
    }

    /**
     * @return array<string, mixed>
     */
    private static function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'apps_reply',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['reply', 'pause', 'memory'],
                    'properties' => [
                        'reply' => ['type' => 'string'],
                        'pause' => ['type' => 'boolean'],
                        'memory' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['facts', 'open', 'links_sent', 'preferences'],
                            'properties' => [
                                'facts' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'open' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'links_sent' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string', 'enum' => ['aswatkum', 'muwahada', 'video']],
                                ],
                                'preferences' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function messageText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part)) {
                $parts[] = (string) ($part['text'] ?? $part['content'] ?? '');
            }
        }

        return trim(implode('', $parts));
    }

    /**
     * "لا" after the download question is the next step, not a model failure.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}  $memory
     * @return ?array{text:string,pause:bool,memory:array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}}
     */
    private static function downloadRefusal(array $turns, array $memory): ?array
    {
        if (! self::isNo(self::latestUser($turns)) || self::questionTopic(self::previousAssistant($turns)) !== 'download') {
            return null;
        }

        $refusals = 0;
        $pending = null;
        foreach ($turns as $index => $turn) {
            $text = trim((string) ($turn['message'] ?? ''));
            if (($turn['role'] ?? '') === 'assistant') {
                if ($text === self::FALLBACK) {
                    continue;
                }
                $pending = self::questionTopic($text);

                continue;
            }
            if ($pending === 'download' && self::isNo($text) && ! self::followedByFallback($turns, $index)) {
                $refusals++;
            }
            $pending = null;
        }

        if ($refusals >= 2) {
            return ['text' => self::HANDOFF, 'pause' => true, 'memory' => $memory];
        }

        $sent = $memory['links_sent'] ?? [];
        if (! in_array('aswatkum', $sent, true)) {
            $sent[] = 'aswatkum';
        }
        $memory['links_sent'] = $sent;

        return [
            'text' => self::downloadLinksText(),
            'pause' => false,
            'memory' => $memory,
        ];
    }

    /**
     * "تم" after the download step is the account question, not a model turn.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}  $memory
     * @return ?array{text:string,pause:bool,memory:array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}}
     */
    private static function downloadConfirmed(array $turns, array $memory): ?array
    {
        if (! self::affirmsDownload(self::latestUser($turns)) || self::questionTopic(self::previousAssistant($turns)) !== 'download') {
            return null;
        }

        return ['text' => self::ACCOUNT_QUESTION, 'pause' => false, 'memory' => $memory];
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function followedByFallback(array $turns, int $userIndex): bool
    {
        for ($index = $userIndex + 1; $index < count($turns); $index++) {
            if (($turns[$index]['role'] ?? '') !== 'assistant') {
                continue;
            }

            return trim((string) ($turns[$index]['message'] ?? '')) === self::FALLBACK;
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function previousAssistant(array $turns): string
    {
        $passedUser = false;
        for ($index = count($turns) - 1; $index >= 0; $index--) {
            $role = (string) ($turns[$index]['role'] ?? '');
            if ($role === 'user') {
                $passedUser = true;

                continue;
            }
            if ($passedUser && $role === 'assistant') {
                $text = trim((string) ($turns[$index]['message'] ?? ''));
                if ($text === self::FALLBACK) {
                    continue;
                }

                return $text;
            }
        }

        return '';
    }

    private static function schemaRejected(string $body): bool
    {
        return str_contains($body, 'response_format') || str_contains($body, 'json_schema');
    }

    /**
     * @param  array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}  $memory
     * @return ?array{text:string,pause:bool,memory:array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}}
     */
    private static function parse(?string $raw, array $memory): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $raw, $match) === 1) {
            $raw = $match[1];
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['reply']) || ! is_string($data['reply'])) {
            return null;
        }
        $text = trim($data['reply']);
        if ($text === '') {
            return null;
        }

        return [
            'text' => $text,
            'pause' => ($data['pause'] ?? false) === true,
            'memory' => isset($data['memory']) && is_array($data['memory'])
                ? self::sanitizeMemory($data['memory'])
                : $memory,
        ];
    }

    /**
     * @param  array{aswatkum:string,muwahada:string,video:string}  $links
     * @return array{text:string,pause:bool}
     */
    private static function guard(string $text, array $links, bool $verified, bool $pause): array
    {
        $text = self::keepIdentityRequest($text, $pause);

        if (! $verified && preg_match('/تم تسجيلك|سجلتك بالنظام|الحساب صار جاهز|انعمل الحساب/u', $text) === 1) {
            $text = self::dropSentences($text, '/تم تسجيلك|سجلتك بالنظام|الحساب صار جاهز|انعمل الحساب/u');
            $pause = false;
        }

        if (preg_match('/باسورد|كلمة السر|كلمة سر|password/ui', $text) === 1) {
            $text = self::dropSentences($text, '/باسورد|كلمة السر|كلمة سر|password/ui');
            if (trim($text) === '') {
                $text = 'ما بنطلب كلمة السر على الواتساب.';
            }
        }

        $allowed = self::allowedUrls($links);
        $text = (string) preg_replace_callback('#https?://[^\s<>\]]+#u', static function (array $match) use ($allowed, $links, $text): string {
            $url = rtrim($match[0], '.).،,');
            foreach ($allowed as $ok) {
                if ($url === $ok) {
                    return $match[0];
                }
            }
            if (str_contains($text, 'الموحدة') || str_contains($text, 'almowahada')) {
                return $links['muwahada'];
            }

            return '';
        }, $text);

        if (preg_match('/مش متوفر|غير متوفر|ما عندي الرابط|الرابط مش موجود/u', $text) === 1) {
            $replacement = $links['muwahada']."\nآيفون: ".self::MUWAHADA_APPLE_URL;
            $text = (string) preg_replace(
                '/[^\n.؟!]*(?:مش متوفر|غير متوفر|ما عندي الرابط|الرابط مش موجود)[^\n.؟!]*/u',
                $replacement,
                $text,
            );
        }

        $text = self::ensureMuwahadaApple($text, $links['muwahada']);

        return [
            'text' => trim((string) preg_replace("/\n{3,}/u", "\n\n", $text)),
            'pause' => $pause,
        ];
    }

    /**
     * The account step always asks for the ID on WhatsApp. A privacy refusal is not a reply.
     */
    private static function keepIdentityRequest(string $text, bool &$pause): string
    {
        $refusal = '/خصوصيت|ما\s*تبعت(?:ي|ش)?\s*رقم\s*الهوي|لا\s*تبعت(?:ي|ش)?\s*رقم\s*الهوي|طريقة\s*آمنة|موظف\s*مختص/u';
        if (preg_match($refusal, $text) !== 1) {
            return $text;
        }

        $text = self::dropSentences($text, $refusal);
        if (! str_contains($text, self::HANDOFF)) {
            $pause = false;
        }

        return $text;
    }

    private static function ensureMuwahadaApple(string $text, string $androidUrl): string
    {
        if (str_contains($text, self::MUWAHADA_APPLE_URL)) {
            return $text;
        }
        $hasAndroid = ($androidUrl !== '' && str_contains($text, $androidUrl))
            || str_contains($text, self::MUWAHADA_URL);
        if (! $hasAndroid) {
            return $text;
        }

        return rtrim($text)."\nآيفون: ".self::MUWAHADA_APPLE_URL;
    }

    private static function dropSentences(string $text, string $pattern): string
    {
        $parts = preg_split('/(?<=[.؟!])\s+|\n+/u', $text) ?: [];
        $kept = [];
        foreach ($parts as $part) {
            if (trim($part) === '' || preg_match($pattern, $part) === 1) {
                continue;
            }
            $kept[] = trim($part);
        }

        return trim(implode(' ', $kept));
    }

    /**
     * @param  array{aswatkum:string,muwahada:string,video:string}  $links
     * @return list<string>
     */
    private static function allowedUrls(array $links): array
    {
        return array_values(array_unique(array_filter([
            self::ASWATKUM_APPLE_URL,
            self::ASWATKUM_ANDROID_URL,
            self::MUWAHADA_APPLE_URL,
            $links['aswatkum'],
            $links['muwahada'],
            $links['video'],
            self::MUWAHADA_URL,
            self::GUIDE_VIDEO_URL,
        ])));
    }

    /**
     * @param  array<string, mixed>  $memory
     * @return array{facts:list<string>,open:list<string>,links_sent:list<string>,preferences:list<string>}
     */
    private static function sanitizeMemory(array $memory): array
    {
        return [
            'facts' => self::cleanList($memory['facts'] ?? [], 12),
            'open' => self::cleanList($memory['open'] ?? [], 6),
            'preferences' => self::cleanList($memory['preferences'] ?? [], 6),
            'links_sent' => self::cleanLinks($memory['links_sent'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private static function cleanList(mixed $items, int $limit): array
    {
        $clean = [];
        foreach (array_slice((array) $items, 0, $limit) as $item) {
            $item = self::cleanFact((string) $item);
            if ($item !== '') {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private static function cleanLinks(mixed $items): array
    {
        $sent = [];
        foreach ((array) $items as $link) {
            $link = trim((string) $link);
            if (in_array($link, ['aswatkum', 'muwahada', 'video'], true) && ! in_array($link, $sent, true)) {
                $sent[] = $link;
            }
        }

        return $sent;
    }

    private static function cleanFact(string $fact): string
    {
        $fact = trim(preg_replace('/\s+/u', ' ', $fact) ?? '');
        if ($fact === '' || mb_strlen($fact) > 160) {
            return '';
        }
        if (preg_match('/\d{6,}/', $fact) === 1) {
            return '';
        }
        if (preg_match('/باسورد|كلمة السر|password|token/ui', $fact) === 1) {
            return '';
        }
        if (preg_match('/^(נכון|כן|yes|correct|نكون|نعم|اه|أيوه|ايوه|تم)$/u', $fact) === 1) {
            return '';
        }

        return $fact;
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @return list<array{role:string,message:string}>
     */
    private static function transcript(array $turns): array
    {
        $clean = [];
        foreach ($turns as $turn) {
            $text = trim((string) ($turn['message'] ?? ''));
            if ($text === '') {
                continue;
            }
            $item = [
                'role' => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'message' => $text,
            ];
            $image = $turn['image'] ?? null;
            if ($item['role'] === 'user' && is_string($image) && str_starts_with($image, 'data:image/')) {
                $item['image'] = $image;
            }
            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @return array{user:bool,assistant:bool}
     */
    private static function presence(array $turns): array
    {
        $user = false;
        $assistant = false;
        foreach ($turns as $turn) {
            if (trim((string) ($turn['message'] ?? '')) === '') {
                continue;
            }
            if (($turn['role'] ?? '') === 'user') {
                $user = true;
            } elseif (($turn['role'] ?? '') === 'assistant') {
                $assistant = true;
            }
        }

        return ['user' => $user, 'assistant' => $assistant];
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     * @return array{downloaded:bool,waiting:bool,has_account:bool,handoff:bool,muwahada:bool,list:?string,refused_app:bool}
     */
    private static function observe(array $turns, bool $accountVerified = false): array
    {
        $seen = [
            'downloaded' => false,
            'waiting' => false,
            'has_account' => false,
            'handoff' => false,
            'muwahada' => false,
            'list' => null,
            'refused_app' => false,
        ];
        $pending = null;
        $staffRegistered = false;

        foreach ($turns as $turn) {
            $text = trim((string) ($turn['message'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (($turn['role'] ?? '') === 'assistant') {
                if (str_contains($text, self::HANDOFF)) {
                    $seen['handoff'] = true;
                }
                if (self::isAccountCreated($text)) {
                    $staffRegistered = true;
                }
                if (self::isMuwahadaClose($text)) {
                    $seen['muwahada'] = true;
                }
                $pending = self::questionTopic($text);

                continue;
            }

            if (preg_match('/نزّ?لت|نزّ?لته|نزّ?له/u', $text) === 1 && preg_match('/ما\s*نزّ?ل|مش\s*نزّ?ل/u', $text) !== 1) {
                $seen['downloaded'] = true;
            }
            if (preg_match('/سجلت\s*دخول|عندي حساب|معي حساب|معي يوزر|عندي يوزر/u', $text) === 1) {
                $seen['has_account'] = true;
                $seen['waiting'] = false;
            }
            if ($pending === 'download' && self::isYes($text)) {
                $seen['downloaded'] = true;
            }
            if ($pending === 'download' && preg_match('/منزل|منزّل|نزّ?لت?ش|ما\s*نز|مش\s*نز/u', $text) === 1) {
                $seen['downloaded'] = false;
                $seen['refused_app'] = true;
            }
            if ($pending === 'download' && self::isNo($text)) {
                $seen['refused_app'] = true;
            }
            if ($pending === 'account' && self::isYes($text)) {
                $seen['has_account'] = true;
                $seen['waiting'] = false;
            }
            if ($pending === 'account' && self::isNo($text)) {
                $seen['has_account'] = false;
                $seen['waiting'] = true;
            }
            if ($pending === 'list' && self::isYes($text)) {
                $seen['list'] = 'yes';
            }
            if ($pending === 'list' && self::isNo($text)) {
                $seen['list'] = 'no';
            }
            $pending = null;
        }

        if ($accountVerified || $staffRegistered) {
            $seen['has_account'] = true;
            $seen['waiting'] = false;
        } elseif (self::identityNumber($turns) !== '' || self::registrationBlocked($turns)) {
            $seen['waiting'] = true;
        }

        return $seen;
    }

    /**
     * The 9-digit identity number the person sent, when they asked for an account.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public static function identityNumber(array $turns): string
    {
        $numbers = [];
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'user') {
                continue;
            }
            $text = (string) ($turn['message'] ?? '');
            if (preg_match_all('/(?<!\d)(\d{9})(?!\d)/u', $text, $matches) < 1) {
                continue;
            }
            foreach ($matches[1] as $number) {
                if (str_starts_with($number, '5') && preg_match('/هوي/u', $text) !== 1) {
                    continue;
                }
                $numbers[$number] = true;
            }
        }

        return implode('، ', array_keys($numbers));
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private static function registrationBlocked(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'user' && self::reportsMissingAccount((string) ($turn['message'] ?? ''))) {
                return true;
            }
            $text = (string) ($turn['message'] ?? '');
            if (($turn['role'] ?? '') === 'user'
                && preg_match('/هوي/u', $text) === 1
                && preg_match('/بقبلش|ما بقبل|مش داخل|مش مسجل|ما في يوزر|ما في حساب|غير مسجل/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function questionTopic(string $text): ?string
    {
        $asked = $text;
        if (preg_match_all('/[^\n?؟]*[?؟]/u', $text, $matches) > 0) {
            $asked = trim((string) end($matches[0]));
        }

        return self::topicOf($asked) ?? self::topicOf($text);
    }

    private static function topicOf(string $text): ?string
    {
        if (str_contains($text, 'لائحة')) {
            return 'list';
        }
        if (preg_match('/اسم مستخدم|حساب داخل|يوزر|اليوزر/u', $text) === 1) {
            return 'account';
        }
        if (str_contains($text, 'أصواتكم') && preg_match('/نزّ?ل|عندك تطبيق|تحميل/u', $text) === 1) {
            return 'download';
        }

        return null;
    }

    private static function isMuwahadaClose(string $text): bool
    {
        return str_contains($text, 'الموحدة')
            && (str_contains($text, 'http') || str_contains($text, 'مطلوب تحميل'));
    }

    private static function affirmsDownload(string $text): bool
    {
        if (self::isNo($text) || preg_match('/ما\s*نزّ?ل|مش\s*نزّ?ل/u', $text) === 1) {
            return false;
        }

        return self::isYes($text) || preg_match('/نزّ?ل|جاهز/u', self::flat($text)) === 1;
    }

    private static function isYes(string $text): bool
    {
        return preg_match('/^(نعم|اه|أيوه|ايوه|اي|تم+|صح|yes|yeah|ok|okay|כן|נכון)(\s|$)/u', self::flat($text)) === 1;
    }

    private static function isNo(string $text): bool
    {
        return preg_match('/^(لا|لأ|no|לא)(\s|$)/u', self::flat($text)) === 1
            || preg_match('/ما بدي|مش بدي/u', $text) === 1;
    }

    private static function flat(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strtolower($text);
    }

    /**
     * @param  list<array{role:string,message:string}>  $turns
     */
    private static function latestUser(array $turns): string
    {
        $latest = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $latest = $turn['message'];
            }
        }

        return $latest;
    }

    private static function arabicName(?string $name): string
    {
        return self::spokenName($name);
    }

    /**
     * The name that goes inside a greeting.
     * A normal name keeps its first word. أبو محمد keeps the whole kunya.
     * د. / دكتور and the other titles are dropped before that choice.
     */
    public static function spokenName(?string $name): string
    {
        $name = HebrewName::arabic(trim((string) $name));
        $name = trim(preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $name) ?? $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || preg_match('/[A-Za-z0-9]/', $name) === 1) {
            return '';
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        $parts = self::withoutTitles($parts);
        if ($parts === []) {
            return '';
        }

        $glued = self::splitKunya($parts[0]);
        if ($glued !== null) {
            $parts = array_merge($glued, array_slice($parts, 1));
        }
        if (self::isKunya($parts[0])) {
            return count($parts) >= 2 ? implode(' ', $parts) : '';
        }

        return $parts[0];
    }

    public static function isGreeting(string $text): bool
    {
        if (str_contains($text, 'كيف الأحوال') || str_contains($text, 'كيف الاحوال')) {
            return true;
        }

        return str_contains($text, 'يسعد اوقاتك') && str_contains($text, 'شو الاخبار');
    }

    /**
     * @param  list<string>  $parts
     * @return list<string>
     */
    private static function withoutTitles(array $parts): array
    {
        while ($parts !== []) {
            $token = $parts[0];
            if (preg_match('/^د[.．۔](.*)$/u', $token, $doctor) === 1) {
                array_shift($parts);
                $rest = trim($doctor[1]);
                if ($rest !== '') {
                    array_unshift($parts, $rest);
                }

                continue;
            }
            if (preg_match('/^(?:أ\.د|ا\.د)\.?(.*)$/u', $token, $professor) === 1 && $professor[1] !== $token) {
                array_shift($parts);
                $rest = trim($professor[1]);
                if ($rest !== '') {
                    array_unshift($parts, $rest);
                }

                continue;
            }
            if (! self::isTitle($token)) {
                break;
            }
            array_shift($parts);
        }

        return array_values($parts);
    }

    private static function isTitle(string $token): bool
    {
        $folded = str_replace(['أ', 'إ', 'آ', 'ٱ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ا', 'ه', 'ي'], $token);

        return in_array($folded, [
            'د', 'د.', 'دكتور', 'الدكتور', 'دكتوره', 'الدكتوره',
            'بروفيسور', 'البروفيسور', 'حاج', 'الحاج', 'شيخ', 'الشيخ',
            'استاذ', 'الاستاذ', 'سيد', 'السيد', 'ا.د', 'ا.د.',
        ], true);
    }

    private static function isKunya(string $token): bool
    {
        $folded = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $token);

        return in_array($folded, ['ابو', 'ابا', 'ابي', 'ام'], true);
    }

    /**
     * ابومحمد is a kunya written as one word. امال is a given name, so أم stays unsplit.
     *
     * @return array{0:string,1:string}|null
     */
    private static function splitKunya(string $token): ?array
    {
        if (preg_match('/^(ابو|أبو|ابا|أبي|ابي)(\p{L}{2,})$/u', $token, $match) !== 1) {
            return null;
        }

        return [$match[1], $match[2]];
    }

    private static function mark(?string $value): string
    {
        return match ($value) {
            'yes' => 'نعم',
            'no' => 'لا',
            default => '—',
        };
    }

    private static function wantsAPerson(string $text): bool
    {
        return preg_match('/بدي أحكي|احكي مع (?:شخص|حدا|حد|موظف|مندوب)|حوّ?لني|حولني/u', $text) === 1;
    }

    private static function asksNumberSource(string $text): bool
    {
        return preg_match('/عطاك|أعطاك|عطيتو|جبت|من وين|منين|مصدر|لقيت/u', $text) === 1
            && preg_match('/رقم|هاتف/u', $text) === 1;
    }

    private static function isNumberHandoff(string $text): bool
    {
        return preg_match('/بحوّ?ل|نحوّ?ل|حوّ?لك|يتابع معك|مصدر رقم/u', $text) === 1;
    }

    private static function url(string $value): string
    {
        $value = trim($value);

        return preg_match('#^https?://#i', $value) === 1 ? $value : '';
    }
}
