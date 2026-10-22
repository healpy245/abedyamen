<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use Illuminate\Support\Facades\Cache;

/**
 * One short follow-up when a person answered, then went quiet before a sort.
 */
class MansourConversationRevival
{
    public const WAIT_SECONDS = 180;

    public const MAX_NUDGES = 2;

    public function arm(int $conversationId, int $assistantMessageId): void
    {
        $conversation = ChatbotConversation::query()->find($conversationId);
        if ($conversation === null || ! $conversation->allowsAutomaticReply()) {
            return;
        }

        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        if ((int) ($meta['mansour_revival_count'] ?? 0) >= self::MAX_NUDGES) {
            return;
        }

        if (! $this->stillUnsorted($conversation)) {
            return;
        }

        Cache::put($this->key($conversationId), $assistantMessageId, now()->addMinutes(10));
    }

    public function waitAndSend(int $conversationId): void
    {
        $assistantId = Cache::pull($this->key($conversationId));
        if (! is_numeric($assistantId)) {
            return;
        }

        if (! app()->runningUnitTests() && self::WAIT_SECONDS > 0) {
            @set_time_limit(self::WAIT_SECONDS + 90);
            sleep(self::WAIT_SECONDS);
        }

        $this->send($conversationId, (int) $assistantId);
    }

    public function send(int $conversationId, int $afterAssistantId): void
    {
        $conversation = ChatbotConversation::query()->find($conversationId);
        $instance = $conversation?->instance;
        if ($conversation === null || $instance === null || ! $instance->hasMansourIntegration()) {
            return;
        }
        if ($instance->outboundHeld() || ! $instance->isBotGloballyActive() || ! $conversation->allowsAutomaticReply()) {
            return;
        }
        if (ChatbotMessage::query()->where('conversation_id', $conversationId)->where('id', '>', $afterAssistantId)->exists()) {
            return;
        }
        if (! $this->stillUnsorted($conversation)) {
            return;
        }

        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $count = (int) ($meta['mansour_revival_count'] ?? 0);
        if ($count >= self::MAX_NUDGES) {
            return;
        }

        $chatId = trim((string) $conversation->external_chat_id);
        $lines = app(MansourLines::class);
        if ($chatId === '' || ! $lines->canDeliver($instance, $conversation)) {
            return;
        }

        if ($instance->isDimaBot() && ! DimaEngine::allowsOutbound()) {
            return;
        }

        $waitingOn = ChatbotMessage::query()->find($afterAssistantId);
        $reply = match (true) {
            $instance->isDimaBot() => DimaEngine::nudge($this->turns($conversation), $conversation->contact_name, true),
            $instance->isAppsBot() => AppsPrompt::nudge($this->turns($conversation)),
            default => MansourPrompt::silenceNudge(is_string($waitingOn?->message) ? $waitingOn->message : null),
        };
        if (trim($reply) === '') {
            return;
        }
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
            'message' => $reply,
            'delivery_status' => 'pending',
        ]);

        $typing = $instance->isAppsBot() ? AppsPrompt::TYPING_MS : 1000;
        $sendResult = $lines->send($instance, $conversation, $chatId, $reply, $typing);
        $ok = ($sendResult['status'] ?? 0) >= 200 && ($sendResult['status'] ?? 0) < 300;
        $assistant->forceFill([
            'delivery_status' => $ok ? 'sent' : 'failed',
        ])->save();
        if (! $ok) {
            return;
        }

        $fresh = $conversation->fresh() ?? $conversation;
        $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
        $meta['mansour_revival_count'] = $count + 1;
        $fresh->forceFill(['metadata' => $meta])->save();
    }

    private function stillUnsorted(ChatbotConversation $conversation): bool
    {
        $turns = [];
        $messages = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['role', 'message']);
        $userLines = 0;
        foreach ($messages as $message) {
            $text = trim((string) $message->message);
            if ($text === '') {
                continue;
            }
            if ((string) $message->role === 'user') {
                $userLines++;
            }
            $turns[] = ['role' => (string) $message->role, 'message' => $text];
        }

        if ($userLines < 1) {
            return false;
        }

        if ($conversation->instance?->isInviteBot()) {
            return false;
        }

        if ($conversation->instance?->isDimaBot()) {
            return ! DimaEngine::isClosed($conversation);
        }

        if ($conversation->instance?->isAppsBot()) {
            return AppsPrompt::nudge($turns) !== '';
        }

        return MansourPrompt::dialogueResult($turns)['group'] === null;
    }

    /**
     * @return list<array{role:string,message:string}>
     */
    private function turns(ChatbotConversation $conversation): array
    {
        return ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['role', 'message'])
            ->map(fn (ChatbotMessage $message): array => [
                'role' => (string) $message->role,
                'message' => (string) $message->message,
            ])
            ->all();
    }

    private function key(int $conversationId): string
    {
        return 'mansour-revival-'.$conversationId;
    }
}
