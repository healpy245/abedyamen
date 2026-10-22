<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * دعوات الموحدة: تحية أولاً، وبعد الرد الدعوة مع الصورة، ثم رد قصير.
 * الاسم في الرسالة هو الاسم الأول فقط.
 */
class InvitePrompt
{
    public const WOMEN = 'women';

    public const YOUTH = 'youth';

    public const POSITIVE = 'positive';

    public const CANCEL = 'cancel';

    public const OPEN = 'open';

    public const NO_REPLY = 'no_reply';

    public const MODEL = 'gpt-4o-mini';

    public const PLEA = 'خساره، حضورك بالنسبة النا كان مهم، ما في مجال بتاتا؟';

    public const CLARIFY = 'بتحضري المؤتمر؟';

    public const ASK = 'يرجى الإجابة بـ «تأكيد» لتأكيد الحضور.';

    public const YOUTH_AFTER_FILE = 'بس تسجل بالرابط ابعتلي <<تم>> 🙏';

    public const NUDGE = 'يعطيكي العافيه {name}، نتمنى ترجعيلنا برساله للتاكيد على حضورك، حضورك بشرفنا 🌸';

    public const YOUTH_NUDGE = 'يعطيك العافيه {name}، نتمنى ترجعيلنا برساله للتاكيد على حضورك، حضورك بشرفنا';

    public const YOUTH_BODY = <<<'TXT'
لأن التغيير يبدأ منكم،
ندعو شبابنا لأضخم مؤتمر شبابي دعمًا للموحدة لنعلنها بصوت واحد: *الشباب حسبوها صح.. بس موحدة!*

📍 قاعة الأوبرا – طمرة
🗓️ السبت 24.10.2026
⏰ الساعة 14:00

مكانك بيننا.. سجّل الآن وكن شريكًا في صناعة التغيير:

https://forms.gle/mgzREfC9RWnna7TX7
بس تسجل بالرابط ابعتلي <<تم>> 🙏
TXT;

    public const BODY = <<<'TXT'
يسعدنا أن ندعوكِ لحضور "المؤتمر النسائي" في الجليل.
وجودكِ بيننا له قيمة خاصة، لما لكِ من حضور ودور فاعل في مجتمعكِ. ونؤمن بأن اللقاء يزداد ثراءً بمشاركة نساء صاحبات تجربة ومبادرة مثلكِ.
نتطلع إلى لقائكِ ومشاركتكِ هذا المؤتمر، وأن تكون فرصة جميلة للتواصل وتبادل الأفكار وتعزيز التعاون للارتقاء في مجتمعنا
يسعدنا تأكيد حضوركِ حتى نتمكن من استقبالكِ والترحيب بكِ كما يليق
بانتظار ردّكِ، ولكِ منا كل المحبة والتقدير
طاقم النساء في القائمة العربية الموحدة
للاستفسار : 0502018556

يرجى الإجابة بـ «تأكيد» لتأكيد الحضور.
TXT;

    public static ?Closure $testResponder = null;

    public const POSTER = 'images/farz/womens-conference.jpg';

    public const POSTER_STORAGE = 'invite/womens-conference.jpg';

    public const YOUTH_POSTER = 'images/farz/youth-conference.jpg';

    public const YOUTH_POSTER_STORAGE = 'invite/youth-conference.jpg';

    public static function variantOf(?ChatbotInstance $instance): string
    {
        return $instance?->isYouthInviteBot() ? self::YOUTH : self::WOMEN;
    }

    public static function template(string $variant = self::WOMEN): string
    {
        if ($variant === self::YOUTH) {
            return self::YOUTH_BODY;
        }

        return self::BODY;
    }

    /**
     * The first WhatsApp line. The poster waits until she or he replies.
     */
    public static function greetingFor(?string $name, string $variant = self::WOMEN): string
    {
        $given = self::givenName($name);
        $wish = $variant === self::YOUTH ? 'يعطيك العافيه' : 'يعطيكي العافيه';
        if ($given === '') {
            return 'مرحبا، '.$wish;
        }

        return 'مرحبا '.$given.'، '.$wish;
    }

