<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Support\FarzUi;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Checklist read of بوت التطبيقات chats. Reads stored messages only.
 */
class AppsInsightService
{
    /** @var array<int, array{buckets: array<string, list<int>>, funnel: array<string, mixed>}> */
    private array $snapshots = [];

    /** @var array<int, bool> */
    private array $verifiedAccounts = [];

    public function __construct(protected MansourInsightService $sheets) {}

    /**
     * @return array<string, int|list<int>>
     */
    public function summarize(ChatbotInstance $instance, ?string $lineId = null, bool $includeUntagged = false): array
    {
        $buckets = $this->classify($instance, $lineId, $includeUntagged);
        $downloaded = count($buckets[AppsPrompt::GROUP_DOWNLOADED]);
        $waiting = count($buckets[AppsPrompt::GROUP_WAITING]);
        $noReply = count($buckets[AppsPrompt::GROUP_NO_REPLY]);
        $follow = count($buckets[AppsPrompt::GROUP_FOLLOW_UP] ?? []);
        $total = $downloaded + $noReply;

        return [
            'downloaded' => $downloaded,
            'waiting_account' => $waiting,
            'no_reply' => $noReply,
            'follow_up' => $follow,
            'replied' => $downloaded,
            'downloaded_pct' => $this->percent($downloaded, $total),
            'waiting_account_pct' => $this->percent($waiting, $total),
            'no_reply_pct' => $this->percent($noReply, $total),
            'follow_up_pct' => $this->percent($follow, $total),
            'conversation_ids' => $buckets,
        ];
    }

    /**
     * Counts behind the أصواتكم / الموحدة cards.
     *
     * @return array{
     *     contacted:int,
     *     aswatkum: array{downloaded:int,interacted:int,waiting_registration:int,waiting_delegate:int},
     *     muwahada: array{downloaded:int,interacted:int,waiting_registration:int,waiting_delegate:int}
     * }
     */
    public function funnel(ChatbotInstance $instance): array
    {
        return $this->snapshot($instance)['funnel'];
    }

    public function isValidGroup(string $group): bool
    {
        return array_key_exists($group, FarzUi::appGroups());
    }

    /**
     * @return list<int>
     */
    public function conversationIdsForGroup(ChatbotInstance $instance, string $group, ?string $lineId = null, bool $includeUntagged = false): array
    {
        if (! $this->isValidGroup($group)) {
            return [];
        }

        return $this->classify($instance, $lineId, $includeUntagged)[$group] ?? [];
    }

    public function exportCsv(ChatbotInstance $instance, string $group, ?string $lineId = null, bool $includeUntagged = false): StreamedResponse
    {
        return app(AnalyticsDownloadService::class)->stream($this->exportBundle($instance, $group, $lineId, $includeUntagged));
    }

