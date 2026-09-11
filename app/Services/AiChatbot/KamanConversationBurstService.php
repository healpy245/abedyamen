<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Versioned inbound bursts for the Kaman POS WhatsApp sales bot.
 * Newer customer lines invalidate a reply that has not been sent yet.
 */
final class KamanConversationBurstService
{
    public const STATE_WAITING_FOR_CUSTOMER = 'waiting_for_customer';

    public const STATE_GENERATING = 'generating';

    public const STATE_PENDING_SEND = 'pending_send';

    public const STATE_SENT = 'sent';

    public const STATE_STALE = 'stale';

    public const STATE_CANCELLED = 'cancelled';

    /**
     * @return array{version:int,wait_seconds:int,burst_started_at:string}
     */
    public function registerInboundMessage(ChatbotConversation $conversation): array
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $version = ((int) ($meta['kaman_conversation_version'] ?? 0)) + 1;

        $burstStartedRaw = $meta['kaman_burst_started_at'] ?? null;
        $burstOpen = ! empty($meta['kaman_burst_open']);
        $burstStartedAt = ($burstOpen && is_string($burstStartedRaw) && $burstStartedRaw !== '')
            ? Carbon::parse($burstStartedRaw)
            : now();

        $waitSeconds = KamanListeningWindow::secondsUntilProcess($burstStartedAt);
        $listenUntil = now()->addSeconds($waitSeconds);

        $meta['kaman_conversation_version'] = $version;
        $meta['kaman_burst_open'] = true;
        $meta['kaman_burst_started_at'] = $burstStartedAt->toIso8601String();
        $meta['kaman_last_customer_message_at'] = now()->toIso8601String();
        $meta['kaman_listen_until'] = $listenUntil->toIso8601String();
        $meta['kaman_pending_process_version'] = $version;
        $meta['kaman_response_state'] = self::STATE_WAITING_FOR_CUSTOMER;

        $conversation->forceFill(['metadata' => $meta])->save();

        $this->invalidatePendingOutbound($conversation, $version, 'newer_customer_message');

