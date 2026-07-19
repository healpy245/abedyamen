<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\WhatsApp\WhatsAppStaffAgentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppBotController extends Controller
{
    private const PUBLIC_BASE_URL = 'https://kaman-workspace.com';
    private const GREEN_API_SEND_MESSAGE_URL = 'https://7107.api.greenapi.com/waInstance7107542731/sendMessage/9e37d1ee067f4a21bc79cf714d5f250d265f7e31b6294b22b8';
    private const GREEN_API_SEND_TYPING_URL = 'https://7107.api.greenapi.com/waInstance7107542731/sendTyping/9e37d1ee067f4a21bc79cf714d5f250d265f7e31b6294b22b8';
    private const LEAD_CREATE_URL = 'https://mfit.karmelfiber.com/new-lead-mfit';
    private const LEAD_STATUS_VALUE = 4;
    private const TEAM_MEMBER_PHONE = '972584680001';
    private const CHAT_CACHE_PREFIX = 'whatsapp_lead_chat_';
    private const WEBHOOK_EVENTS_CACHE_KEY = 'whatsapp_webhook_events';
    private const WEBHOOK_ACTIVE_CACHE_KEY = 'whatsapp_webhook_active';
    /** Unix timestamp: ignore incoming messages older than this after a resume. */
    private const WEBHOOK_RESUME_AFTER_CACHE_KEY = 'whatsapp_webhook_resume_after';
    private const PROMPT_CACHE_KEY = 'whatsapp_chatbot_prompt';
    private const PROMPT_DB_KEY = 'system_prompt';
    private const PROCESSED_MESSAGE_CACHE_PREFIX = 'whatsapp_processed_message_';
    private const CHAT_HISTORY_LIMIT = 40;
    private const MAX_INCOMING_MESSAGE_AGE_SECONDS = 300;
    /** @var array<int, string> */
    private const STAFF_NUMBERS = [
        '0524060606',
        '0504680004',
        '0584680001',
        '0536233649',
        '0523899868',
        '0524586302',
        '0549133538',
    ];
    /** @var array<int, string> */
    private const CEO_NUMBERS = [
        '0524060606',
        '0584680001',
    ];
    /** @var array<int, string> */
    private const COWORKER_NUMBERS = [
        '0549133538',
    ];
    private const STAFF_AGENT_CHAT_PREFIX = 'staff_agent_';
    private const SYSTEM_PROMPT = <<<'PROMPT'
# KAMAN POS AI SALES AGENT

أنت مندوب المبيعات والدعم الذكي الرسمي لشركة KAMAN POS.

## هوية البوت

الاسم: KAMAN Assistant

إذا سأل العميل:
"هل أنت إنسان؟"

أجب بشكل طبيعي:

"أنا مساعد ذكاء اصطناعي من فريق KAMAN، ومدرب على نظام المطاعم الخاص فينا. وإذا احتجت أي مساعدة إضافية بقدر أوصلك مع أحد أعضاء الفريق."

إذا واجهت سؤالًا لا تعرف إجابته:

"هاي المعلومات الموجودة عندي حالياً، وبطلب من أحد أعضاء الفريق يتواصل معك بأقرب فرصة."

---

## العميل يظن أن الرقم للشليح / מנדוב توصيل

أحياناً يرسل العميل رسائل قصيرة مثل:
* "شليح"
* "שליח?"
* "שליח"
* "وين الشليح؟"
* "השליח?"

هذا يعني غالباً أنه يظن أن رقم الواتساب هذا تابع **لسائق توصيل أو مندوب delivery** — وهذا **خطأ**.

**هذا الرقم ليس رقم شليح أو توصيل.** هذا الرقم الرسمي لـ **KAMAN POS** — نظام كاشير وإدارة مطاعم.

عند هذه الرسائل:
* لا تتظاهر أنك الشليح.
* لا تسأل عن عنوان التوصيل أو رقم الطلب.
* لا تطلب تفاصيل delivery.
* أوضح بلطف وبوضوح أن هذا خطأ في الرقم، وأنكم KAMAN POS.
* اختم بسؤال قصير إذا كان عنده مطعم وبده يسمع عن النظام.

**مثال بالعربية:**
"مرحبا 😊
هذا الرقم مو تبع شليح أو توصيل.
نحن KAMAN POS — نظام كاشير وإدارة مطاعم.
إذا عندك مطعم وبدك تسمع عن النظام، أنا موجود."

**مثال بالعبرية:**
"היי 😊
זה לא מספר של שליח או משלוח.
אנחנו KAMAN POS — מערכת קופה וניהול למסעדות.
אם יש לך מסעדה ורוצה לשמוע על המערכת, אני כאן."

رد بنفس لغة رسالة العميل (عربي / עברית / English).

---

# اللغات

رد بنفس لغة العميل دائماً:

* العربية → العربية
* العبرية → العبرية
* الإنجليزية → الإنجليزية

إذا بدأ العميل بالعربية:
استمر بالعربية.

---

# أسلوب الحديث

مهم جداً:

* تصرف كشخص حقيقي.
* لا تتحدث كروبوت.
* استخدم أسلوب واتساب طبيعي.
* ردود قصيرة.
* لا تستخدم فقرات طويلة.
* لا تكرر نفس الجمل دائماً.
* لا تكرر نفس السؤال بنفس الصيغة.
* استخدم العامية العربية المحلية وليس الفصحى.
* تحدث مع العميل بنفس أسلوبه.

إذا كان العميل:

* جدي → كن جاداً.
* يمزح → امزح بلطف.
* سريع → كن سريعاً ومباشراً.

إذا سأل العميل:
"كيفك؟"

أجب:

"شكراً لسؤالك 😊 أهم إشي إنت تكون بخير، كيف بقدر أساعدك؟"

---

# أسلوب الكلام المحلي

يمكن استخدام بعض الكلمات الدارجة المنتشرة بسوق المطاعم داخل إسرائيل بشكل طبيعي:

* بسيدر
* حفيلاه
* سوخن
* معريخت
* هدرخاه
* سليكا

لكن لا تبالغ باستخدامها.

---

# شخصية البوت

العمر: 30 سنة

الجنس: أنثى

إذا سأل العميل أسئلة شخصية:

* لا تدخل في تفاصيل شخصية.
* لا تتحدث عن الحالة الاجتماعية.
* أعد الحديث لموضوع المطعم أو النظام.

---

# أهداف البوت

1. الترحيب بالعميل.
2. جمع بيانات العميل المحتمل.
3. فهم احتياجات المطعم.
4. شرح النظام.
5. الرد على الأسئلة والاعتراضات.
6. بناء ثقة مع العميل.
7. نقل العميل لمرحلة الديمو أو اللقاء.
8. زيادة نسبة الإغلاق والمبيعات.

---

# بداية المحادثة

في أول رسالة فقط:

مرحبا 👋

معك KAMAN Assistant من فريق KAMAN POS.

هاي معريخت متكاملة لإدارة المطاعم من الكاشير والطلبات وحتى متابعة المبيعات.

قبل ما نكمل، ممكن اسمك والمدينة اللي موجود فيها المطعم؟

---

# جمع بيانات العميل

في بداية المحادثة حاول الحصول على:

* الاسم
* المدينة

إذا حصلت على معلومة واحدة فقط:

اسأل عن الثانية بشكل قصير وطبيعي.

بعد الحصول على الاثنين تابع المحادثة.

---

# أسئلة التعرف على المطعم

اسأل بشكل تدريجي وليس دفعة واحدة:

* شو نوع المطعم عندك؟
* كم فرع عندك؟
* عندك توصيل؟
* بتستعمل معريخت حالياً؟
* أي معريخت بتستعمل؟

---

# معلومات المنتج

KAMAN POS هي معريخت متكاملة لإدارة المطاعم بطريقة ذكية وسهلة.

النظام بساعد المطاعم يديروا:

* الكاشير
* الطلبات
* المطبخ
* المبيعات
* الموظفين
* الفروع
* العمليات اليومية

---

# الميزات

* نظام كاشير سحابي
* نظام للنادل
* شاشة مطبخ
* طباعة عربي / عبري / إنجليزي
* تكامل HAAT (هات / האט)
* منيو رقمي
* CRM
* تقرير Z
* إرسال فواتير SMS
* دعم سليكا
* دعم 4G

ممنوع اختراع أو إضافة ميزات غير مذكورة.

---

# تكامل HAAT (هات / האט)

إذا سأل العميل عن **هات** أو **HAAT** أو **האט**، أو قال إنه:
* بدّه يشغّل هات
* عنده هات وبدّه يربطه
* بدو אינטגרציה مع هات
* بيستقبل طلبات من هات

اشرح له بشكل واضح وقصير عن **אינטגרציית HAAT** (تكامل / انتجراسيا HAAT) في KAMAN POS:

**شو بيعمل التكامل؟**
* بيستقبل طلبيات **هات** بشكل **أوتوماتيكي** داخل النظام.
* بيرسل الطلب **مباشرة للمطبخ** (شاشة المطبخ / KDS) بدون ما يضطر الموظف يعيد إدخال الطلب من الصفر على الكاشير.
* بيقلّل الطباعة المتكررة للورق — ما في داعي تطبع ورقة وتعيد إنشاء نفس الطلب يدوياً على الكاشير.

**كيف تشرحها للزبون (مثال):**
"إذا عندك هات أو بدك تشغّله، KAMAN POS فيها **אינטגרציית HAAT**.
يعني طلبيات هات بتيجي أوتوماتيكي للنظام وبتروح للمطبخ مباشرة، بدون ما تضطر تطبع وتعيد إدخال الطلب على الكاشير كل مرة."

**إذا سأل بالعبرية — مثال:**
"אם יש לך HAAT או שאתה רוצה להפעיל אותו, ל-KAMAN POS יש **אינטגרציית HAAT**.
הזמנות מ-HAAT נכנסות אוטומטית למערכת ונשלחות ישר למטבח, בלי להדפיס שוב ושוב ובלי ליצור את ההזמנה מחדש בקופה."

**قواعد:**
* لا تعد بموعد تفعيل محدد — قل إن الفريق بيساعد بالربط والإعداد.
* لا تخترع تفاصيل تقنية غير مذكورة.
* بعد الشرح، اسأل سؤالاً واحداً يساعدك تفهم وضعه: "عندك هات شغّال حالياً؟" أو "كم طلب تقريباً بتيجي من هات باليوم؟"

---

# التسعير

الأسعار تبدأ من:

299 شيكل شهرياً + ضريبة القيمة المضافة.

السعر النهائي يعتمد على:

* عدد الفروع
* المزايا المطلوبة
* التكاملات المطلوبة
* الأجهزة المطلوبة
* احتياجات المطعم التشغيلية

---

# عند السؤال عن السعر

إذا سأل العميل عن السعر:

أجب:

"الأسعار بتبدأ من 299 شيكل بالشهر + ضريبة.

السعر النهائي بيعتمد على شو الخدمات والمزايا اللي بدك تشغلها بالمطعم."

ثم تابع بسؤال يساعد على فهم احتياجه:

* شو نوع المطعم عندك؟
  أو
* كم فرع عندك؟
  أو
* شو أهم إشي بدك المعريخت تساعدك فيه؟

---

# التعامل مع المنافسين

تعرف على:

* Becom
* بيكوم
* פיקוו
* Leenkobot
* لينكوبوت
* לינקובוט
* T Bon
* تي بون
* טי בון
* Aviv
* افيف
* אביב
* Tabit
* تابيت
* טאביט

---

## Becom / Leenkobot / T Bon / Aviv

اشرح بشكل مختصر:

* أغلبها أنظمة أقدم.
* قدراتها السحابية محدودة مقارنة بالأنظمة الحديثة.
* أقل جاهزية للأتمتة والتحليلات الحديثة وميزات الذكاء الاصطناعي.

يمكن توضيح:

"اليوم وجود البيانات على الكلاود صار مهم جداً للتحليلات والأتمتة والتطوير المستقبلي."

ويمكن ذكر:

"كثير من الأنظمة بالسوق تعتمد على أجهزة رخيصة نسبياً ويتم بيعها ضمن حزم بأسعار مرتفعة مقارنة بالقيمة الفعلية."

لا تستخدم لغة هجومية.

لا تستخدم اتهامات.

---

## Tabit

إذا كان يستخدم Tabit:

اشرح:

"KAMAN بتعطي نفس الفكرة الأساسية لكن مع مرونة أكبر، تطوير أسرع، مزايا أكثر، وإمكانيات ذكاء اصطناعي مع تكلفة أقل."

---

# بعد الحديث عن المنافس

اسأل دائماً:

"شو أكثر مشاكل بتواجهك حالياً بالمعريخت اللي عندك؟"

بعدها:

* افهم المشكلة.
* أعطِ حلاً عملياً.
* اربط الحل بميزة موجودة داخل KAMAN.

---

# معالجة المشاكل

إذا ذكر العميل مشكلة:

* لا تنتقل مباشرة للديمو.
* جاوب على المشكلة أولاً.
* وضح كيف ممكن KAMAN تساعده.
* ابقِ الردود قصيرة وعملية.

---

# الطلبات الخاصة والتطوير

إذا طلب العميل ميزة خاصة أو سير عمل معقد:

قل:

"واحدة من أهم ميزاتنا إنو إحنا الفريق اللي بنى النظام من الصفر."

"عنا تقريباً 22 مبرمج."

"إذا في احتياج خاص غالباً بنقدر نطوره أو نلاقي إله حل مناسب."

لا تعد بشيء غير مؤكد.

---

# العميل الغاضب

إذا كان العميل غاضباً:

* حافظ على الهدوء.
* تفهم المشكلة.
* اعتذر عن الإزعاج.
* حاول تهدئته.
* أظهر اهتماماً حقيقياً.

مثال:

"معك حق تنزعج من هيك موضوع، خليني أفهم أكثر وإن شاء الله نلاقي أفضل حل."

---

# طلب مندوب بشري

إذا طلب العميل مندوب:

حاول أولاً مساعدته بنفسك.

إذا أصر:

أخبره أن أحد أعضاء الفريق سيتواصل معه قريباً.

---

# ترتيب ديمو أو لقاء

إذا أبدى العميل اهتماماً:

قل:

"ممتاز 👍

أحد أعضاء فريقنا رح يتواصل معك بأقرب فرصة لترتيب ديمو أو لقاء مناسب."

---

# سياسة المواعيد

ممنوع على البوت:

* تحديد موعد.
* تأكيد موعد.
* اختيار ساعة معينة.

فقط أخبر العميل أن الفريق سيتواصل معه.

---

# ساعات العمل

الأحد - الخميس

إذا طلب موعد:

أخبره أن أحد أعضاء الفريق سيتواصل معه خلال ساعات العمل لترتيب الموعد.

---

# قواعد مهمة

* لا تخترع ميزات.
* لا تخترع أسعار.
* لا تخترع تكاملات.
* لا تؤكد وجود ميزة غير مذكورة.
* لا تعطِ وعوداً تقنية غير مؤكدة.
* لا تكتب ردوداً طويلة.
* لا تخرج عن موضوع المطاعم وKAMAN إلا إذا طلب العميل ذلك.
* لا تكرر الأسئلة بشكل مزعج.
* حاول دائماً دفع المحادثة خطوة للأمام.

---

# إنهاء المحادثة

إذا انتهت المحادثة:

"ولا يهمك 😊

إذا احتجت أي مساعدة إحنا موجودين."

OUTPUT RULE:
Return plain text only, no JSON, no markdown.
PROMPT;

    public function __construct(
        private readonly WhatsAppStaffAgentService $staffAgentService,
    ) {}

    public function index(Request $request)
    {
        return view('whatsapp-bot', [
            'webhookUrl' => rtrim(self::PUBLIC_BASE_URL, '/') . '/whatsapp-bot/webhook',
            'lastResult' => $request->session()->get('whatsapp_bot_last_result'),
            'webhookActive' => $this->isWebhookActive(),
            'chatbotPrompt' => $this->getSystemPrompt(),
        ]);
    }

    public function savePrompt(Request $request)
    {
        $validated = $request->validate([
            'prompt' => 'required|string|min:30|max:30000',
        ]);

        $prompt = trim($validated['prompt']);

        DB::table('whatsapp_settings')->updateOrInsert(
            ['key' => self::PROMPT_DB_KEY],
            ['value' => $prompt, 'updated_at' => now(), 'created_at' => now()]
        );

        Cache::forever(self::PROMPT_CACHE_KEY, $prompt);

        return response()->json([
            'ok' => true,
            'message' => 'Prompt saved successfully.',
        ]);
    }

    public function resetPrompt()
    {
        DB::table('whatsapp_settings')
            ->where('key', self::PROMPT_DB_KEY)
            ->delete();

        Cache::forget(self::PROMPT_CACHE_KEY);

        return response()->json([
            'ok' => true,
            'message' => 'Prompt reset to default.',
            'prompt' => self::SYSTEM_PROMPT,
        ]);
    }

    public function events()
    {
        $events = Cache::get(self::WEBHOOK_EVENTS_CACHE_KEY, []);
        if (!is_array($events)) {
            $events = [];
        }

        return response()->json([
            'ok' => true,
            'count' => count($events),
            'events' => array_values($events),
            'server_time' => now()->toIso8601String(),
            'webhook_active' => $this->isWebhookActive(),
        ]);
    }

    public function toggleWebhook()
    {
        $newState = !$this->isWebhookActive();
        Cache::forever(self::WEBHOOK_ACTIVE_CACHE_KEY, $newState);

        // On resume, drop anything that was sent while the bot was off (and any
        // backlog that arrives after reactivation with an older timestamp).
        if ($newState) {
            Cache::forever(self::WEBHOOK_RESUME_AFTER_CACHE_KEY, now()->timestamp);
        }

        return response()->json([
            'ok' => true,
            'webhook_active' => $newState,
            'message' => $newState ? 'Webhook activated.' : 'Webhook deactivated.',
        ]);
    }

    public function clearEvents()
    {
        Cache::forget(self::WEBHOOK_EVENTS_CACHE_KEY);

        return response()->json([
            'ok' => true,
            'message' => 'Webhook events cleared.',
        ]);
    }

    public function webhook(Request $request)
    {
        if (!$this->isIncomingMessageWebhook($request)) {
            $this->pushWebhookEvent([
                'status' => 'ignored',
                'chat_id' => $this->extractChatId($request),
                'incoming' => $this->extractIncomingMessage($request),
                'reply' => null,
                'green_api_status' => null,
                'reason' => 'Ignored non-incoming webhook event.',
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored non-incoming webhook event.',
            ]);
        }

        if (!$this->isWebhookActive()) {
            $messageId = $this->extractMessageId($request);
            if ($messageId !== null) {
                $this->markMessageAsProcessed($messageId);
            }

            // Acknowledge without storing content so the AI never "sees" paused traffic.
            $this->pushWebhookEvent([
                'status' => 'deactivated',
                'chat_id' => $this->extractChatId($request),
                'incoming' => null,
                'reply' => null,
                'green_api_status' => null,
                'reason' => 'Webhook is deactivated from dashboard.',
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'Webhook is deactivated.',
            ]);
        }

        $chatId = $this->extractChatId($request);
        $incomingMessage = $this->extractIncomingMessage($request);
        $messageId = $this->extractMessageId($request);
        $messageTimestamp = $this->extractMessageTimestamp($request);

        $resumeAfter = $this->webhookResumeAfterTimestamp();
        if ($resumeAfter !== null && $messageTimestamp !== null && $messageTimestamp < $resumeAfter) {
            if ($messageId !== null) {
                $this->markMessageAsProcessed($messageId);
            }

            $this->pushWebhookEvent([
                'status' => 'ignored',
                'chat_id' => $chatId,
                'incoming' => null,
                'reply' => null,
                'green_api_status' => null,
                'reason' => 'Ignored message sent while webhook was inactive.',
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored message sent while webhook was inactive.',
            ]);
        }

        if ($messageTimestamp !== null && (now()->timestamp - $messageTimestamp) > self::MAX_INCOMING_MESSAGE_AGE_SECONDS) {
            $this->pushWebhookEvent([
                'status' => 'ignored',
                'chat_id' => $chatId,
                'incoming' => $incomingMessage,
                'reply' => null,
                'green_api_status' => null,
                'reason' => 'Ignored stale incoming message event.',
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored stale incoming message event.',
            ]);
        }

        if ($messageId !== null && $this->isMessageAlreadyProcessed($messageId)) {
            $this->pushWebhookEvent([
                'status' => 'ignored',
                'chat_id' => $chatId,
                'incoming' => $incomingMessage,
                'reply' => null,
                'green_api_status' => null,
                'reason' => 'Ignored duplicate incoming message event.',
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored duplicate incoming message event.',
            ]);
        }

        if (!$chatId || !$incomingMessage) {
            $this->pushWebhookEvent([
                'status' => 'ignored',
                'chat_id' => null,
                'incoming' => null,
                'reply' => null,
                'green_api_status' => null,
                'reason' => 'No chatId or text message found in webhook payload.',
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'No chatId or text message found in webhook payload.',
            ]);
        }

        if ($this->isStaffAgentChatId($chatId)) {
            if ($messageId !== null) {
                $this->markMessageAsProcessed($messageId);
            }

            try {
                $isCeo = $this->isCeoChatId($chatId);
                $reply = $this->staffAgentService->handleMessage($incomingMessage, $isCeo);
                $this->emitTypingIndicator($chatId);
                $this->applyHumanLikeDelay($reply);
                $sendResult = $this->sendWhatsAppMessage($chatId, $reply);
            } catch (\Throwable $e) {
                $reply = 'صار في مشكلة بمعالجة الطلب. جرب مرة ثانية.';
                $sendResult = null;

                try {
                    $this->emitTypingIndicator($chatId);
                    $this->applyHumanLikeDelay($reply);
                    $sendResult = $this->sendWhatsAppMessage($chatId, $reply);
                } catch (\Throwable $sendError) {
                    Log::error('Staff agent fallback Green API send failed', [
                        'chat_id' => $chatId,
                        'error' => $sendError->getMessage(),
                    ]);
                }

                $this->pushWebhookEvent([
                    'status' => 'fallback',
                    'chat_id' => $chatId,
                    'incoming' => $incomingMessage,
                    'reply' => $reply,
                    'green_api_status' => $sendResult['status'] ?? null,
                    'reason' => 'Staff agent: ' . $e->getMessage(),
                ]);

                return response()->json([
                    'ok' => true,
                    'fallback' => true,
                    'mode' => 'staff_agent',
                    'chatId' => $chatId,
                    'incoming' => $incomingMessage,
                    'reply' => $reply,
                ]);
            }

            $this->pushWebhookEvent([
                'status' => 'processed',
                'chat_id' => $chatId,
                'incoming' => $incomingMessage,
                'reply' => $reply,
                'green_api_status' => $sendResult['status'] ?? null,
                'reason' => 'Staff agent (' . ($isCeo ? 'CEO' : 'coworker') . ')',
            ]);

            return response()->json([
                'ok' => true,
                'mode' => 'staff_agent',
                'chatId' => $chatId,
                'incoming' => $incomingMessage,
                'reply' => $reply,
                'green_api_status' => $sendResult['status'],
            ]);
        }

        if ($this->isStaffChatId($chatId)) {
            $this->pushWebhookEvent([
                'status' => 'ignored',
                'chat_id' => $chatId,
                'incoming' => $incomingMessage,
                'reply' => null,
                'green_api_status' => null,
                'reason' => 'Ignored staff number.',
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored staff number.',
            ]);
        }

        if ($messageId !== null) {
            $this->markMessageAsProcessed($messageId);
        }

        try {
            $reply = $this->generateReply($chatId, $incomingMessage);
            $this->emitTypingIndicator($chatId);
            $this->applyHumanLikeDelay($reply);
            $sendResult = $this->sendWhatsAppMessage($chatId, $reply);
            $postReply = $this->handlePostReplyActions($chatId, $incomingMessage);
            $leadSync = $postReply['lead_sync'];
            $teamNotify = $postReply['team_notify'];
        } catch (\Throwable $e) {
            $fallbackReply = $this->fallbackReply($chatId, $incomingMessage);
            $sendResult = null;
            $postReply = $this->handlePostReplyActions($chatId, $incomingMessage);
            $leadSync = $postReply['lead_sync'];
            $teamNotify = $postReply['team_notify'];

            try {
                $this->emitTypingIndicator($chatId);
                $this->applyHumanLikeDelay($fallbackReply);
                $sendResult = $this->sendWhatsAppMessage($chatId, $fallbackReply);
            } catch (\Throwable $sendError) {
                Log::error('Fallback Green API send failed', [
                    'chat_id' => $chatId,
                    'error' => $sendError->getMessage(),
                ]);
            }

            $this->pushWebhookEvent([
                'status' => 'fallback',
                'chat_id' => $chatId,
                'incoming' => $incomingMessage,
                'reply' => $fallbackReply,
                'green_api_status' => $sendResult['status'] ?? null,
                'reason' => $e->getMessage()
                    . ($leadSync['message'] !== '' ? ' | Lead sync: ' . $leadSync['message'] : '')
                    . ($teamNotify['message'] !== '' ? ' | Team notify: ' . $teamNotify['message'] : ''),
            ]);

            Log::error('WhatsApp webhook chatbot failed', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
            ]);

            return response()->json([
                'ok' => true,
                'fallback' => true,
                'chatId' => $chatId,
                'incoming' => $incomingMessage,
                'reply' => $fallbackReply,
                'green_api_status' => $sendResult['status'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        $this->pushWebhookEvent([
            'status' => 'processed',
            'chat_id' => $chatId,
            'incoming' => $incomingMessage,
            'reply' => $reply,
            'green_api_status' => $sendResult['status'] ?? null,
            'reason' => trim(implode(' | ', array_values(array_filter([
                $leadSync['message'] !== '' ? 'Lead sync: ' . $leadSync['message'] : null,
                $teamNotify['message'] !== '' ? 'Team notify: ' . $teamNotify['message'] : null,
            ])))) ?: null,
        ]);

        return response()->json([
            'ok' => true,
            'chatId' => $chatId,
            'incoming' => $incomingMessage,
            'reply' => $reply,
            'green_api_status' => $sendResult['status'],
        ]);
    }

    public function testSend(Request $request)
    {
        $validated = $request->validate([
            'chat_id' => 'required|string|max:255',
            'message' => 'required|string|max:4000',
        ]);

        $reply = $this->generateReply($validated['chat_id'], $validated['message']);
        $sendResult = $this->sendWhatsAppMessage($validated['chat_id'], $reply);

        $result = [
            'chat_id' => $validated['chat_id'],
            'incoming' => $validated['message'],
            'reply' => $reply,
            'green_api_status' => $sendResult['status'],
            'green_api_body' => $sendResult['body'],
        ];

        return redirect()
            ->route('whatsapp.bot.index')
            ->with('whatsapp_bot_last_result', $result);
    }

    public function testChat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
        ]);

        $chatId = $this->testChatId();

        try {
            $reply = $this->generateReply($chatId, $validated['message']);
            $postReply = $this->handlePostReplyActions($chatId, $validated['message']);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $this->formatOpenAiClientError($e),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'reply' => $reply,
            'history' => $this->loadChatHistory($chatId),
            'team_notify' => $postReply['team_notify'],
            'lead_sync' => $postReply['lead_sync'],
        ]);
    }

    public function testStaffChat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'role' => 'required|string|in:ceo,coworker',
        ]);

        $isCeo = $validated['role'] === 'ceo';
        $chatId = $this->staffAgentTestChatId($validated['role']);

        try {
            $reply = $this->staffAgentService->handleMessage($validated['message'], $isCeo);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $this->formatOpenAiClientError($e),
            ], 422);
        }

        $history = $this->loadChatHistory($chatId);
        $history[] = ['role' => 'user', 'content' => trim($validated['message'])];
        $history[] = ['role' => 'assistant', 'content' => $reply];
        $this->saveChatHistory($chatId, $history);

        return response()->json([
            'ok' => true,
            'reply' => $reply,
            'history' => $this->loadChatHistory($chatId),
            'role' => $validated['role'],
        ]);
    }

    public function resetTestStaffChat(Request $request)
    {
        $validated = $request->validate([
            'role' => 'required|string|in:ceo,coworker',
        ]);

        $chatId = $this->staffAgentTestChatId($validated['role']);
        $historyPath = $this->chatHistoryPath($chatId);
        if (File::exists($historyPath)) {
            File::delete($historyPath);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Staff agent conversation reset.',
        ]);
    }

    public function resetTestChat()
    {
        $chatId = $this->testChatId();
        $historyPath = $this->chatHistoryPath($chatId);
        if (File::exists($historyPath)) {
            File::delete($historyPath);
        }

        $leadPath = $this->leadStatePath($chatId);
        if (File::exists($leadPath)) {
            File::delete($leadPath);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Conversation reset.',
        ]);
    }

    private function sendWhatsAppMessage(string $chatId, string $message): array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->post(self::GREEN_API_SEND_MESSAGE_URL, [
                'chatId' => $chatId,
                'message' => $message,
            ]);

        if (!$response->successful()) {
            Log::warning('Green API sendMessage failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'chat_id' => $chatId,
            ]);
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    private function emitTypingIndicator(string $chatId): void
    {
        try {
            Http::timeout(10)
                ->acceptJson()
                ->post(self::GREEN_API_SEND_TYPING_URL, [
                    'chatId' => $chatId,
                ]);
        } catch (\Throwable $e) {
            // Keep silent: typing indicator is best-effort only.
        }
    }

    private function applyHumanLikeDelay(string $reply): void
    {
        $length = mb_strlen(trim($reply));
        $minMs = 1200;
        $maxMs = 2600;

        if ($length > 180) {
            $maxMs = 3600;
        } elseif ($length > 90) {
            $maxMs = 3000;
        }

        $delayMs = random_int($minMs, $maxMs);
        usleep($delayMs * 1000);
    }

    private function fallbackReply(string $chatId, string $incomingMessage): string
    {
        $incoming = trim($incomingMessage);
        $normalized = mb_strtolower($incoming);
        $history = $this->loadChatHistory($chatId);
        $hasPreviousTurns = count($history) >= 2;

        if ($normalized !== '' && (str_contains($normalized, 'سعر') || str_contains($normalized, 'كم') || str_contains($normalized, 'شيكل'))) {
            return "بسيدر 👌\n"
                . "الأسعار بتبدأ من 299 شيكل بالشهر + ضريبة.\n\n"
                . "السعر النهائي بيعتمد على شو الخدمات والمزايا اللي بدك تشغلها بالمطعم.\n\n"
                . "شو نوع المطعم عندك؟";
        }

        if ($normalized !== '' && (str_contains($normalized, 'ديمو') || str_contains($normalized, 'زيارة') || str_contains($normalized, 'سوخن'))) {
            return "ممتاز 👍\n"
                . "أحد أعضاء فريقنا رح يتواصل معك بأقرب فرصة لترتيب ديمو أو لقاء مناسب.";
        }

        if ($hasPreviousTurns && $incoming !== '') {
            return "ممتاز، فهمت عليك 🙌\n"
                . "ذكرتلي: {$incoming}\n\n"
                . "كم فرع عندك؟ وهل عندكم توصيل؟";
        }

        return "مرحبا 👋\n\n"
            . "معك KAMAN Assistant من فريق KAMAN POS.\n\n"
            . "هاي معريخت متكاملة لإدارة المطاعم من الكاشير والطلبات وحتى متابعة المبيعات.\n\n"
            . "قبل ما نكمل، ممكن اسمك والمدينة اللي موجود فيها المطعم؟";
    }

    private function testChatId(): string
    {
        return 'test_session_' . session()->getId() . '@c.us';
    }

    private function isTestChatId(string $chatId): bool
    {
        return str_starts_with($chatId, 'test_session_');
    }

    private function shouldVerifyOpenAiSsl(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return false;
        }

        $sslVerify = config('openai.ssl_verify', config('services.openai.verify_ssl', true));

        return filter_var($sslVerify, FILTER_VALIDATE_BOOLEAN);
    }

    private function formatOpenAiClientError(\Throwable $e): string
    {
        $errorText = $e->getMessage();

        if (str_contains($errorText, 'SSL certificate problem') || str_contains($errorText, 'cURL error 60')) {
            return 'Unable to reach OpenAI due to SSL verification (cURL error 60). '
                . 'For local development, set OPENAI_SSL_VERIFY=false in .env, then run: php artisan optimize:clear';
        }

        return 'Failed to generate reply: ' . $errorText;
    }

    private function extractChatId(Request $request): ?string
    {
        $possiblePaths = [
            'senderData.chatId',
            'chatId',
            'messageData.chatId',
        ];

        foreach ($possiblePaths as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractIncomingMessage(Request $request): ?string
    {
        $possiblePaths = [
            'messageData.textMessageData.textMessage',
            'messageData.extendedTextMessageData.text',
            'messageData.caption',
            'message',
        ];

        foreach ($possiblePaths as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function isIncomingMessageWebhook(Request $request): bool
    {
        $typeWebhook = strtolower(trim((string) $request->input('typeWebhook', '')));

        // Green API uses this type for real incoming messages.
        if ($typeWebhook !== '' && $typeWebhook !== 'incomingmessagereceived') {
            return false;
        }

        // Extra safety: ignore events explicitly marked as sent by our own account.
        $fromMe = $request->input('messageData.fromMe');
        if (is_bool($fromMe) && $fromMe) {
            return false;
        }

        return true;
    }

    private function extractMessageId(Request $request): ?string
    {
        $possiblePaths = [
            'idMessage',
            'messageData.idMessage',
            'messageData.message.idMessage',
        ];

        foreach ($possiblePaths as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractMessageTimestamp(Request $request): ?int
    {
        $possiblePaths = [
            'timestamp',
            'messageData.timestamp',
            'messageData.messageTimestamp',
        ];

        foreach ($possiblePaths as $path) {
            $value = $request->input($path);
            if (is_int($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function isMessageAlreadyProcessed(string $messageId): bool
    {
        return Cache::has($this->processedMessageCacheKey($messageId));
    }

    private function markMessageAsProcessed(string $messageId): void
    {
        Cache::put($this->processedMessageCacheKey($messageId), true, now()->addDays(3));
    }

    private function processedMessageCacheKey(string $messageId): string
    {
        return self::PROCESSED_MESSAGE_CACHE_PREFIX . md5($messageId);
    }

    private function generateReply(string $chatId, string $incomingMessage): string
    {
        $history = $this->loadChatHistory($chatId);

        $history[] = ['role' => 'user', 'content' => trim($incomingMessage)];

        $messages = [['role' => 'system', 'content' => $this->getSystemPrompt()]];
        foreach (array_slice($history, -self::CHAT_HISTORY_LIMIT) as $message) {
            if (!isset($message['role'], $message['content'])) {
                continue;
            }
            $messages[] = [
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $message['content'],
            ];
        }

        $reply = trim($this->chatWithMessages($messages, [
            'max_tokens' => 450,
            'temperature' => 0.6,
        ]));

        if ($reply === '') {
            $reply = 'مرحبا 👋 معك فريق KAMAN. ممكن أعرف شو نوع المطعم عندك؟';
        }

        $history[] = ['role' => 'assistant', 'content' => $reply];
        $this->saveChatHistory($chatId, $history);

        return $reply;
    }

    /**
     * @param  array<int, array{role:string,content:string|array}>  $messages
     */
    private function chatWithMessages(array $messages, array $options = []): string
    {
        $apiKey = trim((string) (config('openai.api_key') ?: env('OPENAI_API_KEY', '')));
        $baseUrl = (string) (config('openai.base_uri') ?: 'https://api.openai.com/v1');
        $model = (string) ($options['model'] ?? config('openai.default_model', 'gpt-4o-mini'));
        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI API key is missing from runtime configuration.');
        }

        $http = Http::withToken($apiKey)
            ->timeout((int) config('openai.request_timeout', 30))
            ->acceptJson();

        if (config('openai.organization')) {
            $http = $http->withHeaders(['OpenAI-Organization' => config('openai.organization')]);
        }
        if (config('openai.project')) {
            $http = $http->withHeaders(['OpenAI-Project' => config('openai.project')]);
        }

        if (!$this->shouldVerifyOpenAiSsl()) {
            $http = $http->withoutVerifying()->withOptions([
                'verify' => false,
                'curl' => [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ],
            ]);
        }

        $endpoint = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.openai.com/v1', '/') . '/chat/completions';

        try {
            $response = $http->post($endpoint, [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? 0.6,
                'max_tokens' => $options['max_tokens'] ?? 450,
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException($this->formatOpenAiClientError($e));
        }

        if (!$response->successful()) {
            $body = $response->json() ?? [];
            $errorMessage = is_array($body)
                ? ($body['error']['message'] ?? $body['message'] ?? 'Unknown OpenAI error')
                : (string) $response->body();
            Log::error('OpenAI API error (WhatsApp bot)', [
                'status' => $response->status(),
                'body' => $body ?: $response->body(),
            ]);
            throw new \RuntimeException('OpenAI request failed with status ' . $response->status() . ': ' . $errorMessage);
        }

        $body = $response->json() ?? [];
        $content = $body['choices'][0]['message']['content'] ?? '';

        return is_string($content) ? trim($content) : '';
    }

    private function cacheKey(string $chatId): string
    {
        return self::CHAT_CACHE_PREFIX . md5($chatId);
    }

    /**
     * @return array<int, array{role:string,content:string}>
     */
    private function loadChatHistory(string $chatId): array
    {
        $path = $this->chatHistoryPath($chatId);
        if (!File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $history = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['role'], $entry['content'])) {
                continue;
            }
            $role = $entry['role'] === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) $entry['content']);
            if ($content === '') {
                continue;
            }
            $history[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $history;
    }

    /**
     * @param  array<int, array{role:string,content:string}>  $history
     */
    private function saveChatHistory(string $chatId, array $history): void
    {
        $trimmed = array_slice($history, -self::CHAT_HISTORY_LIMIT);
        $path = $this->chatHistoryPath($chatId);
        $directory = dirname($path);

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, json_encode($trimmed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function chatHistoryPath(string $chatId): string
    {
        return storage_path('app/whatsapp-chat-history/' . md5($chatId) . '.json');
    }

    /**
     * @return array{synced:bool,message:string}
     */
    private function syncLeadIfReady(string $chatId, string $latestIncomingMessage): array
    {
        $path = $this->leadStatePath($chatId);
        $state = $this->loadLeadState($chatId);

        if (($state['submitted'] ?? false) === true) {
            return ['synced' => true, 'message' => 'already submitted'];
        }

        $phone = $this->normalizePhoneFromChatId($chatId);
        $name = $this->extractLeadName($latestIncomingMessage) ?: ($state['name'] ?? null);
        $city = $this->extractLeadCity($latestIncomingMessage) ?: ($state['city'] ?? null);

        $state['phone'] = $phone;
        $state['name'] = $name;
        $state['city'] = $city;
        $this->saveLeadState($path, $state);

        if (!$phone || !$name || !$city) {
            return ['synced' => false, 'message' => 'waiting for required fields (name/city)'];
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->post(self::LEAD_CREATE_URL, [
                'phone' => $phone,
                'name' => $name,
                'status' => self::LEAD_STATUS_VALUE,
                'city' => $city,
            ]);

        if (!$response->successful()) {
            Log::warning('Lead sync failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'chat_id' => $chatId,
            ]);
            return ['synced' => false, 'message' => 'failed with HTTP ' . $response->status()];
        }

        $state['submitted'] = true;
        $state['submitted_at'] = now()->toIso8601String();
        $this->saveLeadState($path, $state);

        return ['synced' => true, 'message' => 'submitted successfully'];
    }

    /**
     * @return array{lead_sync: array{synced:bool,message:string}, team_notify: array{notified:bool,message:string}}
     */
    private function handlePostReplyActions(string $chatId, string $incomingMessage): array
    {
        return [
            'lead_sync' => $this->syncLeadIfReady($chatId, $incomingMessage),
            'team_notify' => $this->notifyTeamIfClientWantsToAdvance($chatId, $incomingMessage),
        ];
    }

    /**
     * @return array{notified:bool,message:string}
     */
    private function notifyTeamIfClientWantsToAdvance(string $chatId, string $incomingMessage): array
    {
        $state = $this->loadLeadState($chatId);
        if (($state['team_notified'] ?? false) === true) {
            return ['notified' => true, 'message' => 'already notified'];
        }

        if (!$this->isAdvanceIntent($incomingMessage)) {
            return ['notified' => false, 'message' => 'no advance trigger'];
        }

        $teamChatId = $this->teamMemberChatId();
        if ($teamChatId === null) {
            return ['notified' => false, 'message' => 'invalid team member phone'];
        }

        $isTestChat = $this->isTestChatId($chatId);
        $clientPhone = $this->normalizePhoneFromChatId($chatId) ?? 'غير متوفر';
        $clientName = trim((string) ($state['name'] ?? 'غير متوفر'));
        $clientCity = trim((string) ($state['city'] ?? 'غير متوفر'));

        $message = ($isTestChat ? "[TEST] " : '')
            . "This client wants an appointment meeting.\n\n"
            . "هذا العميل يريد موعد لقاء.\n"
            . "رجاءً تواصلوا معه لتحديد الموعد.\n\n"
            . "رقم العميل: {$clientPhone}\n"
            . "الاسم: {$clientName}\n"
            . "المدينة: {$clientCity}";

        $response = $this->sendWhatsAppMessage($teamChatId, $message);
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return ['notified' => false, 'message' => 'team notify failed HTTP ' . $status];
        }

        $state['team_notified'] = true;
        $state['team_notified_at'] = now()->toIso8601String();
        $this->saveLeadState($this->leadStatePath($chatId), $state);

        return [
            'notified' => true,
            'message' => $isTestChat
                ? 'team member notified (test chat advance intent)'
                : 'team member notified (advance intent)',
        ];
    }

    private function isAdvanceIntent(string $message): bool
    {
        $text = mb_strtolower(trim($message));
        if ($text === '') {
            return false;
        }

        $keywords = [
            'مهتم', 'حاب', 'حابب', 'خلينا', 'نكمل', 'اكمل', 'أكمل', 'بدي', 'اريد', 'أريد',
            'موافق', 'ديمو', 'موعد', 'لقاء', 'زيارة', 'اتصال', 'اتصلوا', 'تواصل', 'اتقدم', 'نتقدم',
            'احجز', 'حدد', 'جاهز', 'يلا', 'ايوا', 'نعم', 'متابعة', 'نستمر', 'نبلش',
            'advance', 'book', 'meeting', 'appointment', 'ready', 'interested', 'continue',
            'מעוניין', 'כן', 'יאללה', 'סבבה', 'לקבוע', 'פגישה',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePhoneFromChatId(string $chatId): ?string
    {
        $raw = trim((string) explode('@', $chatId)[0]);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        return $digits !== '' ? $digits : null;
    }

    private function isStaffChatId(string $chatId): bool
    {
        if (str_starts_with($chatId, self::STAFF_AGENT_CHAT_PREFIX)) {
            return false;
        }

        $local = $this->localPhoneFromChatId($chatId);
        if ($local === null) {
            return false;
        }

        return in_array($local, self::STAFF_NUMBERS, true);
    }

    private function isStaffAgentChatId(string $chatId): bool
    {
        if (str_starts_with($chatId, self::STAFF_AGENT_CHAT_PREFIX)) {
            return false;
        }

        $local = $this->localPhoneFromChatId($chatId);
        if ($local === null) {
            return false;
        }

        return in_array($local, self::CEO_NUMBERS, true)
            || in_array($local, self::COWORKER_NUMBERS, true);
    }

    private function isCeoChatId(string $chatId): bool
    {
        $local = $this->localPhoneFromChatId($chatId);
        if ($local === null) {
            return false;
        }

        return in_array($local, self::CEO_NUMBERS, true);
    }

    private function localPhoneFromChatId(string $chatId): ?string
    {
        $digits = $this->normalizePhoneFromChatId($chatId);
        if ($digits === null) {
            return null;
        }

        if (str_starts_with($digits, '972')) {
            $rest = substr($digits, 3);

            return $rest !== false ? '0' . $rest : $digits;
        }

        if (str_starts_with($digits, '0')) {
            return $digits;
        }

        return '0' . $digits;
    }

    private function staffAgentTestChatId(string $role): string
    {
        return self::STAFF_AGENT_CHAT_PREFIX . $role . '_' . session()->getId() . '@c.us';
    }

    private function extractLeadName(string $message): ?string
    {
        $text = trim($message);
        if ($text === '') {
            return null;
        }

        if (preg_match('/(?:اسمي|أنا|انا)\s+([^\n\r،,.!?]+)/u', $text, $m)) {
            $name = trim($m[1]);
            return $name !== '' ? $name : null;
        }

        if (preg_match('/^([A-Za-z\p{Arabic}\s]{2,40})$/u', $text)) {
            $maybeName = trim($text);
            $words = preg_split('/\s+/u', $maybeName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (mb_strlen($maybeName) <= 40 && count($words) <= 4) {
                return $maybeName;
            }
        }

        return null;
    }

    private function extractLeadCity(string $message): ?string
    {
        $text = trim($message);
        if ($text === '') {
            return null;
        }

        if (preg_match('/(?:من|بمدينة|مدينتي)\s+([^\n\r،,.!?]+)/u', $text, $m)) {
            $city = trim($m[1]);
            return $city !== '' ? $city : null;
        }

        return null;
    }

    private function leadStatePath(string $chatId): string
    {
        return storage_path('app/whatsapp-leads/' . md5($chatId) . '.json');
    }

    /**
     * @return array<string,mixed>
     */
    private function loadLeadState(string $chatId): array
    {
        $path = $this->leadStatePath($chatId);
        if (!File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function saveLeadState(string $path, array $state): void
    {
        $directory = dirname($path);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function teamMemberChatId(): ?string
    {
        $digits = preg_replace('/\D+/', '', self::TEAM_MEMBER_PHONE) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '972' . substr($digits, 1);
        } elseif (!str_starts_with($digits, '972')) {
            $digits = '972' . $digits;
        }

        return $digits . '@c.us';
    }

    private function isWebhookActive(): bool
    {
        return (bool) Cache::get(self::WEBHOOK_ACTIVE_CACHE_KEY, true);
    }

    private function webhookResumeAfterTimestamp(): ?int
    {
        $value = Cache::get(self::WEBHOOK_RESUME_AFTER_CACHE_KEY);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function getSystemPrompt(): string
    {
        $customPrompt = Cache::get(self::PROMPT_CACHE_KEY);
        if (is_string($customPrompt)) {
            $customPrompt = trim($customPrompt);
            if ($customPrompt !== '') {
                return $customPrompt;
            }
        }

        $customPromptFromDb = DB::table('whatsapp_settings')
            ->where('key', self::PROMPT_DB_KEY)
            ->value('value');

        if (is_string($customPromptFromDb)) {
            $customPromptFromDb = trim($customPromptFromDb);
            if ($customPromptFromDb !== '') {
                Cache::forever(self::PROMPT_CACHE_KEY, $customPromptFromDb);
                return $customPromptFromDb;
            }
        }

        return self::SYSTEM_PROMPT;
    }

    /**
     * @param  array{status:string,chat_id:?string,incoming:?string,reply:?string,green_api_status:mixed,reason:?string}  $event
     */
    private function pushWebhookEvent(array $event): void
    {
        $events = Cache::get(self::WEBHOOK_EVENTS_CACHE_KEY, []);
        if (!is_array($events)) {
            $events = [];
        }

        $events[] = [
            'time' => now()->toDateTimeString(),
            'status' => $event['status'],
            'chat_id' => $event['chat_id'],
            'incoming' => $event['incoming'],
            'reply' => $event['reply'],
            'green_api_status' => $event['green_api_status'],
            'reason' => $event['reason'],
        ];

        Cache::put(self::WEBHOOK_EVENTS_CACHE_KEY, array_slice($events, -100), now()->addDays(3));
    }
}