    public static function posterPath(string $variant = self::WOMEN): ?string
    {
        $path = public_path($variant === self::YOUTH ? self::YOUTH_POSTER : self::POSTER);

        return is_file($path) ? $path : null;
    }

    public static function posterFileName(string $variant = self::WOMEN): string
    {
        return $variant === self::YOUTH ? 'youth-conference.jpg' : 'womens-conference.jpg';
    }

    /**
     * Copy the poster onto the local disk so chat history can show it.
     *
     * @return array{attachment_disk:string,attachment_path:string,attachment_mime:string}|null
     */
    public static function posterAttachment(string $variant = self::WOMEN): ?array
    {
        $source = self::posterPath($variant);
        if ($source === null) {
            return null;
        }

        $stored = $variant === self::YOUTH ? self::YOUTH_POSTER_STORAGE : self::POSTER_STORAGE;
        $disk = Storage::disk('local');
        if (! $disk->exists($stored)) {
            $disk->put($stored, (string) file_get_contents($source));
        }

        return [
            'attachment_disk' => 'local',
            'attachment_path' => $stored,
            'attachment_mime' => 'image/jpeg',
        ];
    }

    /**
     * Caption that goes out with the poster, after the greeting has been answered.
     */
    public static function invitationFor(?string $name, ?string $template = null, string $variant = self::WOMEN): string
    {
        if ($variant === self::YOUTH) {
            $template = trim((string) $template);
            if ($template === '' || ! str_contains($template, 'لأن التغيير يبدأ منكم')) {
                $template = self::YOUTH_BODY;
            }

            return $template;
        }

        return self::openingFor($name, $template);
    }

    public static function openingFor(?string $name, ?string $template = null): string
    {
        $template = self::plainWomenCopy((string) $template);
        if ($template === '') {
            $template = self::template();
        }
        $given = self::givenName($name);
        $slot = $given !== '' ? $given : 'الغالية';
        $text = str_replace(['{name}', '{اسم}', 'اسم الشخص'], $slot, $template);
        if (! str_contains($text, 'يرجى الإجابة')) {
            $text = rtrim($text)."\n".self::ASK;
        }

        return $text;
    }

    public static function nudgeFor(?string $name, string $variant = self::WOMEN): string
    {
        $given = self::givenName($name);
        $slot = $given !== '' ? $given : ($variant === self::YOUTH ? '' : 'الغالية');
        $text = str_replace('{name}', $slot, $variant === self::YOUTH ? self::YOUTH_NUDGE : self::NUDGE);
        $text = preg_replace('/\s+،/u', '،', $text) ?? $text;

        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }

    public static function instructions(string $variant = self::WOMEN): string
    {
        if ($variant === self::YOUTH) {
            return <<<'TXT'
أنت موظف دعوات للقاء شباب الموحدة في طمرة. التحية انبعتت، وبعد الرد انبعتت الدعوة مع الصورة. ردّك قصير، جملة أو جملتين، بالمذكر، بالعربي.
لا تناقش سياسة ولا تصويت. لا تطوّل. لا تعيد نص الدعوة.
ممنوع الترحيب العام. ممنوع "كيف يمكنني مساعدتك" وممنوع "أنا هنا لمساعدتك".
إذا أكد الحضور، أو كتب «تأكيد» أو قال إنه سجّل: اشكره وقل إننا مستنيينه، وخلي stance = positive و close = true.
إذا كتب «إلغاء» أو طلب يوقف الرسائل: قل بلطف إن اسمه انشال من القائمة، stance = cancel و close = true.
إذا اعتذر عن الحضور بدون إلغاء صريح وبدون سبب قوي (مشغول، صعب، ما رح يجي): اسأل مرة واحدة فقط "خساره، حضورك بالنسبة النا كان مهم، ما في مجال بتاتا؟" وبعدها نص الدعوة، وآخر سطر "بس تسجل بالرابط ابعتلي تم". stance = open و close = false. لا تعيد هالسؤال.
إذا كتب «تم»: الحضور مؤكد، stance = positive و close = true.
إذا بعد هالسؤال أكد إنه ما في مجال: قل بلطف إن اسمه انشال، stance = cancel و close = true.
إذا السبب قوي من أول رسالة، مثل وفاة أو مرض أو حادث: قل "تمام متاسف على الازعاج ولا يهمك، تسلم على وقتك" ولا تسأل إذا في مجال. stance = cancel و close = true.
إذا سأل عن الموعد أو المكان: السبت 24.10.2026، الساعة 14:00، قاعة الأوبرا في طمرة. للتسجيل https://forms.gle/mgzREfC9RWnna7TX7. stance = open و close = false، واسأله إذا بيحضر.
إذا الرسالة تحية، أو مو واضح إذا بيأكد الحضور أو بيرفضه: اسأل فقط "بتحضر اللقاء؟". stance = open و close = false.
لا تستخدم إلا اسمه الأول إذا لزم.
TXT;
        }

        return <<<'TXT'
أنتِ موظفة دعوات للمؤتمر النسائي للقائمة العربية الموحدة في الجليل. الرسالة الأولى انبعتت. ردّك قصير، جملة أو جملتين، بالمؤنث، بالعربي.
لا تناقشي سياسة ولا تصويت. لا تطيلي. لا تعيدي نص الدعوة.
ممنوع الترحيب العام. ممنوع "كيف يمكنني مساعدتك" وممنوع "أنا هنا لمساعدتك".
إذا أكدت الحضور، أو كتبت «تأكيد»: اشكريها قولي إننا مستنيينها، وخلي stance = positive و close = true.
إذا كتبت «إلغاء» أو طلبت توقف الرسائل: قولي بلطف إن اسمها انشال من القائمة، stance = cancel و close = true.
إذا اعتذرت عن الحضور بدون إلغاء صريح وبدون سبب قوي (مشغولة، صعب، ما رح تيجي): اسألي مرة واحدة فقط "خساره، حضورك بالنسبة النا كان مهم، ما في مجال بتاتا؟". stance = open و close = false. لا تعيدي هالسؤال.
إذا بعد هالسؤال أكدت إنه ما في مجال: قولي بلطف إن اسمها انشال، stance = cancel و close = true.
إذا السبب قوي من أول رسالة، مثل وفاة أو مرض أو حادث: قولي "تمام متاسفه على الازعاج ولا يهمك، تسلمي على وقتك" ولا تسألي إذا في مجال. stance = cancel و close = true.
إذا سألت عن الموعد أو المكان: السبت 24.10.2026، الساعة 11:30، قاعة الأوبرا في طمرة. للاستفسار 0502018556. stance = open و close = false، واسألي إذا بتحضر.
إذا الرسالة تحية، أو مو واضح إذا بتأكد الحضور أو بترفضه: اسألي فقط "بتحضري المؤتمر؟". stance = open و close = false.
لا تستخدمي إلا اسمها الأول إذا لزم.
TXT;
    }

