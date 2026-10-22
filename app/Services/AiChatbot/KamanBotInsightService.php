<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Services\Malan\Campaigns\CampaignInsightService;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Live analytics for the Kaman WhatsApp bot inbox.
 * Same groups as campaign chats: leads, no reply, rejection, engaged, messages, contacts.
 */
class KamanBotInsightService
{
    public const GROUP_LEADS = 'leads';

    public const GROUP_NO_RESPONSE = 'no_response';

    public const GROUP_REJECTED = 'rejected';

    public const GROUP_RESPONDED = 'responded';

    public const GROUP_CONTACTS = 'contacts';

    public const GROUP_MESSAGES = 'messages';

    public function __construct(
        protected CampaignInsightService $campaignInsights,
    ) {}

    /**
     * @return array{
     *     contacts:int,
     *     messages:int,
     *     leads:int,
     *     responded:int,
     *     no_response:int,
     *     rejected:int,
     *     engaged:int,
     *     conversation_ids:array<string, list<int>>
     * }
     */
    public function summarize(ChatbotInstance $instance): array
    {
        $buckets = $this->classify($instance);

        $contacts = count($buckets[self::GROUP_CONTACTS]);
        $messages = count($buckets[self::GROUP_MESSAGES]);
        $leads = count($buckets[self::GROUP_LEADS]);
        $noResponse = count($buckets[self::GROUP_NO_RESPONSE]);
        $rejected = count($buckets[self::GROUP_REJECTED]);
        $engaged = count($buckets[self::GROUP_RESPONDED]);

        return [
            'contacts' => $contacts,
            'messages' => $messages,
            'leads' => $leads,
            'responded' => $leads + $rejected + $engaged,
            'no_response' => $noResponse,
            'rejected' => $rejected,
            'engaged' => $engaged,
            'conversation_ids' => [
                self::GROUP_LEADS => $buckets[self::GROUP_LEADS],
                self::GROUP_NO_RESPONSE => $buckets[self::GROUP_NO_RESPONSE],
                self::GROUP_REJECTED => $buckets[self::GROUP_REJECTED],
                self::GROUP_RESPONDED => $buckets[self::GROUP_RESPONDED],
                'engaged' => $buckets[self::GROUP_RESPONDED],
                self::GROUP_MESSAGES => $buckets[self::GROUP_MESSAGES],
                self::GROUP_CONTACTS => $buckets[self::GROUP_CONTACTS],
            ],
        ];
    }

    /**
     * @return list<int>
     */
    public function conversationIdsForGroup(ChatbotInstance $instance, string $group, ?string $lineId = null, bool $includeUntagged = false): array
    {
        $group = $this->normalizeGroup($group);
        if ($group === null) {
            return [];
        }

        $buckets = $this->classify($instance);

        return $buckets[$group] ?? [];
    }

    public function isValidGroup(string $group): bool
    {
        return $this->normalizeGroup($group) !== null;
    }

    public function exportCsv(ChatbotInstance $instance, string $group): StreamedResponse
    {
        return app(AnalyticsDownloadService::class)->stream($this->exportBundle($instance, $group));
    }

    /**
     * @return array{format:string,filename:string,ascii:string,sheet:string,widths:list<int>,phone_index:int,table:list<list<string>>}
     */
    public function exportBundle(ChatbotInstance $instance, string $group): array
    {
        $group = $this->normalizeGroup($group);
        if ($group === null) {
            abort(404);
        }

        $ids = $this->conversationIdsForGroup($instance, $group);
        $rows = $ids === []
            ? collect()
            : ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get(['id', 'contact_name', 'contact_phone', 'external_chat_id', 'title']);

        $groupLabel = $this->localizedGroupLabel($group);
        $filename = sprintf(
            'kaman-bot-%s-%s-%s.csv',
            $group,
            app()->getLocale(),
            now()->format('Ymd-His')
        );
        $table = [[
            __('chatbot.workspace.analytics.export_name'),
            __('chatbot.workspace.analytics.export_phone'),
            __('chatbot.workspace.analytics.export_group'),
        ]];
        foreach ($rows as $conversation) {
            $table[] = [
                $conversation->displayName(),
                $this->phoneOf($conversation),
                $groupLabel,
            ];
        }

        return [
            'format' => 'csv',
            'filename' => $filename,
            'ascii' => $filename,
            'sheet' => $groupLabel,
            'widths' => [],
            'phone_index' => 1,
            'table' => $table,
        ];
    }