    /**
     * @return array{format:string,filename:string,ascii:string,sheet:string,widths:list<int>,phone_index:int,table:list<list<string>>}
     */
    public function exportBundle(ChatbotInstance $instance, string $group, ?string $lineId = null, bool $includeUntagged = false): array
    {
        if (! $this->isValidGroup($group)) {
            abort(404);
        }

        $ids = $this->conversationIdsForGroup($instance, $group, $lineId, $includeUntagged);
        $rows = $ids === []
            ? collect()
            : ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get(['id', 'contact_name', 'contact_phone', 'external_chat_id']);
        $notes = $this->notes($ids);
        $identities = $this->identities($ids);
        $label = FarzUi::appGroups()[$group]['label'];
        $table = [['الاسم', 'الهاتف', 'رقم الهوية', 'المجموعة', 'الملاحظة']];
        foreach ($rows as $conversation) {
            $phone = preg_replace('/\D+/', '', (string) ($conversation->contact_phone ?: $conversation->external_chat_id)) ?: '';
            $table[] = [
                $conversation->displayName(),
                $phone,
                $identities[(int) $conversation->id] ?? '',
                $label,
                $notes[(int) $conversation->id] ?? '',
            ];
        }

        return [
            'format' => 'xlsx',
            'filename' => trim((string) $instance->name).' - '.$label.' - '.now()->format('Y-m-d').'.xlsx',
            'ascii' => 'apps-'.$group.'-'.now()->format('Y-m-d').'.xlsx',
            'sheet' => mb_substr($label, 0, 31),
            'widths' => [32, 18, 16, 22, 70],
            'phone_index' => 1,
            'table' => $table,
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    private function classify(ChatbotInstance $instance, ?string $lineId = null, bool $includeUntagged = false): array
    {
        return $this->snapshot($instance, $lineId, $includeUntagged)['buckets'];
    }

    /**
     * One pass over the chats: export buckets and the dashboard funnel.
     *
     * @return array{buckets: array<string, list<int>>, funnel: array<string, mixed>}
     */
    private function snapshot(ChatbotInstance $instance, ?string $lineId = null, bool $includeUntagged = false): array
    {
        $key = $instance->id.'|'.trim((string) $lineId).'|'.($includeUntagged ? '1' : '0');

        return $this->snapshots[$key] ??= $this->buildSnapshot($instance, $lineId, $includeUntagged);
    }

    /**
     * @return array{buckets: array<string, list<int>>, funnel: array<string, mixed>}
     */
    private function buildSnapshot(ChatbotInstance $instance, ?string $lineId = null, bool $includeUntagged = false): array
    {
        $buckets = array_fill_keys(array_keys(FarzUi::appGroups()), []);
        $funnel = [
            'contacted' => 0,
            'aswatkum' => ['downloaded' => 0, 'interacted' => 0, 'waiting_registration' => 0, 'waiting_delegate' => 0],
            'muwahada' => ['downloaded' => 0, 'interacted' => 0, 'waiting_registration' => 0, 'waiting_delegate' => 0],
        ];
        $ids = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->when(trim((string) $lineId) !== '', fn ($query) => $query->onLine((string) $lineId, $includeUntagged))
            ->where(function ($query): void {
                $query->whereNull('external_chat_id')
                    ->orWhere('external_chat_id', 'not like', '%@g.us');
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $turnsById = $this->turns($ids);
        $verified = $this->verified($ids);
        foreach ($ids as $id) {
            $turns = $turnsById[$id] ?? [];
            $context = ['account' => (bool) ($verified[$id] ?? false)];
            $result = AppsPrompt::result($turns, $context);
            foreach ($result['groups'] ?? array_filter([$result['group'] ?? null]) as $group) {
                if (isset($buckets[$group])) {
                    $buckets[$group][] = $id;
                }
            }

            $profile = AppsPrompt::profile($turns, $context);
            if (! $profile['contacted']) {
                continue;
            }
            $funnel['contacted']++;
            if ($profile['aswatkum_downloaded']) {
                $funnel['aswatkum']['downloaded']++;
            }
            if ($profile['aswatkum_interacted']) {
                $funnel['aswatkum']['interacted']++;
            }
            if ($profile['waiting_registration']) {
                $funnel['aswatkum']['waiting_registration']++;
            }
            if ($profile['waiting_delegate']) {
                $funnel['aswatkum']['waiting_delegate']++;
            }
            if ($profile['muwahada_downloaded']) {
                $funnel['muwahada']['downloaded']++;
            }
            if ($profile['muwahada_interacted']) {
                $funnel['muwahada']['interacted']++;
            }
            if ($profile['muwahada_waiting_registration']) {
                $funnel['muwahada']['waiting_registration']++;
            }
            if ($profile['muwahada_waiting_delegate']) {
                $funnel['muwahada']['waiting_delegate']++;
            }
        }

        return ['buckets' => $buckets, 'funnel' => $funnel];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function identities(array $ids): array
    {
        $identities = [];
        foreach ($this->turns($ids) as $id => $turns) {
            $identities[$id] = AppsPrompt::identityNumber($turns);
        }

        return $identities;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function notes(array $ids): array
    {
        $notes = [];
        foreach ($this->turns($ids) as $id => $turns) {
            $notes[$id] = AppsPrompt::result($turns, [
                'account' => (bool) ($this->verified($ids)[$id] ?? false),
            ])['note'];
        }

        return $notes;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, list<array{role:string,message:string}>>
     */
    private function turns(array $ids): array
    {
        $turns = [];
        if ($ids === []) {
            return $turns;
        }
        $messages = ChatbotMessage::query()
            ->whereIn('conversation_id', $ids)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['conversation_id', 'role', 'message']);
        foreach ($messages as $message) {
            $turns[(int) $message->conversation_id][] = [
                'role' => (string) $message->role,
                'message' => (string) $message->message,
            ];
        }

        return $turns;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, bool>
     */
    private function verified(array $ids): array
    {
        $missing = array_values(array_filter(
            $ids,
            fn (int $id): bool => ! array_key_exists($id, $this->verifiedAccounts),
        ));
        if ($missing !== []) {
            foreach ($missing as $id) {
                $this->verifiedAccounts[$id] = false;
            }
            $rows = ChatbotConversation::query()->whereIn('id', $missing)->get(['id', 'metadata']);
            foreach ($rows as $row) {
                $this->verifiedAccounts[(int) $row->id] = (bool) data_get($row->metadata, 'apps_verified.account');
            }
        }

        return $this->verifiedAccounts;
    }

    private function percent(int $count, int $total): int
    {
        return $total > 0 ? (int) round(($count / $total) * 100) : 0;
    }
}