    /**
     * The person's given name, in Arabic, without the family name or a title.
     */
    public static function givenName(?string $name): string
    {
        $name = HebrewName::arabic($name);
        $name = preg_replace('/\s*[\(（].*?[\)）]\s*/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s*[-–—]\s*(?:ام|أم|ابو|أبو)\s+\S+/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $skip = ['د', 'د.', 'دكتور', 'الدكتور', 'دكتوره', 'الدكتوره', 'الشيخ', 'شيخ', 'الاستاذ', 'استاذ', 'الأستاذ', 'السيد', 'سيد', 'الحاج', 'حاج'];
        while ($parts !== [] && in_array($parts[0], $skip, true)) {
            array_shift($parts);
        }
        if ($parts === []) {
            return '';
        }

        $first = $parts[0];
        $second = $parts[1] ?? '';
        if (in_array($first, ['أم', 'ام'], true)) {
            $woman = $parts[2] ?? '';
            if ($woman !== '' && count($parts) >= 4 && ! self::maleGiven($woman)) {
                return $woman;
            }

            return '';
        }
        if (in_array($first, ['أبو', 'ابو'], true) && $second !== '') {
            $first = $second;
            $second = $parts[2] ?? '';
        }
        $joins = ['الرحمن', 'الرحيم', 'الله', 'الكريم', 'العزيز', 'الهادي', 'المعز', 'ربه', 'الحق', 'القادر', 'اللطيف', 'الوهاب', 'الحميد', 'الرزاق', 'الدين', 'الحكيم'];
        if ($second !== '' && ($first === 'عبد' || $second === 'الدين')) {
            if ($first === 'عبد' && in_array($second, $joins, true)) {
                return $first.' '.$second;
            }
            if ($second === 'الدين' && in_array($first, ['عز', 'فداء', 'نور', 'سيف', 'بهاء', 'صلاح', 'محيي'], true)) {
                return $first.' '.$second;
            }
        }

        return $first;
    }

    /**
     * A son's name after «أم» is not the woman's name.
     */
    private static function maleGiven(string $name): bool
    {
        return in_array($name, [
            'محمد', 'أحمد', 'احمد', 'محمود', 'علي', 'حسن', 'حسين', 'خالد', 'عمر', 'يوسف', 'إبراهيم', 'ابراهيم',
            'عبدالله', 'عبد الله', 'عبد الرحمن', 'مصطفى', 'موسى', 'مراد', 'أسامة', 'سليمان', 'منير', 'منصور',
            'سهيل', 'زهير', 'حسام', 'مهند', 'عماد', 'عمران', 'غانم', 'شكري', 'صبحي', 'محسن', 'معتصم', 'معتز',
            'مؤمن', 'توفيق', 'فوزي', 'لطفي', 'عدي', 'وسيم', 'واصل', 'سيد', 'هيثم', 'رأفت', 'جعفر', 'رمضان',
            'كمال', 'كامل', 'طه', 'هلال', 'ناصر', 'جبريل', 'سراج', 'فضل', 'فاضل', 'رياض', 'نذير', 'فائق',
            'نزار', 'سلمان', 'ثابت', 'فيصل', 'ليث', 'وليد', 'مهاب', 'مفيد', 'سفيان', 'حاتم', 'أنور', 'مروان',
            'عواد', 'إياد', 'أمير', 'أديب', 'سامي', 'عيسى', 'يحيى', 'عثمان', 'داوود', 'نبيل', 'باسم', 'باسل',
            'بلال', 'جمال', 'جلال', 'حازم', 'حمزة', 'حمزه', 'خضر', 'خليل', 'رامي', 'رائد', 'راشد', 'زياد',
            'سالم', 'سمير', 'شادي', 'شريف', 'صالح', 'طلال', 'عادل', 'عامر', 'عزام', 'عصام', 'علاء', 'عمرو',
            'غازي', 'فادي', 'فارس', 'فهد', 'فؤاد', 'قاسم', 'ماجد', 'مازن', 'مالك', 'مجدي', 'منذر', 'ناجي',
            'نادر', 'نضال', 'نعيم', 'هاشم', 'هاني', 'هشام', 'وائل', 'ياسر', 'يونس', 'أيمن', 'ايمن', 'إيهاب',
            'إسماعيل', 'أنس', 'أكرم', 'بشار', 'بسام', 'تميم', 'جابر', 'جبر', 'حمدي', 'حنا', 'خلدون', 'داود',
            'ربيع', 'سعيد', 'سعد', 'سليم', 'سميح', 'صادق', 'صلاح', 'ضياء', 'طاهر', 'عارف', 'عاطف', 'عدنان',
            'عزت', 'غسان', 'غالب', 'فراس', 'فريد', 'فهمي', 'لؤي', 'ماهر', 'مخلص', 'مصعب', 'معاذ', 'مهدي',
            'نصر', 'نعمان', 'نمر', 'هادي', 'وصفي', 'ياسين', 'يعقوب', 'أشرف', 'اشرف', 'طارق', 'حمادة', 'فتحي',
            'جواد', 'عبد الحكيم', 'حسني', 'وجيه', 'جراح',
        ], true);
    }

    /**
     * @return array{stance: string, note: string, close: bool}
     */
    public static function read(ChatbotConversation $conversation): array
    {
        $stored = is_array($conversation->metadata) ? ($conversation->metadata['invite'] ?? null) : null;
        if (! is_array($stored)) {
            return ['stance' => self::OPEN, 'note' => '', 'close' => false, 'sent' => false, 'plea' => false];
        }

        return [
            'stance' => self::normalize((string) ($stored['stance'] ?? '')),
            'note' => mb_substr(trim((string) ($stored['note'] ?? '')), 0, 240),
            'close' => (bool) ($stored['close'] ?? false),
            'sent' => (bool) ($stored['sent'] ?? false),
            'plea' => (bool) ($stored['plea'] ?? false),
        ];
    }

    public static function remember(ChatbotConversation $conversation, string $stance, string $note, bool $close, bool $plea = false): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $previous = is_array($meta['invite'] ?? null) ? $meta['invite'] : [];
        $meta['invite'] = [
            'stance' => self::normalize($stance),
            'note' => mb_substr(trim($note), 0, 240),
            'close' => $close,
            'sent' => (bool) ($previous['sent'] ?? false),
            'plea' => $plea || (bool) ($previous['plea'] ?? false),
        ];
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    public static function delivered(ChatbotConversation $conversation): bool
    {
        if (self::read($conversation)['sent'] === true) {
            return true;
        }

        return ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('message_type', 'image')
            ->exists();
    }

    public static function markDelivered(ChatbotConversation $conversation): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $invite = is_array($meta['invite'] ?? null) ? $meta['invite'] : [];
        $invite['stance'] = self::normalize((string) ($invite['stance'] ?? self::OPEN));
        $invite['note'] = mb_substr(trim((string) ($invite['note'] ?? '')), 0, 240);
        $invite['close'] = (bool) ($invite['close'] ?? false);
        $invite['sent'] = true;
        $meta['invite'] = $invite;
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    /**
     * A first reply that refuses the list should not receive the poster.
     */
    public static function wantsOut(string $text): bool
    {
        $flat = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return self::isHardCancel($flat) || self::isSerious($flat);
    }

    public static function clarifyText(string $variant = self::WOMEN): string
    {
        return $variant === self::YOUTH ? 'بتحضر اللقاء؟' : self::CLARIFY;
    }

    /**
     * After the poster, a plain hello does not need a second question.
     *
     * @param  array{text?: string, stance?: string}  $decision
     */
    public static function invitationIsEnough(array $decision, string $variant = self::WOMEN): bool
    {
        return ($decision['stance'] ?? '') === self::OPEN
            && ($decision['text'] ?? '') === self::clarifyText($variant);
    }

    public static function normalize(string $stance): string
    {
        return in_array($stance, [self::POSITIVE, self::CANCEL, self::OPEN], true) ? $stance : self::OPEN;
    }

    /**
     * @param  list<array{role: string, message: string}>  $turns
     * @return array{text: string, stance: string, note: string, close: bool}
     */
    public static function nextReply(array $turns, ?string $name, array $state, string $variant = self::WOMEN): array
    {
        if (($state['close'] ?? false) === true) {
            return ['text' => '', 'stance' => self::normalize((string) ($state['stance'] ?? '')), 'note' => (string) ($state['note'] ?? ''), 'close' => true];
        }

        $latest = self::latestCustomerText($turns);
        if ($latest === '') {
            return ['text' => '', 'stance' => self::OPEN, 'note' => '', 'close' => false];
        }

        $pleaded = (bool) ($state['plea'] ?? false) || self::pleaWasSent($turns);
        $ruled = self::rule($latest, $variant, $pleaded);
        if ($ruled !== null || self::isGreeting($latest)) {
            return $ruled ?? self::clarify($variant);
        }

        $fromModel = self::askModel($turns, $name, $variant);
        if ($fromModel !== null) {
            if (str_contains((string) $fromModel['text'], 'ما في مجال بتاتا')) {
                if ($pleaded) {
                    return self::clarify($variant);
                }
                $fromModel['text'] = self::pleaFor($variant);
                $fromModel['stance'] = self::OPEN;
                $fromModel['close'] = false;
                $fromModel['plea'] = true;

                return $fromModel;
            }
            $fromModel['text'] = self::guard((string) $fromModel['text'], (string) $fromModel['stance'], $variant);

            return $fromModel;
        }

        return $ruled ?? self::clarify($variant);
    }

    /**
     * Customer lines that arrived together, after the last reply.
     *
     * @param  list<array{role: string, message: string}>  $turns
     */
    public static function latestCustomerText(array $turns): string
    {
        $parts = [];
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if (($turns[$i]['role'] ?? '') !== 'user') {
                break;
            }
            array_unshift($parts, trim((string) ($turns[$i]['message'] ?? '')));
        }

        return trim(implode("\n", array_values(array_filter($parts, static fn (string $part): bool => $part !== ''))));
    }