    /**
     * Customer-facing Kaman chats, excluding WhatsApp groups and campaign threads.
     *
     * @return Collection<int, ChatbotConversation>
     */
    private function conversations(ChatbotInstance $instance): Collection
    {
        return ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->where(function ($query): void {
                $query->whereNull('external_chat_id')
                    ->orWhere('external_chat_id', 'not like', '%@g.us');
            })
            ->orderBy('id')
            ->get([
                'id',
                'metadata',
                'contact_name',
                'contact_phone',
                'external_chat_id',
                'title',
                'last_customer_message_at',
                'last_assistant_message_at',
            ]);
    }

    /**
     * @return array<string, list<int>>
     */
    private function classify(ChatbotInstance $instance): array
    {
        $buckets = [
            self::GROUP_LEADS => [],
            self::GROUP_NO_RESPONSE => [],
            self::GROUP_REJECTED => [],
            self::GROUP_RESPONDED => [],
            self::GROUP_MESSAGES => [],
            self::GROUP_CONTACTS => [],
        ];

        $conversations = $this->conversations($instance);
        if ($conversations->isEmpty()) {
            return $buckets;
        }

        $ids = $conversations->map(fn (ChatbotConversation $c) => (int) $c->id)->all();
        $withUser = $this->conversationIdsWithRole($ids, 'user');
        $withAssistant = $this->conversationIdsWithRole($ids, 'assistant');

        $needsSentiment = [];
        foreach ($conversations as $conversation) {
            $id = (int) $conversation->id;
            $buckets[self::GROUP_CONTACTS][] = $id;
            if (isset($withAssistant[$id]) || $conversation->last_assistant_message_at !== null) {
                $buckets[self::GROUP_MESSAGES][] = $id;
            }

            if ($this->hasStoredLead($conversation)) {
                $buckets[self::GROUP_LEADS][] = $id;
                continue;
            }

            $customerWrote = isset($withUser[$id]) || $conversation->last_customer_message_at !== null;
            if (! $customerWrote) {
                if (isset($withAssistant[$id]) || $conversation->last_assistant_message_at !== null) {
                    $buckets[self::GROUP_NO_RESPONSE][] = $id;
                }
                continue;
            }

            $needsSentiment[] = $id;
        }

        if ($needsSentiment !== []) {
            $negative = $this->campaignInsights->conversationIdsWithNegativeSignal($needsSentiment);
            $remaining = [];
            foreach ($needsSentiment as $id) {
                if (isset($negative[$id])) {
                    $buckets[self::GROUP_REJECTED][] = $id;
                } else {
                    $remaining[] = $id;
                }
            }
            $positiveLong = $this->positiveLongLeadIds($remaining);
            foreach ($remaining as $id) {
                if (isset($positiveLong[$id])) {
                    $buckets[self::GROUP_LEADS][] = $id;
                } else {
                    $buckets[self::GROUP_RESPONDED][] = $id;
                }
            }
        }

        return $buckets;
    }

