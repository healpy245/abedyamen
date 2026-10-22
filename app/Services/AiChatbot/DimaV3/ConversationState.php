<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\DimaV3;

use App\Models\AiChatbot\ChatbotConversation;

/**
 * V3 memory lives on metadata.dima_v3 so V1 memory normalization cannot drop it.
 */
class ConversationState
{
    public function __construct(private readonly ConversationFlow $flow) {}

    /**
     * @return array{
     *     current_stage:string,
     *     permission_status:string,
     *     voting_intention:string,
     *     party_mentioned:string,
     *     last_user_question:string,
     *     previously_answered_topics:list<string>,
     *     sent_media_ids:list<string>,
     *     opted_out:bool
     * }
     */
    public function read(ChatbotConversation $conversation): array
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $raw = is_array($meta['dima_v3'] ?? null) ? $meta['dima_v3'] : [];

        return $this->normalize($raw);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function write(ChatbotConversation $conversation, array $state): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['dima_v3'] = $this->normalize($state);
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    public function isClosed(ChatbotConversation $conversation): bool
    {
        $state = $this->read($conversation);

        return $state['opted_out'] || $state['current_stage'] === ConversationFlow::CLOSED;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     current_stage:string,
     *     permission_status:string,
     *     voting_intention:string,
     *     party_mentioned:string,
     *     last_user_question:string,
     *     previously_answered_topics:list<string>,
     *     sent_media_ids:list<string>,
     *     opted_out:bool
     * }
     */
    public function normalize(array $raw): array
    {
        $stage = $this->flow->normalize((string) ($raw['current_stage'] ?? ''));
        if ($stage === '') {
            $stage = ConversationFlow::GREETING;
        }
        $permission = (string) ($raw['permission_status'] ?? '');
        if (! in_array($permission, ['', 'granted', 'refused'], true)) {
            $permission = '';
        }
        $vote = (string) ($raw['voting_intention'] ?? '');
        if (! in_array($vote, ['', 'yes', 'no'], true)) {
            $vote = '';
        }

        return [
            'current_stage' => $stage,
            'permission_status' => $permission,
            'voting_intention' => $vote,
            'party_mentioned' => mb_substr(trim((string) ($raw['party_mentioned'] ?? '')), 0, 120),
            'last_user_question' => mb_substr(trim((string) ($raw['last_user_question'] ?? '')), 0, 240),
            'previously_answered_topics' => $this->stringList($raw['previously_answered_topics'] ?? []),
            'sent_media_ids' => $this->stringList($raw['sent_media_ids'] ?? []),
            'opted_out' => (bool) ($raw['opted_out'] ?? false),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $items = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = mb_substr(trim($item), 0, 160);
            if ($trimmed !== '' && ! in_array($trimmed, $items, true)) {
                $items[] = $trimmed;
            }
        }

        return $items;
    }
}
