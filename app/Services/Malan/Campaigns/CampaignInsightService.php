<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

use App\Jobs\SendCampaignFollowUpNudgeJob;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\Malan\MalanCampaign;
use App\Models\Malan\MalanCampaignContact;
use App\Models\Malan\MalanCampaignGuardianNumber;
use App\Services\AiChatbot\AnalyticsDownloadService;
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

    public const GROUP_GUARDIANS = 'guardians';

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
        $this->refreshStaleInsights($campaign);

        $row = MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('COUNT(*) as contacts')
            ->selectRaw('SUM(CASE WHEN message_sent_at IS NOT NULL THEN 1 ELSE 0 END) as messages')
            ->selectRaw('SUM(CASE WHEN lead_created_at IS NOT NULL OR status = ? THEN 1 ELSE 0 END) as leads', [MalanCampaignContact::STATUS_LEAD_CREATED])
            ->selectRaw('SUM(CASE WHEN insight_group = ? THEN 1 ELSE 0 END) as no_response', [self::GROUP_NO_RESPONSE])
            ->selectRaw('SUM(CASE WHEN insight_group = ? THEN 1 ELSE 0 END) as rejected', [self::GROUP_REJECTED])
            ->selectRaw('SUM(CASE WHEN insight_group = ? THEN 1 ELSE 0 END) as engaged', [self::GROUP_RESPONDED])
            ->first();

        $contacts = (int) ($row->contacts ?? 0);
        $messages = (int) ($row->messages ?? 0);
        $leads = (int) ($row->leads ?? 0);
        $noResponse = (int) ($row->no_response ?? 0);
        $rejected = (int) ($row->rejected ?? 0);
        $engaged = (int) ($row->engaged ?? 0);
        $guardians = MalanCampaignGuardianNumber::query()->where('campaign_id', $campaign->id)->count();
        $responded = $leads + $rejected + $engaged;
        $pending = max(0, $contacts - $messages);

        $campaign->refresh();
        $settings = is_array($campaign->settings) ? $campaign->settings : [];
        $settings['insight_counts'] = [
            'contacts' => $contacts,
            'messages' => $messages,
            'leads' => $leads,
            'no_response' => $noResponse,
            'rejected' => $rejected,
            'engaged' => $engaged,
            'guardians' => $guardians,
            'responded' => $responded,
            'pending' => $pending,
            'at' => now()->toIso8601String(),
        ];
        $campaign->forceFill([
            'contacts_count' => $contacts,
            'leads_stored_count' => $leads,
            'settings' => $settings,
        ])->save();

        return [
            'contacts' => $contacts,
            'messages' => $messages,
            'leads' => $leads,
            'responded' => $responded,
            'no_response' => $noResponse,
            'rejected' => $rejected,
            'engaged' => $engaged,
            'guardians' => $guardians,
            'pending' => $pending,
            'status' => (string) $campaign->status,
            'cards' => [
                ['key' => self::GROUP_LEADS, 'count' => $leads, 'exportable' => true],
                ['key' => self::GROUP_GUARDIANS, 'count' => $guardians, 'exportable' => true],
                ['key' => self::GROUP_NO_RESPONSE, 'count' => $noResponse, 'exportable' => true],
                ['key' => self::GROUP_REJECTED, 'count' => $rejected, 'exportable' => true],
                ['key' => self::GROUP_RESPONDED, 'count' => $engaged, 'exportable' => true],
                ['key' => self::GROUP_MESSAGES, 'count' => $messages, 'exportable' => true],
                ['key' => self::GROUP_CONTACTS, 'count' => $contacts, 'exportable' => true],
            ],
            'conversation_ids' => [
                self::GROUP_LEADS => $this->conversationIdsForGroup($campaign, self::GROUP_LEADS),
                self::GROUP_GUARDIANS => $this->conversationIdsForGroup($campaign, self::GROUP_GUARDIANS),
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
        if ($group === self::GROUP_GUARDIANS) {
            return collect();
        }

        return $this->contactsQueryForGroup($campaign, $group)->orderBy('id')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<MalanCampaignContact>
     */
    public function contactsQueryForGroup(MalanCampaign $campaign, string $group): \Illuminate\Database\Eloquent\Builder
    {
        $group = strtolower(trim($group));
        if ($group === 'engaged') {
            $group = self::GROUP_RESPONDED;
        }

        $query = MalanCampaignContact::query()->where('campaign_id', $campaign->id);

        return match ($group) {
            self::GROUP_CONTACTS => $query,
            self::GROUP_MESSAGES => $query->whereNotNull('message_sent_at'),
            self::GROUP_LEADS => $query->where(function ($q): void {
                $q->whereNotNull('lead_created_at')
                    ->orWhere('status', MalanCampaignContact::STATUS_LEAD_CREATED)
                    ->orWhere('insight_group', self::GROUP_LEADS);
            }),
            self::GROUP_NO_RESPONSE => $query->where(function ($q): void {
                $q->where('insight_group', self::GROUP_NO_RESPONSE)
                    ->orWhere(function ($inner): void {
                        $inner->whereNull('insight_group')
                            ->whereNotNull('message_sent_at')
                            ->whereNull('responded_at')
                            ->whereNull('lead_created_at')
                            ->where('status', '!=', MalanCampaignContact::STATUS_LEAD_CREATED);
                    });
            }),
            self::GROUP_REJECTED => $query->where('insight_group', self::GROUP_REJECTED),
            self::GROUP_RESPONDED => $query->where('insight_group', self::GROUP_RESPONDED),
            default => $query->whereRaw('1 = 0'),
        };
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

        if ($group === self::GROUP_GUARDIANS) {
            return MalanCampaignGuardianNumber::query()
                ->where('campaign_id', $campaign->id)
                ->whereNotNull('conversation_id')
                ->orderByDesc('id')
                ->limit(200)
                ->pluck('conversation_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return ChatbotConversation::query()
            ->where('campaign_id', $campaign->id)
            ->where('channel', '!=', ChatbotConversation::CHANNEL_TEST)
            ->whereIn('id', $this->contactsQueryForGroup($campaign, $group)
                ->whereNotNull('conversation_id')
                ->select('conversation_id'))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(200)
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
            self::GROUP_GUARDIANS,
            self::GROUP_NO_RESPONSE,
            self::GROUP_REJECTED,
            self::GROUP_RESPONDED,
            self::GROUP_CONTACTS,
            self::GROUP_MESSAGES,
        ], true);
    }

    public function exportExcel(MalanCampaign $campaign, string $group): StreamedResponse
    {
        return app(AnalyticsDownloadService::class)->stream($this->exportBundle($campaign, $group));
    }

    /**
     * @return array{format:string,filename:string,ascii:string,sheet:string,widths:list<int>,phone_index:int,table:list<list<string>>}
     */
    public function exportBundle(MalanCampaign $campaign, string $group): array
    {
        $group = strtolower(trim($group));
        if ($group === 'engaged') {
            $group = self::GROUP_RESPONDED;
        }
        $allowed = [
            self::GROUP_LEADS,
            self::GROUP_GUARDIANS,
            self::GROUP_NO_RESPONSE,
            self::GROUP_REJECTED,
            self::GROUP_RESPONDED,
            self::GROUP_CONTACTS,
            self::GROUP_MESSAGES,
        ];
        if (! in_array($group, $allowed, true)) {
            abort(404);
        }

        if ($group === self::GROUP_GUARDIANS) {
            return $this->guardianBundle($campaign);
        }

        $this->refreshStaleInsights($campaign);
        $groupLabel = $this->localizedGroupLabel($group);
        $filename = sprintf(
            'campaign-%d-%s-%s-%s.csv',
            $campaign->id,
            $group,
            app()->getLocale(),
            now()->format('Ymd-His')
        );
        $table = [[
            __('chatbot.workspace.campaigns.export_col_name'),
            __('chatbot.workspace.campaigns.export_col_phone'),
            __('chatbot.workspace.campaigns.export_col_city'),
            __('chatbot.workspace.campaigns.export_col_status'),
            __('chatbot.workspace.campaigns.export_col_group'),
            __('chatbot.workspace.campaigns.export_col_responded_at'),
            __('chatbot.workspace.campaigns.export_col_lead_created_at'),
        ]];
        foreach ($this->contactsQueryForGroup($campaign, $group)->orderBy('id')->lazyById(500) as $contact) {
            $table[] = [
                (string) ($contact->name ?? ''),
                (string) ($contact->phone_normalized ?: $contact->phone),
                (string) ($contact->city ?? ''),
                $this->localizedContactStatus((string) $contact->status),
                $groupLabel,
                optional($contact->responded_at)?->toDateTimeString() ?? '',
                optional($contact->lead_created_at)?->toDateTimeString() ?? '',
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
     * @return array{format:string,filename:string,ascii:string,sheet:string,widths:list<int>,phone_index:int,table:list<list<string>>}
     */
    private function guardianBundle(MalanCampaign $campaign): array
    {
        $rows = MalanCampaignGuardianNumber::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get();
        $groupLabel = $this->localizedGroupLabel(self::GROUP_GUARDIANS);
        $filename = sprintf(
            'campaign-%d-guardians-%s-%s.csv',
            $campaign->id,
            app()->getLocale(),
            now()->format('Ymd-His')
        );
        $table = [[
            __('chatbot.workspace.campaigns.export_col_guardian_phone'),
            __('chatbot.workspace.campaigns.export_col_source_name'),
            __('chatbot.workspace.campaigns.export_col_source_phone'),
            __('chatbot.workspace.campaigns.export_col_group'),
        ]];
        foreach ($rows as $row) {
            $table[] = [
                (string) ($row->phone_normalized ?: $row->phone),
                (string) ($row->source_name ?? ''),
                (string) ($row->source_phone ?? ''),
                $groupLabel,
            ];
        }

        return [
            'format' => 'csv',
            'filename' => $filename,
            'ascii' => $filename,
            'sheet' => $groupLabel,
            'widths' => [],
            'phone_index' => 0,
            'table' => $table,
        ];
    }

    private function localizedGroupLabel(string $group): string
    {
        $key = match ($group) {
            self::GROUP_LEADS => 'stat_leads',
            self::GROUP_GUARDIANS => 'stat_guardians',
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
     * Persist insight_group for repliers whose chat changed, in small batches.
     * People who never replied are labeled with one SQL update.
     */
    public function refreshStaleInsights(MalanCampaign $campaign, int $limit = 400): void
    {
        MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->whereNull('insight_group')
            ->whereNotNull('message_sent_at')
            ->whereNull('responded_at')
            ->whereNull('lead_created_at')
            ->where('status', '!=', MalanCampaignContact::STATUS_LEAD_CREATED)
            ->update([
                'insight_group' => self::GROUP_NO_RESPONSE,
                'updated_at' => now(),
            ]);

        MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where(function ($q): void {
                $q->whereNotNull('lead_created_at')
                    ->orWhere('status', MalanCampaignContact::STATUS_LEAD_CREATED);
            })
            ->where(function ($q): void {
                $q->whereNull('insight_group')
                    ->orWhere('insight_group', '!=', self::GROUP_LEADS);
            })
            ->update([
                'insight_group' => self::GROUP_LEADS,
                'updated_at' => now(),
            ]);

        $replied = MalanCampaignContact::query()
            ->where('malan_campaign_contacts.campaign_id', $campaign->id)
            ->whereNotNull('malan_campaign_contacts.responded_at')
            ->whereNull('malan_campaign_contacts.lead_created_at')
            ->where('malan_campaign_contacts.status', '!=', MalanCampaignContact::STATUS_LEAD_CREATED);

        // Small reply sets stay exact on every open. Large sets only re-read
        // unlabeled rows, chats with a newer message, and open nudge windows.
        if ((clone $replied)->count() <= 80) {
            $stale = $replied->get();
        } else {
            $stale = (clone $replied)
                ->where(function ($q): void {
                    $q->whereNull('malan_campaign_contacts.insight_group')
                        ->orWhere(function ($inner): void {
                            $inner->where('malan_campaign_contacts.insight_group', self::GROUP_RESPONDED)
                                ->whereExists(function ($sub): void {
                                    $sub->selectRaw('1')
                                        ->from('ai_chatbot_conversations')
                                        ->whereColumn('ai_chatbot_conversations.id', 'malan_campaign_contacts.conversation_id')
                                        ->where('ai_chatbot_conversations.metadata', 'like', '%campaign_followup_nudge_message_id%');
                                });
                        })
                        ->orWhereExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from('ai_chatbot_messages')
                                ->whereColumn('ai_chatbot_messages.conversation_id', 'malan_campaign_contacts.conversation_id')
                                ->whereColumn('ai_chatbot_messages.updated_at', '>', 'malan_campaign_contacts.updated_at');
                        });
                })
                ->orderBy('malan_campaign_contacts.id')
                ->limit($limit)
                ->get();
        }

        foreach ($stale as $contact) {
            $this->syncContactInsight($contact);
        }
    }

    public function syncContactInsight(MalanCampaignContact $contact): void
    {
        $group = $this->groupForContact($contact);
        if ($contact->insight_group === $group) {
            $contact->touch();

            return;
        }

        $contact->forceFill(['insight_group' => $group])->save();
    }

    private function groupForContact(MalanCampaignContact $contact): ?string
    {
        if ($contact->lead_created_at !== null
            || $contact->status === MalanCampaignContact::STATUS_LEAD_CREATED) {
            return self::GROUP_LEADS;
        }

        if ($contact->message_sent_at === null) {
            return null;
        }

        if ($contact->responded_at === null) {
            return self::GROUP_NO_RESPONSE;
        }

        $conversationId = (int) ($contact->conversation_id ?? 0);
        if ($conversationId <= 0) {
            return self::GROUP_RESPONDED;
        }

        if ($this->conversationIdsWithNegativeSignal([$conversationId]) !== []) {
            return self::GROUP_REJECTED;
        }

        if ($this->conversationIdsWithoutAnswerAfterNudge([$conversationId]) !== []) {
            return self::GROUP_NO_RESPONSE;
        }

        return self::GROUP_RESPONDED;
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
    public function conversationIdsWithNegativeSignal(array $conversationIds): array
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
