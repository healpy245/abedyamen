<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Services\KamanAgents\KamanAgentsApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a Kaman Agents CRM lead when a WhatsApp sales conversation ends.
 */
class KamanWhatsappLeadStoreService
{
    public const UNKNOWN = 'unknown';

    public const REASON_CLOSED = 'closed';

    public const REASON_IDLE = 'idle';

    public function __construct(
        protected KamanAgentsApiClient $apiClient,
        protected AiChatbotSettingsService $settingsService,
    ) {}

    /**
     * @return array{stored: bool, skipped?: string, lead?: array<string, mixed>}
     */
    public function storeFromConversation(ChatbotConversation $conversation, string $reason = self::REASON_CLOSED): array
    {
        $conversation->refresh();
        $instance = $conversation->instance;
        if (! $instance instanceof ChatbotInstance || ! $instance->hasKamanWhatsappIntegration()) {
            return ['stored' => false, 'skipped' => 'not_kaman_whatsapp'];
        }

        if ($conversation->isTest()) {
            return ['stored' => false, 'skipped' => 'test_channel'];
        }

        $chatId = strtolower((string) ($conversation->external_chat_id ?? ''));
        if (str_contains($chatId, '@g.us')) {
            return ['stored' => false, 'skipped' => 'group_chat'];
        }

        if (! $this->apiClient->isConfigured()) {
            return ['stored' => false, 'skipped' => 'not_configured'];
        }

        if ($this->alreadyStored($conversation)) {
            return ['stored' => false, 'skipped' => 'already_stored'];
        }

        $userCount = $conversation->messages()->where('role', 'user')->count();
        if ($userCount < 1) {
            return ['stored' => false, 'skipped' => 'no_customer_messages'];
        }

        if ($reason === self::REASON_IDLE && ! $this->isIdle($conversation)) {
            return ['stored' => false, 'skipped' => 'still_active'];
        }

        $lock = Cache::lock('kaman-whatsapp-lead-'.$conversation->id, 45);
        if (! $lock->get()) {
            return ['stored' => false, 'skipped' => 'locked'];
        }

        try {
            $conversation->refresh();
            if ($this->alreadyStored($conversation)) {
                return ['stored' => false, 'skipped' => 'already_stored'];
            }

            $extracted = $this->extractLeadFields($conversation);
            $payload = [
                'fullName' => $extracted['fullName'],
                'restName' => $extracted['restName'],
                'source' => $extracted['source'],
                'city' => $extracted['city'],
                'phoneNumber' => $extracted['phoneNumber'],
                'notes' => $extracted['notes'],
            ];

            $lead = $this->apiClient->createLead($payload);
            $this->markStored($conversation, $payload, $lead, $reason);

            return ['stored' => true, 'lead' => $lead];
        } catch (Throwable $e) {
            Log::warning('Kaman WhatsApp lead store failed', [
                'conversation_id' => $conversation->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return ['stored' => false, 'skipped' => 'api_error'];
        } finally {
            optional($lock)->release();
        }
    }

    public function alreadyStored(ChatbotConversation $conversation): bool
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $lead = is_array($meta['kaman_agents_lead'] ?? null) ? $meta['kaman_agents_lead'] : [];

        return ! empty($lead['posted_at']);
    }

    public function isIdle(ChatbotConversation $conversation): bool
    {
        $minutes = max(1, (int) config('services.kaman_agents.lead_idle_minutes', 30));
        $lastAt = $conversation->last_message_at
            ?? $conversation->last_customer_message_at
            ?? $conversation->last_assistant_message_at;

        if ($lastAt === null) {
            return false;
        }

        return $lastAt->lte(now()->subMinutes($minutes));
    }

    /**
     * @return array{fullName: string, restName: string, source: string, city: string, phoneNumber: string, notes: string}
     */
    public function extractLeadFields(ChatbotConversation $conversation): array
    {
        $fallback = $this->heuristicFields($conversation);
        $ai = $this->extractWithOpenAi($conversation, $fallback);

        return [
            'fullName' => $this->fieldOrUnknown($ai['fullName'] ?? $fallback['fullName']),
            'restName' => $this->fieldOrUnknown($ai['restName'] ?? $fallback['restName']),
            'source' => $this->fieldOrUnknown($ai['source'] ?? $fallback['source']),
            'city' => $this->fieldOrUnknown($ai['city'] ?? $fallback['city']),
            'phoneNumber' => $this->fieldOrUnknown($ai['phoneNumber'] ?? $fallback['phoneNumber']),
            'notes' => $this->notesOrUnknown($ai['notes'] ?? $fallback['notes']),
        ];
    }

    /**
     * @return array{fullName: string, restName: string, source: string, city: string, phoneNumber: string, notes: string}
     */
    public function heuristicFields(ChatbotConversation $conversation): array
    {
        $customerText = $this->customerText($conversation);

        return [
            'fullName' => $this->nameFromContact($conversation),
            'restName' => $this->guessRestaurantName($customerText),
            'source' => $this->sourceValue(),
            'city' => $this->guessCity($customerText),
            'phoneNumber' => $this->phoneOrUnknown($conversation->contact_phone),
            'notes' => $this->fallbackNotes($conversation),
        ];
    }

    /**
     * @param  array{fullName: string, restName: string, source: string, city: string, phoneNumber: string, notes: string}  $fallback
     * @return array<string, string>
     */
    private function extractWithOpenAi(ChatbotConversation $conversation, array $fallback): array
    {
        $apiKey = (string) (config('services.openai.api_key') ?: env('OPENAI_API_KEY') ?: '');
        if ($apiKey === '') {
            return $fallback;
        }

        $transcript = $this->transcript($conversation);
        if (trim($transcript) === '') {
            return $fallback;
        }

        $settings = $this->settingsService->all();
        $model = (string) ($settings['chatbot_model'] ?? 'gpt-4o-mini');

        $http = Http::timeout(45)->withToken($apiKey)->acceptJson();
        if (! config('services.openai.verify_ssl', true)) {
            $http = $http->withoutVerifying();
        }

        try {
            $response = $http->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => implode("\n", [
                            'استخرج بيانات ليد من محادثة واتساب لمبيعات نظام كمان للمطاعم.',
                            'أرجع JSON فقط بالمفاتيح: fullName, restName, city, notes.',
                            'إذا الحقل غير معروف استخدم بالضبط unknown.',
                            'لا تختلق اسم أو مدينة أو مطعم إذا الزبون ما ذكرهم.',
                            'notes فقرة عربية قصيرة: ماذا طلب الزبون بوضوح، وما الذي يهتم فيه.',
                            'لا تكرر في notes الاسم أو المدينة أو الهاتف أو اسم المطعم. هذه حقول منفصلة.',
                            'اعتمد كلام الزبون فقط. إذا البوت فسّر جملة مشوّهة كمنتج، لا تنسخ تفسير البوت.',
                            'لا تنسب طلباً لم يقله الزبون بوضوح. الجملة غير المفهومة ليست طلباً.',
                            'الرسائل المعلّمة [رسالة صوتية] كلام الزبون. [رسالة صوتية غير واضحة] تفريغ غير مؤكد ولا يُحسب طلباً.',
                        ]),
                    ],
                    [
                        'role' => 'user',
                        'content' => "اسم واتساب: ".($conversation->contact_name ?: self::UNKNOWN)
                            ."\nهاتف: ".($conversation->contact_phone ?: self::UNKNOWN)
                            ."\n\nالمحادثة:\n".$transcript,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            Log::debug('Kaman WhatsApp lead extract OpenAI failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }

        if (! $response->successful()) {
            return $fallback;
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            return $fallback;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return $fallback;
        }

        return [
            'fullName' => $this->fieldOrUnknown($decoded['fullName'] ?? null) !== self::UNKNOWN
                ? $this->fieldOrUnknown($decoded['fullName'] ?? null)
                : $fallback['fullName'],
            'restName' => $this->fieldOrUnknown($decoded['restName'] ?? null),
            'source' => $fallback['source'],
            'city' => $this->fieldOrUnknown($decoded['city'] ?? null),
            'phoneNumber' => $fallback['phoneNumber'],
            'notes' => $this->notesOrUnknown($decoded['notes'] ?? null) !== self::UNKNOWN
                ? $this->notesOrUnknown($decoded['notes'] ?? null)
                : $fallback['notes'],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     * @param  array<string, mixed>  $lead
     */
    private function markStored(
        ChatbotConversation $conversation,
        array $payload,
        array $lead,
        string $reason,
    ): void {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['kaman_agents_lead'] = [
            'posted_at' => now()->toIso8601String(),
            'reason' => $reason,
            'lead_id' => $lead['id'] ?? $lead['leadId'] ?? $lead['lead_id'] ?? null,
            'fullName' => $payload['fullName'],
            'restName' => $payload['restName'],
            'city' => $payload['city'],
            'phoneNumber' => $payload['phoneNumber'],
        ];
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    private function nameFromContact(ChatbotConversation $conversation): string
    {
        $name = trim((string) ($conversation->contact_name ?? ''));
        if ($name === '' || preg_match('/\d{6,}/', $name) === 1) {
            return self::UNKNOWN;
        }

        return $name;
    }

    private function guessRestaurantName(string $customerText): string
    {
        if (preg_match('/مطعم\s+([^\n،,]{2,40})/u', $customerText, $match) === 1) {
            return $this->fieldOrUnknown($match[1]);
        }

        return self::UNKNOWN;
    }

    private function guessCity(string $customerText): string
    {
        if (preg_match('/(?:من|بـ|في|بال)\s*([^\n،?]{2,30})/u', $customerText, $match) === 1) {
            $city = trim((string) $match[1]);
            if (mb_strlen($city) <= 24 && ! preg_match('/مطعم|كاشير|نظام|سعر|ديمو/u', $city)) {
                return $this->fieldOrUnknown($city);
            }
        }

        return self::UNKNOWN;
    }

    private function sourceValue(): string
    {
        $source = trim((string) config('services.kaman_agents.lead_source', 'whatsapp'));

        return $source !== '' ? $source : 'whatsapp';
    }

    private function phoneOrUnknown(mixed $raw): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return self::UNKNOWN;
        }

        if (str_starts_with($digits, '972') && strlen($digits) >= 11) {
            return '0'.substr($digits, 3);
        }

        if (! str_starts_with($digits, '0') && strlen($digits) === 9) {
            return '0'.$digits;
        }

        return $digits;
    }

    private function fieldOrUnknown(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || strcasecmp($value, 'n/a') === 0 || strcasecmp($value, 'null') === 0) {
            return self::UNKNOWN;
        }

        return $value;
    }

    private function notesOrUnknown(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : self::UNKNOWN;
    }

    private function customerText(ChatbotConversation $conversation): string
    {
        return $conversation->messages()
            ->where('role', 'user')
            ->orderBy('id')
            ->pluck('message')
            ->implode("\n");
    }

    private function transcript(ChatbotConversation $conversation): string
    {
        $lines = [];
        $messages = $conversation->messages()
            ->orderBy('id')
            ->get(['role', 'message', 'metadata']);

        foreach ($messages as $message) {
            $text = $this->transcriptLine($message->role, (string) $message->message, $message->metadata);
            if ($text === '') {
                continue;
            }
            $who = $message->role === 'user' ? 'الزبون' : 'البوت';
            $lines[] = $who.': '.$text;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function transcriptLine(string $role, string $message, mixed $metadata): string
    {
        $text = trim($message);
        $meta = is_array($metadata) ? $metadata : [];
        if ($role !== 'user' || ($meta['whatsapp_voice'] ?? false) !== true) {
            return $text;
        }

        $spoken = trim((string) ($meta['transcript'] ?? ''));
        if (($meta['voice_unclear'] ?? false) === true || $spoken === '') {
            $guess = trim((string) ($meta['voice_best_guess'] ?? $spoken));

            return $guess !== ''
                ? '[رسالة صوتية غير واضحة] '.$guess
                : '[رسالة صوتية غير واضحة]';
        }

        return '[رسالة صوتية] '.$spoken;
    }

    private function fallbackNotes(ChatbotConversation $conversation): string
    {
        $clear = $this->clearCustomerText($conversation);
        $needs = [];
        if (preg_match('/سعر|تكلفة|شهري|299/u', $clear) === 1) {
            $needs[] = 'يسأل عن السعر';
        }
        if (preg_match('/ديمو|مندوب|demo/iu', $clear) === 1) {
            $needs[] = 'طلب تواصل مندوب أو ديمو';
        }
        if (preg_match('/كاشير|كوباه|كوبا|قائمة|منيو/u', $clear) === 1) {
            $needs[] = 'مهتم بنظام كاشير';
        }
        if (preg_match('/food\s*truck|فود\s*ترك|مطبخ/iu', $clear) === 1) {
            $needs[] = 'مهتم يفتح مطبخ أو فود ترك';
        }
        if (preg_match('/هات|haat/iu', $clear) === 1) {
            $needs[] = 'عنده اهتمام بتوصيل هات';
        }

        if ($needs === []) {
            return 'ما في طلب واضح غير متابعة المحادثة.';
        }

        return implode('، ', $needs).'.';
    }

    private function clearCustomerText(ChatbotConversation $conversation): string
    {
        $lines = [];
        $messages = $conversation->messages()
            ->where('role', 'user')
            ->orderBy('id')
            ->get(['message', 'metadata']);

        foreach ($messages as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            if (($meta['whatsapp_voice'] ?? false) === true && ($meta['voice_unclear'] ?? false) === true) {
                continue;
            }
            $line = $this->transcriptLine('user', (string) $message->message, $meta);
            $line = preg_replace('/^\[رسالة صوتية\]\s*/u', '', $line) ?? $line;
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        return implode("\n", $lines);
    }
}
