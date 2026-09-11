<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

use App\Jobs\SendCampaignFollowUpNudgeJob;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\Malan\MalanCampaign;
use App\Models\Malan\MalanCampaignContact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Campaign conversation insights: leads, no-response, rejected/negative, exports.
 */
class CampaignInsightService
{
    public const GROUP_LEADS = 'leads';

    public const GROUP_NO_RESPONSE = 'no_response';

    public const GROUP_REJECTED = 'rejected';

    public const GROUP_RESPONDED = 'responded';

    public const GROUP_CONTACTS = 'contacts';

    public const GROUP_MESSAGES = 'messages';

    /**
     * Strong rejection / negative signals in spoken Arabic / Hebrew / English.
     *
     * @var list<string>
     */
    private const NEGATIVE_PHRASES = [
        'ما بدي',
        'ما بدّي',
        'مش بدي',
        'مش بدّي',
        'ما بدنا',
        'مش مهتم',
        'مش مهتمة',
        'لا شكرا',
        'لا شكرًا',
        'لا شكراً',
        'بلاش',
        'كثير غالي',
        'غالي علي',
        'غالي عليي',
        'أسوأ',
        'اساء',
        'سيء',
        'سيئ',
        'زفت',
        'نصب',
        'كذب',
        'فاشل',
        'مش راضي',
        'مش عاجبني',
        'بيزك احسن',
        'بيزك أحسن',
        'هوت احسن',
        'هوت أحسن',
        'امسحني',
        'وقف رسايل',
        'وقف رسائل',
        'بطل تبعت',
        'ما بدكم',
        'لا مעוניין',
        'לא מעוניין',
        'לא רוצה',
        'תפסיקו',
        'יקר מדי',
        'not interested',
        'stop messaging',
        'unsubscribe',
        'too expensive',
        'scam',
    ];

    /**
     * @return array{
     *     contacts:int,
     *     messages:int,
     *     leads:int,
     *     responded:int,
     *     no_response:int,
     *     rejected:int,
     *     engaged:int,
     *     pending:int,
     *     status:string,
     *     cards:list<array{key:string,count:int,exportable:bool}>
     * }
     */
    public function summarize(MalanCampaign $campaign): array
    {
        $buckets = $this->classifyContacts($campaign);

        $contacts = $campaign->contacts()->count();
        $messages = $campaign->contacts()->whereNotNull('message_sent_at')->count();
        $leads = count($buckets[self::GROUP_LEADS]);
        $noResponse = count($buckets[self::GROUP_NO_RESPONSE]);
        $rejected = count($buckets[self::GROUP_REJECTED]);
        $engaged = count($buckets[self::GROUP_RESPONDED]);
        $responded = $leads + $rejected + $engaged;
        $pending = max(0, $contacts - $messages);

        return [
            'contacts' => $contacts,
            'messages' => $messages,
            'leads' => $leads,
            'responded' => $responded,
            'no_response' => $noResponse,
            'rejected' => $rejected,
            'engaged' => $engaged,
            'pending' => $pending,
            'status' => (string) $campaign->status,
            'cards' => [
                ['key' => self::GROUP_LEADS, 'count' => $leads, 'exportable' => true],
                ['key' => self::GROUP_NO_RESPONSE, 'count' => $noResponse, 'exportable' => true],
                ['key' => self::GROUP_REJECTED, 'count' => $rejected, 'exportable' => true],
                ['key' => self::GROUP_RESPONDED, 'count' => $engaged, 'exportable' => true],
                ['key' => self::GROUP_MESSAGES, 'count' => $messages, 'exportable' => true],
                ['key' => self::GROUP_CONTACTS, 'count' => $contacts, 'exportable' => true],
            ],
            'conversation_ids' => [
                self::GROUP_LEADS => $this->conversationIdsForGroup($campaign, self::GROUP_LEADS),
                self::GROUP_NO_RESPONSE => $this->conversationIdsForGroup($campaign, self::GROUP_NO_RESPONSE),
                self::GROUP_REJECTED => $this->conversationIdsForGroup($campaign, self::GROUP_REJECTED),
                self::GROUP_RESPONDED => $this->conversationIdsForGroup($campaign, self::GROUP_RESPONDED),
                'engaged' => $this->conversationIdsForGroup($campaign, self::GROUP_RESPONDED),
                self::GROUP_MESSAGES => $this->conversationIdsForGroup($campaign, self::GROUP_MESSAGES),
                self::GROUP_CONTACTS => $this->conversationIdsForGroup($campaign, self::GROUP_CONTACTS),
            ],
        ];
    }

