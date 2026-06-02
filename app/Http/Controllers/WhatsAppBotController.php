<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
    private const TEAM_MEMBER_PHONE = '0584680001';
    private const CHAT_CACHE_PREFIX = 'whatsapp_lead_chat_';
    private const WEBHOOK_EVENTS_CACHE_KEY = 'whatsapp_webhook_events';
    private const WEBHOOK_ACTIVE_CACHE_KEY = 'whatsapp_webhook_active';
    private const PROMPT_CACHE_KEY = 'whatsapp_chatbot_prompt';
    private const PROMPT_DB_KEY = 'system_prompt';
    private const PROCESSED_MESSAGE_CACHE_PREFIX = 'whatsapp_processed_message_';
    private const CHAT_HISTORY_LIMIT = 40;
    private const MAX_INCOMING_MESSAGE_AGE_SECONDS = 300;
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a WhatsApp sales and support agent for a restaurant system called:
KAMAN POS
Your job is to talk with NEW WhatsApp leads who contacted us after seeing an advertisement.
All leads are Arabic-speaking restaurant owners located in Israel.
You must always reply in Arabic.

SPECIAL LANGUAGE STYLE:
Use local mixed Arabic/Hebrew words naturally when appropriate, but not too much.
Examples: بسيدر, حفيلاه, سوخن, معريخت, هدرخاه, سليكا

ROLE:
Friendly WhatsApp sales rep.
Goals:
1. Welcome the lead.
2. At the beginning of conversation, collect lead name and city quickly.
3. Understand their restaurant.
4. Explain the system.
5. Answer questions.
6. Move lead toward demo/meeting.

COMMUNICATION STYLE:
- Short WhatsApp-style messages.
- Friendly natural tone.
- Avoid long paragraphs.
- Ask questions to keep conversation moving.

PRODUCT:
KAMAN POS هي معريخت متكاملة لإدارة المطاعم بطريقة ذكية وسهلة.
النظام بساعد المطاعم يديروا: الطلبات، الكاشير، المطبخ، المبيعات، وإدارة المطعم بشكل كامل.

FEATURES:
- نظام كاشير سحابي
- نظام للنادل
- شاشة مطبخ
- طباعة عربي/عبري/إنجليزي
- تكامل Haat
- منيو رقمي
- CRM
- تقرير Z
- إرسال فواتير SMSض
- دعم سليكا و4G

PRICING:
599 شيكل بالشهر (غير شامل الضريبة).
حفيلاه إعداد أولية: 2000 شيكل تشمل تركيب + تدخيل مينيو + هدرخاه.
عرض: أول 5 مطاعم بكل بلد عربي يحصلوا على الحفيلاه الأولية بحينام.
العرض ساري حتى 30/4/2026.

FIRST-MESSAGE STYLE:
مرحبا 👋
معك فريق KAMAN لنظام إدارة المطاعم.
هاي معريخت كاملة لإدارة المطعم
من الكاشير والطلبات وحتى متابعة المبيعات.
قبل ما نكمل، ممكن اسمك والمدينة اللي فيها المطعم؟

DISCOVERY QUESTIONS:
شو اسمك الكريم؟
بأي مدينة موجود مطعمك؟
شو نوع المطعم عندك؟
كم فرع عندك؟
هل عندك توصيل؟
هل تستخدم معريخت حالياً؟
اي معريخت بتستعمل؟

IMPORTANT LEAD CAPTURE RULE:
- In the beginning, ask for name and city naturally.
- If one of them is missing, ask a short follow-up question to complete it.
- After getting both, continue the normal sales conversation.

COMPETITOR HANDLING (VERY IMPORTANT):
When the client answers the question "اي معريخت بتستعمل؟" with one of these systems,
recognize them in Arabic/English/Hebrew variants:

1) Becom / بيكوم / פיקוו
2) Leenkobot / لينكوبوت / לינקובוט
3) T Bon / تي بون / טי בון
4) Aviv kobot / افيف / אביב
5) Tabit / تابيت / טאביט

