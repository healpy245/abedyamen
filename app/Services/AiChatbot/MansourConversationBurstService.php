<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Versioned inbound bursts for شيماء.
 * A newer customer line drops a reply that has not been sent, so it can be regenerated.
 */
final class MansourConversationBurstService
{
    /**
     * @return array{version:int,wait_seconds:int}
     */
    public function registerInboundMessage(ChatbotConversation $conversation): array
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $version = ((int) ($meta['mansour_conversation_version'] ?? 0)) + 1;

        $burstStartedRaw = $meta['mansour_burst_started_at'] ?? null;
        $burstOpen = ! empty($meta['mansour_burst_open']);
        $burstStartedAt = ($burstOpen && is_string($burstStartedRaw) && $burstStartedRaw !== '')
            ? Carbon::parse($burstStartedRaw)
            : now();

        $waitSeconds = MansourListeningWindow::secondsUntilProcess($burstStartedAt);

        $meta['mansour_conversation_version'] = $version;
        $meta['mansour_burst_open'] = true;
        $meta['mansour_burst_started_at'] = $burstStartedAt->toIso8601String();
        $meta['mansour_pending_process_version'] = $version;
        $conversation->forceFill(['metadata' => $meta])->save();

        $this->discardUnsentReply($conversation);

        return [
            'version' => $version,
            'wait_seconds' => $waitSeconds,
        ];
    }

    public function currentVersion(ChatbotConversation $conversation): int
    {
        $conversation->refresh();
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];

        return (int) ($meta['mansour_conversation_version'] ?? 0);
    }

    public function discardUnsentReply(ChatbotConversation $conversation): void
    {
        ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('delivery_status', 'pending')
            ->delete();
    }

    /**
     * @return list<ChatbotMessage>
     */
    public function collectUnprocessedBurst(ChatbotConversation $conversation): array
    {
        $lastSentAssistantId = (int) ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('delivery_status', 'sent')
            ->orderByDesc('id')
            ->value('id');

        $query = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->orderBy('id');

        if ($lastSentAssistantId > 0) {
            $query->where('id', '>', $lastSentAssistantId);
        }

        $burst = [];
        foreach ($query->get() as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            if (! empty($meta['mansour_burst_processed'])) {
                continue;
            }
            $burst[] = $message;
        }

        return $burst;
    }

    /**
     * @param  list<ChatbotMessage>  $burst
     */
    public function markBurstProcessed(array $burst, int $version): void
    {
        foreach ($burst as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            $message->forceFill([
                'metadata' => array_merge($meta, [
                    'mansour_burst_processed' => true,
                    'mansour_burst_processed_version' => $version,
                ]),
            ])->save();
        }
    }

    public function closeBurst(ChatbotConversation $conversation, int $version): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['mansour_burst_open'] = false;
        $meta['mansour_last_processed_version'] = $version;
        unset($meta['mansour_pending_process_version']);
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    /**
     * @param  list<ChatbotMessage>  $burst
     */
    public function burstPrompt(array $burst): ?string
    {
        if (count($burst) < 2) {
            return null;
        }

        $lines = [];
        foreach ($burst as $i => $message) {
            $lines[] = ($i + 1).'. '.trim((string) $message->message);
        }

        return "هالرسائل وصلت متفرقة على واتساب، واحدة ورا الثانية. اقرئيها كلها كفكرة واحدة وجاوبي مرة واحدة بالشامي. لا تردي على آخر سطر بس:\n".implode("\n", $lines);
    }

    public function burstStartedAt(ChatbotConversation $conversation): ?CarbonInterface
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $raw = $meta['mansour_burst_started_at'] ?? null;

        return is_string($raw) && $raw !== '' ? Carbon::parse($raw) : null;
    }
}