    /**
     * @return array{text: string, stance: string, note: string, close: bool}
     */
    public static function clarify(string $variant = self::WOMEN): array
    {
        return [
            'text' => self::clarifyText($variant),
            'stance' => self::OPEN,
            'note' => 'مو واضح',
            'close' => false,
        ];
    }

    public static function pleaFor(string $variant = self::WOMEN): string
    {
        if ($variant !== self::YOUTH) {
            return self::PLEA;
        }

        return self::PLEA."\n\n".self::YOUTH_BODY;
    }

    /**
     * Drop the old unsubscribe line and the old confirm-or-cancel footer.
     */
    public static function plainWomenCopy(string $text): string
    {
        $text = preg_replace('/^أهلًا وسهلًا أختي[^\n]*\R?/u', '', $text) ?? $text;
        $text = preg_replace('/\R*لإلغاء الاشتراك من القائمة البريدية[^\n]*/u', '', $text) ?? $text;
        $text = preg_replace('/\R*يرجى الإجابة بـ «تأكيد» أو «إلغاء»[^\n]*/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  list<array{role: string, message: string}>  $turns
     */
    private static function pleaWasSent(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant') {
                continue;
            }
            if (str_contains((string) ($turn['message'] ?? ''), 'ما في مجال بتاتا')) {
                return true;
            }
        }

        return false;
    }