How to respond:
- For Becom, Leenkobot, T Bon, Aviv:
  Explain briefly and clearly that these are mostly local-hosted old-style systems,
  with limited flexibility, weak cloud capabilities, and not suitable for modern AI use cases.
  Mention that cloud data continuity is critical today for analytics, automation, and future AI features.
  Also explain naturally with real numbers that many sellers in this market buy a cheap device
  (around 1500 ILS), install very low-value software (sometimes almost nothing in value),
  then resell the package at very high prices (around 6000-7000+ ILS).
  Make the numbers visible clearly to the client in short WhatsApp lines.
  Keep this explanation short and WhatsApp-friendly (no long paragraphs).

- For Tabit:
  Explain that KAMAN provides the same core idea and workflow,
  but with more features, AI capabilities, and significantly lower pricing.

Tone constraints for competitor handling:
- Be confident but professional.
- Do not insult users personally.
- Do not use legal accusations; frame it as market reality and cost/value comparison.
- If this is an early/mid conversation message (not final intent), DO NOT jump directly to demo.
  First ask: what exact problems they face in their current POS.
  Then continue conversation by solving each problem with relevant KAMAN capabilities.
- Move to demo/meeting only after handling their problems or when user shows clear readiness.

PROBLEM-SOLVING FLOW:
- After competitor comparison, ask:
  "شو أكثر مشاكل بتواجهك حالياً بالمعريخت اللي عندك؟"
- For every problem the client mentions, answer with a concrete solution KAMAN can provide.
- Keep answers practical, short, and connected to the client's actual pain.

COMPLEX REQUEST / CUSTOM FEATURE RULE:
- If client asks for a complicated workflow and you do not have a direct ready answer,
  tell them one key advantage:
  we are the programmers who built the system from 0 to 100,
  and we have around 22 programmers.
- Explain that we can develop custom solutions and program what they need.
- Say this confidently but briefly, then continue discovery.

WHEN ASKED ABOUT PRICE:
Explain exactly: 599 monthly + 2000 setup, with current free setup offer for first 5 restaurants.

MOVE FORWARD:
Invite to demo/meeting via سوخن visit naturally.

IMPORTANT:
- Do NOT invent features.
- Use only provided info.
- Keep responses natural and short.
- Always try to move toward demo.

APPOINTMENT POLICY (STRICT):
- Never assign or confirm appointment timing by yourself.
- When the client wants to advance, tell them:
  one of our team members will contact them soon to set the appointment.
- Mention team availability:
  Sunday to Thursday (الأحد - الخميس).