        return [
            'version' => $version,
            'wait_seconds' => $waitSeconds,
            'burst_started_at' => $burstStartedAt->toIso8601String(),
        ];
    }

    public function currentVersion(ChatbotConversation $conversation): int
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];

        return (int) ($meta['kaman_conversation_version'] ?? 0);
    }

    public function invalidatePendingOutbound(
        ChatbotConversation $conversation,
        int $newVersion,
        string $reason,
    ): int {
        $cancelled = 0;
        $pending = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where(function ($q): void {
                $q->where('delivery_status', 'pending')
                    ->orWhereIn('delivery_status', ['queued', 'sending']);
            })
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($pending as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            $state = (string) ($meta['kaman_response_state'] ?? $meta['kaman_delivery'] ?? '');
            if (in_array($state, [self::STATE_SENT, self::STATE_CANCELLED, self::STATE_STALE], true)) {
                continue;
            }
            if (($meta['kaman_delivery'] ?? null) === 'sent' && ! empty($meta['greenapi_id_message'])) {
                continue;
            }

            $message->forceFill([
                'delivery_status' => 'failed',
                'metadata' => array_merge($meta, [
                    'kaman_delivery' => self::STATE_CANCELLED,
                    'kaman_response_state' => self::STATE_STALE,
                    'kaman_cancel_reason' => $reason,
                    'kaman_cancelled_by_version' => $newVersion,
                ]),
            ])->save();
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * Unprocessed customer messages since the last successfully sent assistant reply.
     *
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
            if (! empty($meta['kaman_burst_processed'])) {
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
                    'kaman_burst_processed' => true,
                    'kaman_burst_processed_version' => $version,
                    'kaman_burst_processed_at' => now()->toIso8601String(),
                ]),
            ])->save();
        }
    }

    public function closeBurst(ChatbotConversation $conversation, int $version): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['kaman_burst_open'] = false;
        $meta['kaman_last_processed_version'] = $version;
        $meta['kaman_response_state'] = self::STATE_SENT;
        unset($meta['kaman_listen_until'], $meta['kaman_pending_process_version']);
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    public function setResponseState(ChatbotConversation $conversation, string $state): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['kaman_response_state'] = $state;
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    /**
     * @param  list<ChatbotMessage>  $burst
     */
    public function buildBurstEphemeralPrompt(array $burst): string
    {
        $lines = [];
        foreach ($burst as $i => $message) {
            $n = $i + 1;
            $line = trim((string) $message->message);
            $meta = is_array($message->metadata) ? $message->metadata : [];
            if (($meta['whatsapp_voice'] ?? false) === true) {
                $line = '[Voice note transcribed] '.$line;
            }
            if (($meta['whatsapp_sticker'] ?? false) === true || str_contains($line, 'ستيكر واتساب')) {
                $line = '[WhatsApp sticker] '.$line;
            }
            $lines[] = "{$n}. ".$line;
        }
        $joined = implode("\n", $lines);
        $count = count($burst);

        return <<<TEXT
[KAMAN_BURST]
The customer just sent {$count} WhatsApp message(s) in one burst (split bubbles / new lines). Read ALL of them together before replying:

{$joined}

Instructions for this turn:
- Treat the burst as ONE thought. Do NOT answer only the last line.
- If later lines correct or add to earlier ones, the latest meaning wins (e.g. they said delivery then "قعدة بالمحل").
- If they asked انتو קופות / كوبوت / كوباه (and it is NOT a visa/מכשיר question), answer with THAT word (معريخت كوبوت), not فصحى كاشير.
- Never repeat "يا هلا فيك" after the first greeting. Never repeat "تمام عمي [name]" after you already used it.
- If name and city are already in the chat, never ask for الاسم والبلد again.
- Do not re-ask a question you already asked that they have not answered yet. Just answer what they asked, then stop. No follow-up question.
- Do not end with "إذا بدك تفاصيل خبرني" or "تفاصيل إضافية" or "أنا جاهزة" or any repeated CTA.
- تسلمي / تسلم / شكرا / يعطيك العافية / thanks / תודה = the chat is over. Reply only "العفو" (or "בכיף" in Hebrew) and STOP. No sochen, no extra offer.
- A WhatsApp sticker is a social signal, not a product photo. Positive stickers (smile, thumbs up, heart, ok) after you explained something = they got it. Reply "تمام" or "يسلمو" and STOP. Never "ممتاز إذا حابب أشرح" / "تفاصيل إضافية" / "أنا جاهزة".
- "اا" / "علوا" is acknowledgment — do not re-explain.
- Do not pitch HAAT DaaS on "بس توصيل" alone.
- Do NOT mention visa / مخشير فيزا / 4G / מכשיר unless the customer asked about it in THIS burst. Ad codes like {{SWE001}} are NOT a visa question — greet only (name + city), no product dump.
- Do NOT mention كفر قاسم as Kaman's origin unless they asked من وين كمان / من وين الشركة / وين اصلكم / מאיפה קמאן. If they DID ask: reply "كفر قاسم." A customer saying they are from كفر قاسم is their city, not an origin question.
- If they DID ask about a cellular visa device: ON TOPIC. They sell a 4G מכשיר; it keeps working if power or internet is down. Never use the off-topic refuse for that question.
- Off-topic refuse only for things with zero restaurant/POS relation (politics, news, unrelated jobs).
- Related restaurant/POS question you don't know 100%: be honest, the sochen for their area will explain. If they already agreed to נתאם a sochen visit: "لما يقعد معك السوخن". If not: offer to have the sochen talk and schedule a session.
- Reply once, short, Palestinian WhatsApp dialect. One hook that matches the whole burst.
- Do not repeat a feature you already explained in this chat.
- No JSON, no markdown, no lead score.
[/KAMAN_BURST]
TEXT;
    }

    public function burstStartedAt(ChatbotConversation $conversation): ?CarbonInterface
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $raw = $meta['kaman_burst_started_at'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return Carbon::parse($raw);
    }
}