    /**
     * @param  list<int>  $conversationIds
     * @return array<int, true>
     */
    private function conversationIdsWithRole(array $conversationIds, string $role): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($conversationIds, 1000) as $chunk) {
            $ids = ChatbotMessage::query()
                ->whereIn('conversation_id', $chunk)
                ->where('role', $role)
                ->distinct()
                ->pluck('conversation_id');
            foreach ($ids as $id) {
                $found[(int) $id] = true;
            }
        }

        return $found;
    }

    /**
     * A long, positive customer chat counts as a stored lead.
     * This only reads message text. It does not save a lead or send a message.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, true>
     */
    private function positiveLongLeadIds(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $byConversation = [];
        foreach (array_chunk($conversationIds, 500) as $chunk) {
            $messages = ChatbotMessage::query()
                ->whereIn('conversation_id', $chunk)
                ->where('role', 'user')
                ->orderBy('id')
                ->get(['conversation_id', 'message']);
            foreach ($messages as $message) {
                $byConversation[(int) $message->conversation_id][] = (string) $message->message;
            }
        }

        $leads = [];
        foreach ($byConversation as $conversationId => $texts) {
            if ($this->isPositiveLongLead($texts)) {
                $leads[$conversationId] = true;
            }
        }

        return $leads;
    }

    /**
     * @param  list<string>  $customerMessages
     */
    private function isPositiveLongLead(array $customerMessages): bool
    {
        $substantive = [];
        foreach ($customerMessages as $text) {
            if (! $this->isFillerCustomerMessage($text)) {
                $substantive[] = trim($text);
            }
        }

        if ($substantive === []) {
            return false;
        }

        $blob = implode("\n", $substantive);
        if ($this->isJobSeeker($blob) || $this->isExistingCustomerSupport($blob)) {
            return false;
        }

        $chars = 0;
        foreach ($substantive as $text) {
            $chars += mb_strlen($text);
        }
        $count = count($substantive);
        $strong = $this->matches($blob, 'مندوب|جلس|يشرح|نتصل|نتصلك|اتصلي|اتصل|demo|05\d{8}');
        $long = $count >= 6
            || ($count >= 4 && $chars >= 100)
            || ($count >= 4 && $strong);
        if (! $long) {
            return false;
        }

        return $count >= 6
            || $strong
            || $this->matches($blob, 'مطعم|مطاعم|كاشير|كوبا|كوباه|كوبونات|منيو|سعر|تكلف|اشتراك|توصيل|تيك\s*اوي|تيك اوي|مخبز|برجر|شاورما|شورما|بيتزا|كافيه|مقهى|قهوه|نظام|طابع|قعدة|food truck|קופה|תפריט|משלוח|מחיר|اهتمام|بفتح|أفتح|افتح');
    }

    private function isFillerCustomerMessage(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return true;
        }

        $lower = mb_strtolower($trimmed);
        foreach ([
            'مزيد من المعلومات حول هذا',
            'can i get more info',
            'מידע נוסף על זה',
            '{{swe001}}',
        ] as $snippet) {
            if (str_contains($lower, $snippet)) {
                return true;
            }
        }

        if (str_contains($trimmed, 'ستيكر') || str_contains($trimmed, 'صورة/ملف')) {
            return true;
        }

        $letters = preg_replace('/[^\p{L}\p{N}]+/u', '', $trimmed) ?? '';

        return mb_strlen($letters) < 2;
    }

    private function isJobSeeker(string $text): bool
    {
        return $this->matches($text, 'بدور على شغل|شغيلة|وظيفة');
    }

    private function isExistingCustomerSupport(string $text): bool
    {
        return $this->matches($text, 'مشتركين')
            && $this->matches($text, 'بطبع|مش شغال|ناقص|مشكلة|عطل');
    }

    private function matches(string $text, string $pattern): bool
    {
        return preg_match('/'.$pattern.'/iu', $text) === 1;
    }

    private function hasStoredLead(ChatbotConversation $conversation): bool
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $lead = is_array($meta['kaman_agents_lead'] ?? null) ? $meta['kaman_agents_lead'] : [];

        return ! empty($lead['posted_at']);
    }

    private function normalizeGroup(string $group): ?string
    {
        $group = strtolower(trim($group));
        if ($group === 'engaged') {
            $group = self::GROUP_RESPONDED;
        }

        return in_array($group, [
            self::GROUP_LEADS,
            self::GROUP_NO_RESPONSE,
            self::GROUP_REJECTED,
            self::GROUP_RESPONDED,
            self::GROUP_CONTACTS,
            self::GROUP_MESSAGES,
        ], true) ? $group : null;
    }

    private function localizedGroupLabel(string $group): string
    {
        $key = match ($group) {
            self::GROUP_LEADS => 'leads',
            self::GROUP_NO_RESPONSE => 'no_response',
            self::GROUP_REJECTED => 'rejected',
            self::GROUP_RESPONDED => 'engaged',
            self::GROUP_MESSAGES => 'messages',
            self::GROUP_CONTACTS => 'contacts',
            default => null,
        };

        if ($key === null) {
            return $group;
        }

        return (string) __('chatbot.workspace.analytics.'.$key);
    }

    private function phoneOf(ChatbotConversation $conversation): string
    {
        $phone = preg_replace('/\D+/', '', (string) ($conversation->contact_phone ?? '')) ?: '';
        if ($phone !== '') {
            return $phone;
        }

        if (preg_match('/(\d{8,})/', (string) ($conversation->external_chat_id ?? ''), $match) === 1) {
            return $match[1];
        }

        return '';
    }
}