    private static function isHardCancel(string $flat): bool
    {
        return preg_match('/الغاء|إلغاء|الغا|ألغ|وقفوا|لا تبعت|ما تبعت|لا اريد|لا أريد|مش مهتم|ما بدي رسائل|انسحب/u', $flat) === 1;
    }

    private static function isSerious(string $flat): bool
    {
        return preg_match('/وفاة|وفاه|توفي|توفّى|مات|موت|جنازة|جنازه|عزاء|مريض|مرض|مستشفى|مستشفي|عملية|عمليه|حادث|طوارئ|طوارىء|ولادة|ولاده/u', $flat) === 1;
    }

    private static function isSoftNo(string $flat): bool
    {
        if (preg_match('/فاضي|فاضيه|فاضية|مشغول|مشغولة|ما فيني|ما بقدر|مش قادر|مش قادرة|ارتباط|ما بيناسب|(?<!مو )(?<!مش )صعب|مو فاضي|مني فاض|ما في مجال|مستحيل/u', $flat) === 1) {
            return true;
        }

        return preg_match('/ما رح|مش رح|ما بدي أجي|ما بدي اجي|مش جاي|ما بحضر|مش حاضر|لن أحضر|لن احضر|ما أقدر أجي|ما اقدر اجي|^(?:لا|لأ|لاء)(?:\s|$)|^(?:لا|لأ|لاء|no)$/u', $flat) === 1;
    }