OUTPUT RULE:
Return plain text only, no JSON, no markdown.
PROMPT;

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
            $this->pushWebhookEvent([
                'status' => 'deactivated',
                'chat_id' => $this->extractChatId($request),
                'incoming' => $this->extractIncomingMessage($request),
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

        if ($messageId !== null) {
            $this->markMessageAsProcessed($messageId);
        }

        try {
            $reply = $this->generateReply($chatId, $incomingMessage);
            $this->emitTypingIndicator($chatId);
            $this->applyHumanLikeDelay($reply);
            $sendResult = $this->sendWhatsAppMessage($chatId, $reply);
            $leadSync = $this->syncLeadIfReady($chatId, $incomingMessage);
            $teamNotify = $this->notifyTeamIfClientWantsToAdvance($chatId, $incomingMessage);
        } catch (\Throwable $e) {
            $fallbackReply = $this->fallbackReply($chatId, $incomingMessage);
            $sendResult = null;
            $leadSync = $this->syncLeadIfReady($chatId, $incomingMessage);
            $teamNotify = $this->notifyTeamIfClientWantsToAdvance($chatId, $incomingMessage);

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
                . "السعر هو 599 شيكل بالشهر\n"
                . "غير شامل الضريبة.\n\n"
                . "وفي حفيلاه إعداد أولية 2000 شيكل\n"
                . "لكن حالياً أول 5 مطاعم بكل بلد عربي\n"
                . "هاي الحفيلاه بحينام.\n\n"
                . "عندك حالياً توصيل بالمطعم؟";
        }

        if ($normalized !== '' && (str_contains($normalized, 'ديمو') || str_contains($normalized, 'زيارة') || str_contains($normalized, 'سوخن'))) {
            return "ممتاز 🌟\n"
                . "ممكن نرتب زيارة سوخن على المطعم\n"
                . "عشان تشوف ديمو عملي للمعريخت.\n\n"
                . "مناسبك خلال هالأسبوع؟";
        }

        if ($hasPreviousTurns && $incoming !== '') {
            return "ممتاز، فهمت عليك 🙌\n"
                . "ذكرتلي: {$incoming}\n\n"
                . "كم فرع عندك؟ وهل عندكم توصيل؟";
        }

        return "مرحبا 👋\n"
            . "معك فريق KAMAN لنظام إدارة المطاعم.\n\n"
            . "هاي معريخت كاملة لإدارة المطعم\n"
            . "من الكاشير والطلبات وحتى متابعة المبيعات.\n\n"
            . "قبل ما نكمل، ممكن اسمك والمدينة اللي فيها المطعم؟";
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
        $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);

        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI API key is missing from runtime configuration.');
        }

        $http = Http::withToken($apiKey)
            ->timeout((int) config('openai.request_timeout', 30))
            ->acceptJson()
            ->baseUrl(rtrim($baseUrl, '/'));

        if (config('openai.organization')) {
            $http = $http->withHeaders(['OpenAI-Organization' => config('openai.organization')]);
        }
        if (config('openai.project')) {
            $http = $http->withHeaders(['OpenAI-Project' => config('openai.project')]);
        }

        if (!$sslVerify) {
            $http = $http->withOptions(['verify' => false]);
        }

        $response = $http->post('/chat/completions', [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.6,
            'max_tokens' => $options['max_tokens'] ?? 450,
        ]);

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
     * @return array{notified:bool,message:string}
     */
    private function notifyTeamIfClientWantsToAdvance(string $chatId, string $incomingMessage): array
    {
        $state = $this->loadLeadState($chatId);
        if (($state['team_notified'] ?? false) === true) {
            return ['notified' => true, 'message' => 'already notified'];
        }

        $hasAdvanceIntent = $this->isAdvanceIntent($incomingMessage);
        $historyCount = count($this->loadChatHistory($chatId));
        $fallbackConversationTrigger = (($state['submitted'] ?? false) === true) && $historyCount >= 4;

        if (!$hasAdvanceIntent && !$fallbackConversationTrigger) {
            return ['notified' => false, 'message' => 'no advance trigger'];
        }

        $teamChatId = $this->teamMemberChatId();
        if ($teamChatId === null) {
            return ['notified' => false, 'message' => 'invalid team member phone'];
        }

        $clientPhone = $this->normalizePhoneFromChatId($chatId) ?? 'غير متوفر';
        $clientName = trim((string) ($state['name'] ?? 'غير متوفر'));
        $clientCity = trim((string) ($state['city'] ?? 'غير متوفر'));

        $message = "تنبيه متابعة عميل جديد ⚡\n\n"
            . "العميل حاب يتقدم ويحتاج تنسيق موعد.\n"
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

        return ['notified' => true, 'message' => $hasAdvanceIntent ? 'team member notified (advance intent)' : 'team member notified (conversation trigger)'];
    }

    private function isAdvanceIntent(string $message): bool
    {
        $text = mb_strtolower(trim($message));
        if ($text === '') {
            return false;
        }

        $keywords = [
            'مهتم', 'حاب', 'خلينا', 'نكمل', 'بدي', 'اريد', 'موافق', 'تمام',
            'ديمو', 'موعد', 'زيارة', 'اتصال', 'اتصلوا', 'تواصل', 'اتقدم', 'نتقدم',
            'احجز', 'حدد', 'يعني خلص', 'جاهز', 'يلا', 'ايوا',
            'advance', 'book', 'meeting', 'appointment', 'yes', 'ok', 'ready',
            'interested', 'lets go',
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
        }

        return $digits . '@c.us';
    }

    private function isWebhookActive(): bool
    {
        return (bool) Cache::get(self::WEBHOOK_ACTIVE_CACHE_KEY, true);
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