    /**
     * @return Collection<int, MalanCampaignContact>
     */
    public function contactsForGroup(MalanCampaign $campaign, string $group): Collection
    {
        $group = strtolower(trim($group));
        if ($group === self::GROUP_CONTACTS) {
            return $campaign->contacts()->orderBy('id')->get();
        }
        if ($group === self::GROUP_MESSAGES) {
            return $campaign->contacts()->whereNotNull('message_sent_at')->orderBy('id')->get();
        }
        // Alias used by analytics UI cards.
        if ($group === 'engaged') {
            $group = self::GROUP_RESPONDED;
        }

        $buckets = $this->classifyContacts($campaign);
        $ids = $buckets[$group] ?? [];
        if ($ids === []) {
            return collect();
        }

        return $campaign->contacts()->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * Conversation IDs for an insight group.
     * Resolves via contact.conversation_id, then campaign chat id / phone fallbacks.
     *
     * @return list<int>
     */
    public function conversationIdsForGroup(MalanCampaign $campaign, string $group): array
    {
        $group = strtolower(trim($group));
        if ($group === '' || $group === 'all') {
            return [];
        }

        $contacts = $this->contactsForGroup($campaign, $group);
        if ($contacts->isEmpty()) {
            return [];
        }

        $ids = [];
        foreach ($contacts as $contact) {
            $cid = (int) ($contact->conversation_id ?? 0);
            if ($cid > 0) {
                $ids[$cid] = true;
            }
        }

        $unresolved = $contacts->filter(fn (MalanCampaignContact $c) => (int) ($c->conversation_id ?? 0) <= 0);
        if ($unresolved->isNotEmpty()) {
            $externalIds = [];
            $phones = [];
            foreach ($unresolved as $contact) {
                $chatId = trim((string) ($contact->chat_id ?? ''));
                if ($chatId !== '') {
                    $externalIds[] = $chatId;
                    $externalIds[] = 'campaign:'.$campaign->id.':'.$chatId;
                    if (! str_contains($chatId, '@') && preg_match('/^\d+$/', $chatId)) {
                        $externalIds[] = $chatId.'@c.us';
                        $externalIds[] = 'campaign:'.$campaign->id.':'.$chatId.'@c.us';
                    }
                }
                $phone = preg_replace('/\D+/', '', (string) ($contact->phone_normalized ?: $contact->phone)) ?: '';
                if ($phone !== '') {
                    $phones[] = $phone;
                    if (str_starts_with($phone, '0') && strlen($phone) === 10) {
                        $phones[] = '972'.substr($phone, 1);
                    }
                }
            }

            $externalIds = array_values(array_unique(array_filter($externalIds)));
            $phones = array_values(array_unique(array_filter($phones)));

            $query = ChatbotConversation::query()
                ->where('campaign_id', $campaign->id)
                ->where('channel', '!=', ChatbotConversation::CHANNEL_TEST);

            $query->where(function ($q) use ($externalIds, $phones): void {
                if ($externalIds !== []) {
                    $q->orWhereIn('external_chat_id', $externalIds);
                }
                if ($phones !== []) {
                    foreach ($phones as $phone) {
                        $q->orWhere('contact_phone', $phone)
                            ->orWhere('contact_phone', '0'.ltrim($phone, '0'))
                            ->orWhere('external_chat_id', 'like', '%'.$phone.'@%')
                            ->orWhere('external_chat_id', 'like', '%:'.$phone.'@%');
                    }
                }
            });

            if ($externalIds !== [] || $phones !== []) {
                foreach ($query->pluck('id') as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }

        // Keep only conversations that still belong to this campaign.
        if ($ids === []) {
            return [];
        }

        return ChatbotConversation::query()
            ->where('campaign_id', $campaign->id)
            ->where('channel', '!=', ChatbotConversation::CHANNEL_TEST)
            ->whereIn('id', array_keys($ids))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function isValidGroup(string $group): bool
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
        ], true);
    }

    public function exportExcel(MalanCampaign $campaign, string $group): StreamedResponse
    {
        $group = strtolower(trim($group));
        if ($group === 'engaged') {
            $group = self::GROUP_RESPONDED;
        }
        $allowed = [
            self::GROUP_LEADS,
            self::GROUP_NO_RESPONSE,
            self::GROUP_REJECTED,
            self::GROUP_RESPONDED,
            self::GROUP_CONTACTS,
            self::GROUP_MESSAGES,
        ];
        if (! in_array($group, $allowed, true)) {
            abort(404);
        }

        $rows = $this->contactsForGroup($campaign, $group);
        $groupLabel = $this->localizedGroupLabel($group);
        $locale = app()->getLocale();
        $filename = sprintf(
            'campaign-%d-%s-%s-%s.csv',
            $campaign->id,
            $group,
            $locale,
            now()->format('Ymd-His')
        );

        $headers = [
            __('chatbot.workspace.campaigns.export_col_name'),
            __('chatbot.workspace.campaigns.export_col_phone'),
            __('chatbot.workspace.campaigns.export_col_city'),
            __('chatbot.workspace.campaigns.export_col_status'),
            __('chatbot.workspace.campaigns.export_col_group'),
            __('chatbot.workspace.campaigns.export_col_responded_at'),
            __('chatbot.workspace.campaigns.export_col_lead_created_at'),
        ];

        return response()->streamDownload(function () use ($rows, $groupLabel, $headers): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            // UTF-8 BOM so Excel opens Arabic/Hebrew correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $contact) {
                fputcsv($out, [
                    (string) ($contact->name ?? ''),
                    (string) ($contact->phone_normalized ?: $contact->phone),
                    (string) ($contact->city ?? ''),
                    $this->localizedContactStatus((string) $contact->status),
                    $groupLabel,
                    optional($contact->responded_at)?->toDateTimeString() ?? '',
                    optional($contact->lead_created_at)?->toDateTimeString() ?? '',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function localizedGroupLabel(string $group): string
    {
        $key = match ($group) {
            self::GROUP_LEADS => 'stat_leads',
            self::GROUP_NO_RESPONSE => 'stat_no_response',
            self::GROUP_REJECTED => 'stat_rejected',
            self::GROUP_RESPONDED => 'stat_engaged',
            self::GROUP_MESSAGES => 'stat_messages',
            self::GROUP_CONTACTS => 'stat_contacts',
            default => null,
        };

        if ($key === null) {
            return $group;
        }

        return (string) __('chatbot.workspace.campaigns.'.$key);
    }

    private function localizedContactStatus(string $status): string
    {
        $key = 'chatbot.workspace.campaigns.contact_status_'.$status;
        $label = __($key);

        return $label === $key ? $status : (string) $label;
    }

    /**
     * @return array<string, list<int>>
     */
    private function classifyContacts(MalanCampaign $campaign): array
    {
        $buckets = [
            self::GROUP_LEADS => [],
            self::GROUP_NO_RESPONSE => [],
            self::GROUP_REJECTED => [],
            self::GROUP_RESPONDED => [],
        ];

        $contacts = $campaign->contacts()->get([
            'id',
            'conversation_id',
            'status',
            'message_sent_at',
            'responded_at',
            'lead_created_at',
        ]);

        $needsSentiment = [];
        foreach ($contacts as $contact) {
            if ($contact->lead_created_at !== null
                || $contact->status === MalanCampaignContact::STATUS_LEAD_CREATED) {
                $buckets[self::GROUP_LEADS][] = (int) $contact->id;
                continue;
            }

            if ($contact->message_sent_at === null) {
                continue;
            }

            if ($contact->responded_at === null) {
                $buckets[self::GROUP_NO_RESPONSE][] = (int) $contact->id;
                continue;
            }

            $conversationId = (int) ($contact->conversation_id ?? 0);
            if ($conversationId > 0) {
                $needsSentiment[$conversationId] = (int) $contact->id;
            } else {
                $buckets[self::GROUP_RESPONDED][] = (int) $contact->id;
            }
        }

        if ($needsSentiment !== []) {
            $negativeIds = $this->conversationIdsWithNegativeSignal(array_keys($needsSentiment));
            $silentIds = $this->conversationIdsWithoutAnswerAfterNudge(array_keys($needsSentiment));
            foreach ($needsSentiment as $conversationId => $contactId) {
                if (isset($negativeIds[$conversationId])) {
                    $buckets[self::GROUP_REJECTED][] = $contactId;
                } elseif (isset($silentIds[$conversationId])) {
                    $buckets[self::GROUP_NO_RESPONSE][] = $contactId;
                } else {
                    $buckets[self::GROUP_RESPONDED][] = $contactId;
                }
            }
        }

        return $buckets;
    }

    /**
     * Customers who never answered the closing question, not even after the
     * re-engagement nudge — they count as no-response.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, true>
     */
    private function conversationIdsWithoutAnswerAfterNudge(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $nudged = [];
        $conversations = ChatbotConversation::query()
            ->whereIn('id', $conversationIds)
            ->get(['id', 'metadata']);

        foreach ($conversations as $conversation) {
            $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
            $nudgeMessageId = (int) ($meta['campaign_followup_nudge_message_id'] ?? 0);
            $sentAt = $meta['campaign_followup_nudge_sent_at'] ?? null;
            if ($nudgeMessageId <= 0 || ! is_string($sentAt) || $sentAt === '') {
                continue;
            }

            try {
                $sent = Carbon::parse($sentAt);
            } catch (Throwable) {
                continue;
            }

            // Still inside the reply window — do not write them off yet.
            if ($sent->greaterThan(now()->subSeconds(SendCampaignFollowUpNudgeJob::DELAY_SECONDS))) {
                continue;
            }

            $nudged[(int) $conversation->id] = $nudgeMessageId;
        }

        if ($nudged === []) {
            return [];
        }

        $latestUserMessageIds = ChatbotMessage::query()
            ->whereIn('conversation_id', array_keys($nudged))
            ->where('role', 'user')
            ->selectRaw('conversation_id, MAX(id) as last_user_id')
            ->groupBy('conversation_id')
            ->pluck('last_user_id', 'conversation_id');

        $silent = [];
        foreach ($nudged as $conversationId => $nudgeMessageId) {
            if ((int) ($latestUserMessageIds[$conversationId] ?? 0) > $nudgeMessageId) {
                continue;
            }
            $silent[$conversationId] = true;
        }

        return $silent;
    }

    /**
     * @param  list<int>  $conversationIds
     * @return array<int, true>
     */
    private function conversationIdsWithNegativeSignal(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $messages = ChatbotMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->get(['conversation_id', 'message']);

        $byConversation = [];
        foreach ($messages as $message) {
            $cid = (int) $message->conversation_id;
            if (! isset($byConversation[$cid])) {
                $byConversation[$cid] = [];
            }
            if (count($byConversation[$cid]) >= 15) {
                continue;
            }
            $byConversation[$cid][] = (string) $message->message;
        }

        $negative = [];
        foreach ($byConversation as $cid => $texts) {
            $joined = mb_strtolower(implode("\n", array_slice($texts, 0, 12)));
            if ($this->looksNegative($joined)) {
                $negative[$cid] = true;
            }
        }

        return $negative;
    }

    private function looksNegative(string $text): bool
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
        if ($normalized === '') {
            return false;
        }

        foreach (self::NEGATIVE_PHRASES as $phrase) {
            if ($phrase !== '' && mb_stripos($normalized, mb_strtolower($phrase)) !== false) {
                return true;
            }
        }

        // Standalone short refusals across the burst.
        if (preg_match('/(?:^|\n)\s*(لا|لأ|لا+|no)\s*(?:\n|$)/u', $normalized)) {
            return true;
        }

        return false;
    }
}