    private static function cancelKind(string $flat): ?string
    {
        if (self::isHardCancel($flat)) {
            return 'إلغاء';
        }
        if (self::isSoftNo($flat)) {
            return 'رفضت الحضور';
        }

        return null;
    }

    /**
     * @return array{text: string, stance: string, note: string, close: bool, plea?: bool}|null
     */
    private static function rule(string $text, string $variant = self::WOMEN, bool $pleaded = false): ?array
    {
        $flat = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $lines = self::copy($variant);
        if (self::isSerious($flat)) {
            return [
                'text' => $lines['serious'],
                'stance' => self::CANCEL,
                'note' => 'سبب قوي',
                'close' => true,
            ];
        }
        if (self::isHardCancel($flat) || ($pleaded && self::isSoftNo($flat))) {
            return [
                'text' => $lines['cancel'],
                'stance' => self::CANCEL,
                'note' => self::isHardCancel($flat) ? 'إلغاء' : 'ما في مجال',
                'close' => true,
            ];
        }
        if (preg_match('/وين|أين|اين|امتى|إمتى|متى|الساعة|قديش|المكان|فين المؤتمر|عنوان/u', $flat) === 1) {
            return [
                'text' => $lines['when'],
                'stance' => self::OPEN,
                'note' => $lines['note'],
                'close' => false,
            ];
        }
        if (! $pleaded && self::isSoftNo($flat)) {
            return [
                'text' => self::pleaFor($variant),
                'stance' => self::OPEN,
                'note' => 'اعتذار عن الحضور',
                'close' => false,
                'plea' => true,
            ];
        }
        if (preg_match('/^(?:«)?تم(?:»)?[.!?؟]*$/u', $flat) === 1) {
            return [
                'text' => $lines['yes'],
                'stance' => self::POSITIVE,
                'note' => 'أكدت الحضور',
                'close' => true,
            ];
        }
        if (preg_match('/تأكيد|تاكيد|توكيد|ايجي|أجي|بجي|جاي|حاضر|تمام|اكيد|أكيد|ان شاء الله|إن شاء الله|باذن الله|بإذن الله|اؤكد|أؤكد|نعم|ايوا|أيوا|موافق|رح اجي|رح أجي|بكون هناك|ساحضر|سأحضر|سجلت|سجّلت|سجلنا|في مجال/u', $flat) === 1) {
            return [
                'text' => $lines['yes'],
                'stance' => self::POSITIVE,
                'note' => 'أكدت الحضور',
                'close' => true,
            ];
        }

        return null;
    }

    /**
     * @return array{cancel: string, serious: string, when: string, yes: string, note: string}
     */
    private static function copy(string $variant): array
    {
        if ($variant === self::YOUTH) {
            return [
                'cancel' => 'تمام، ولا يهمك. شلنا اسمك من القائمة، وبتمنى نشوفك بمناسبة ثانية. يعطيك العافية 🌸',
                'serious' => 'تمام متاسف على الازعاج ولا يهمك، تسلم على وقتك 🌸',
                'when' => 'اللقاء السبت 24.10.2026 الساعة 14:00 بقاعة الأوبرا في طمرة. للتسجيل: https://forms.gle/mgzREfC9RWnna7TX7. بتحضر؟',
                'yes' => 'يسعدنا هالشي. منستناك ومنرحب فيك باللقاء.',
                'note' => 'سأل عن الموعد',
            ];
        }

        return [
            'cancel' => 'تمام، ولا يهمك. شلنا اسمك من القائمة، وبتمنى نشوفك بمناسبة ثانية. يعطيكي العافية 🌸',
            'serious' => 'تمام متاسفه على الازعاج ولا يهمك، تسلمي على وقتك 🌸',
            'when' => 'المؤتمر السبت 24.10.2026 الساعة 11:30 بقاعة الأوبرا في طمرة. للاستفسار 0502018556. بتحضري؟',
            'yes' => 'يسعدنا هالشي. منستناكِ ومنرحب فيكِ بالمؤتمر.',
            'note' => 'سألت عن الموعد',
        ];
    }

