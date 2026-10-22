<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Support\FarzUi;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reads the stance ديما stored on each chat. Never sends WhatsApp.
 */
class DimaInsightService
{
    public function __construct(
        protected MansourInsightService $sheets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(ChatbotInstance $instance, ?string $lineId = null, bool $includeUntagged = false): array
    {
        $buckets = $this->classify($instance, $lineId, $includeUntagged);
        $counts = [];
        foreach (FarzUi::dimaGroups() as $group => $meta) {
            $counts[$group] = count($buckets[$group] ?? []);
        }
        $noReply = $counts[DimaPrompt::NO_REPLY] ?? 0;
        $replied = array_sum($counts) - $noReply;
        $total = $replied + $noReply;
        $result = [
            'replied' => $replied,
            'conversation_ids' => $buckets,
        ];
        foreach ($counts as $group => $count) {
            $result[$group] = $count;
            $result[$group.'_pct'] = $total > 0 ? (int) round(($count / $total) * 100) : 0;
        }

        return $result;
    }

    public function isValidGroup(string $group): bool
    {
        return array_key_exists($group, FarzUi::dimaGroups());
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
                ->get(['id', 'contact_name', 'contact_phone', 'external_chat_id', 'title', 'metadata']);
        $label = FarzUi::dimaGroups()[$group]['label'];
        $table = [['الاسم', 'الهاتف', 'المجموعة', 'الملاحظة']];
        foreach ($rows as $conversation) {
            $read = DimaPrompt::read($conversation);
            $table[] = [
                $conversation->displayName(),
                $this->phoneOf($conversation),
                $label,
                $group === DimaPrompt::NO_REPLY ? 'أرسلت الرسالة ولم يرد' : $read['note'],
            ];
        }

        $topic = FarzUi::dimaGroups()[$group]['topic'];

        return [
            'format' => 'xlsx',
            'filename' => 'ديما - '.$topic.' - '.now()->format('Y-m-d').'.xlsx',
            'ascii' => 'dima-'.$group.'-'.now()->format('Y-m-d').'.xlsx',
            'sheet' => mb_substr(str_replace(['\\', '/', '*', '?', ':', '[', ']'], ' ', $topic), 0, 31),
            'widths' => [36, 24, 28, 88],
            'phone_index' => 1,
            'table' => $table,
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    private function classify(ChatbotInstance $instance, ?string $lineId, bool $includeUntagged): array
    {
        $buckets = [];
        foreach (array_keys(FarzUi::dimaGroups()) as $group) {
            $buckets[$group] = [];
        }

        $conversations = $this->conversations($instance, $lineId, $includeUntagged);
        if ($conversations->isEmpty()) {
            return $buckets;
        }

        $spoken = [];
        $opened = [];
        ChatbotMessage::query()
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->whereIn('role', ['user', 'assistant'])
            ->get(['conversation_id', 'role'])
            ->each(function (ChatbotMessage $message) use (&$spoken, &$opened): void {
                $id = (int) $message->conversation_id;
                if ((string) $message->role === 'user') {
                    $spoken[$id] = true;
                } else {
                    $opened[$id] = true;
                }
            });

        foreach ($conversations as $conversation) {
            $id = (int) $conversation->id;
            if (! isset($spoken[$id])) {
                if (isset($opened[$id])) {
                    $buckets[DimaPrompt::NO_REPLY][] = $id;
                }

                continue;
            }

            $stored = is_array($conversation->metadata) ? ($conversation->metadata['dima'] ?? null) : null;
            $stance = is_array($stored)
                ? DimaPrompt::normalizeStance((string) ($stored['stance'] ?? ''))
                : DimaPrompt::OPEN;
            $buckets[$stance][] = $id;
        }

        return $buckets;
    }

    /**
     * @return Collection<int, ChatbotConversation>
     */
    private function conversations(ChatbotInstance $instance, ?string $lineId, bool $includeUntagged): Collection
    {
        return ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->when(trim((string) $lineId) !== '', fn ($query) => $query->onLine((string) $lineId, $includeUntagged))
            ->where(function ($query): void {
                $query->whereNull('external_chat_id')
                    ->orWhere('external_chat_id', 'not like', '%@g.us');
            })
            ->orderBy('id')
            ->get(['id', 'metadata', 'contact_name', 'contact_phone', 'external_chat_id', 'title']);
    }

    private function phoneOf(ChatbotConversation $conversation): string
    {
        $phone = preg_replace('/\D+/', '', (string) ($conversation->contact_phone ?? '')) ?: '';
        if ($phone !== '') {
            return $phone;
        }

        return preg_replace('/\D+/', '', (string) $conversation->external_chat_id) ?: '';
    }
}