    private static function isGreeting(string $text): bool
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return preg_match('/^(?:اهلين|أهلين|اهلا|أهلا|يا هلا|هلا|مرحبا|مرحبًا|مرحباً|السلام عليكم|سلام عليكم|سلام|هاي|hi|hello|كيفك|كيف حالكم|شو اخبارك|شو أخبارك|صباح الخير|مساء الخير)\s*[\.\!\?؟]*$/iu', $flat) === 1;
    }

    private static function guard(string $text, string $stance, string $variant = self::WOMEN): string
    {
        $lines = self::copy($variant);
        $generic = preg_match('/مساعدتك|كيف يمكنني|انا هنا|أنا هنا|how can i help/ui', $text) === 1;
        if ($stance === self::POSITIVE) {
            return $generic ? $lines['yes'] : $text;
        }
        if ($stance === self::CANCEL) {
            $blunt = preg_match('/اسمك انشال|انشال اسمك/u', $text) === 1;

            return $generic || $blunt ? $lines['cancel'] : $text;
        }
        if ($generic || preg_match('/بتحضر|حضور|بتيج|بتيجي/u', $text) !== 1) {
            return self::clarifyText($variant);
        }

        return $text;
    }

    /**
     * @param  list<array{role: string, message: string}>  $turns
     * @return array{text: string, stance: string, note: string, close: bool}|null
     */
    private static function askModel(array $turns, ?string $name, string $variant = self::WOMEN): ?array
    {
        $who = $variant === self::YOUTH ? 'اسمه الأول' : 'اسمها الأول';
        $voice = $variant === self::YOUTH ? 'أرجع' : 'أرجعي';
        $messages = [
            ['role' => 'system', 'content' => self::instructions($variant)."\n".$who.': '.(self::givenName($name) !== '' ? self::givenName($name) : 'غير معروف')."\n".$voice.' JSON فقط: {"stance":"positive|cancel|open","text":"","note":"","close":false}'],
        ];
        foreach ($turns as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = trim((string) ($turn['message'] ?? ''));
            if ($text === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $text];
        }

        $raw = self::complete($messages);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }
        $text = trim((string) ($decoded['text'] ?? ''));
        if ($text === '') {
            return null;
        }
        $stance = self::normalize((string) ($decoded['stance'] ?? ''));
        $close = (bool) ($decoded['close'] ?? false) || in_array($stance, [self::POSITIVE, self::CANCEL], true);

        return [
            'text' => mb_substr($text, 0, 400),
            'stance' => $stance,
            'note' => mb_substr(trim((string) ($decoded['note'] ?? '')), 0, 240),
            'close' => $close,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private static function complete(array $messages): ?string
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

        $apiKey = MowahadaOpenAi::key(null);
        if ($apiKey === '') {
            Log::warning('invite-bot.missing-key');

            return null;
        }

        try {
            $response = Http::timeout(25)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => self::MODEL,
                    'temperature' => 0.2,
                    'max_tokens' => 180,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => $messages,
                ]);
        } catch (ConnectionException) {
            Log::warning('invite-bot.timeout');

            return null;
        }
        if (! $response->successful()) {
            Log::warning('invite-bot.http', ['status' => $response->status()]);

            return null;
        }
        $content = $response->json('choices.0.message.content');

        return is_string($content) && trim($content) !== '' ? $content : null;
    }
}
