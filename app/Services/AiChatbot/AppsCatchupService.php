<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Replay missed WhatsApp lines into the Apps bot and store the reply it would
 * have sent. Nothing is delivered while the line is on outbound hold.
 */
class AppsCatchupService
{
    /**
     * Copy the WhatsApp transcript into the chat. Does not call the model and does not send.
     *
     * @return array<string, mixed>
     */
    public function syncBatch(ChatbotInstance $instance, int $afterId, int $limit): array
    {
        if (! $instance->isAppsBot() || ! $instance->outboundHeld()) {
            return ['ok' => false, 'reason' => 'hold'];
        }

        $limit = max(1, min(6, $limit));
        $sendUrl = $this->sendUrl($instance);
        if ($afterId === 0) {
            $state = $this->lineState($sendUrl);
            if ($state !== 'authorized') {
                return ['ok' => false, 'reason' => 'not_authorized', 'green_state' => $state];
            }
        }

        $rows = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->where('id', '>', $afterId)
            ->where(function ($query): void {
                $query->whereNull('external_chat_id')
                    ->orWhere('external_chat_id', 'not like', '%@g.us');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $results = [];
        $lastId = $afterId;
        foreach ($rows as $conversation) {
            $lastId = (int) $conversation->id;
            $results[] = $this->syncOne($conversation, $sendUrl);
            usleep(200_000);
        }

        $more = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->where('id', '>', $lastId)
            ->exists();

        $payload = [
            'ok' => true,
            'hold' => true,
            'last_id' => $lastId,
            'done' => ! $more,
            'rows' => $results,
        ];
        if (! $more) {
            $summary = app(AppsInsightService::class)->summarize($instance);
            $payload['groups'] = [
                'downloaded' => $summary['downloaded'],
                'waiting_account' => $summary['waiting_account'],
                'no_reply' => $summary['no_reply'],
                'follow_up' => $summary['follow_up'],
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncOne(ChatbotConversation $conversation, string $sendUrl): array
    {
        $id = (int) $conversation->id;
        $chatId = $this->chatId($conversation);
        if ($chatId === '' || $sendUrl === '') {
            return ['id' => $id, 'action' => 'skip'];
        }

        try {
            $history = $this->history($sendUrl, $chatId);
        } catch (Throwable) {
            return ['id' => $id, 'action' => 'history_failed'];
        }
        if ($history === []) {
            return ['id' => $id, 'action' => 'empty'];
        }

        $removed = ChatbotMessage::query()
            ->where('conversation_id', $id)
            ->where('role', 'assistant')
            ->where('delivery_status', 'local')
            ->where('metadata->not_sent', true)
            ->delete();

        $knownIds = ChatbotMessage::query()
            ->where('conversation_id', $id)
            ->whereNotNull('external_message_id')
            ->pluck('external_message_id')
            ->map(fn ($value): string => (string) $value)
            ->all();
        $knownText = [];
        foreach ($this->turns($conversation) as $turn) {
            $knownText[($turn['role'] ?? '').'|'.$this->normalize((string) $turn['message'])] = true;
        }

        $importedIn = 0;
        $importedOut = 0;
        foreach ($history as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $role = ($item['direction'] ?? '') === 'in' ? 'user' : 'assistant';
            $externalId = (string) ($item['id'] ?? '');
            if ($externalId !== '' && in_array($externalId, $knownIds, true)) {
                continue;
            }
            $key = $role.'|'.$this->normalize($text);
            if (isset($knownText[$key])) {
                continue;
            }
            $conversation->messages()->create([
                'role' => $role,
                'sender_type' => $role === 'user' ? 'customer' : 'ai',
                'external_message_id' => $externalId !== '' ? $externalId : null,
                'message_type' => 'text',
                'message' => $text,
                'delivery_status' => 'local',
                'metadata' => ['sync' => true],
            ]);
            $knownText[$key] = true;
            if ($externalId !== '') {
                $knownIds[] = $externalId;
            }
            if ($role === 'user') {
                $importedIn++;
            } else {
                $importedOut++;
            }
        }

        return [
            'id' => $id,
            'action' => ($importedIn + $importedOut + $removed) > 0 ? 'synced' : 'same',
            'in' => $importedIn,
            'out' => $importedOut,
            'removed' => $removed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function batch(ChatbotInstance $instance, int $afterId, int $limit): array
    {
        if (! $instance->isAppsBot() || ! $instance->outboundHeld()) {
            return ['ok' => false, 'reason' => 'hold'];
        }

        $limit = max(1, min(4, $limit));
        $rows = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->where('id', '>', $afterId)
            ->where(function ($query): void {
                $query->whereNull('external_chat_id')
                    ->orWhere('external_chat_id', 'not like', '%@g.us');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sendUrl = $this->sendUrl($instance);
        if ($afterId === 0) {
            $state = $this->lineState($sendUrl);
            if ($state !== 'authorized') {
                return ['ok' => false, 'reason' => 'not_authorized', 'green_state' => $state];
            }
        }

        $results = [];
        $lastId = $afterId;
        foreach ($rows as $conversation) {
            $lastId = (int) $conversation->id;
            $results[] = $this->one($instance, $conversation, $sendUrl);
            usleep(250_000);
        }

        $more = ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->where('id', '>', $lastId)
            ->exists();

        return [
            'ok' => true,
            'hold' => true,
            'active' => $instance->isBotGloballyActive(),
            'after' => $afterId,
            'last_id' => $lastId,
            'done' => ! $more,
            'rows' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function one(ChatbotInstance $instance, ChatbotConversation $conversation, string $sendUrl): array
    {
        $id = (int) $conversation->id;
        $chatId = $this->chatId($conversation);
        if ($chatId === '' || $sendUrl === '') {
            return ['id' => $id, 'action' => 'skip'];
        }

        try {
            $history = $this->history($sendUrl, $chatId);
        } catch (Throwable) {
            return ['id' => $id, 'action' => 'history_failed'];
        }

        $imported = $this->importIncoming($conversation, $history);
        $conversation->refresh();
        $turns = $this->turns($conversation);
        if (! $this->hasUser($turns) || AppsPrompt::isFinished($turns)) {
            return ['id' => $id, 'action' => 'stored', 'imported' => $imported, 'replies' => 0];
        }

        $replies = 0;
        if ($this->endsWithUser($turns) && AppsPrompt::shouldSendOpening($turns)) {
            $this->storeText($conversation, AppsPrompt::openingFor($instance, (string) $conversation->contact_name));
            $replies++;
            $conversation->refresh();
            $turns = $this->turns($conversation);
        }

        $needsAnswer = ! AppsPrompt::isFinished($turns) && $this->hasSubstantive($turns) && (
            $this->endsWithUser($turns) || $this->lastRealAssistantIsOpening($turns)
        );
        if ($needsAnswer) {
            $decision = AppsPrompt::nextReply(
                $turns,
                (string) $conversation->contact_name,
                AppsPrompt::links($instance),
                AppsPrompt::memoryOf($conversation),
                $instance,
                true,
            );
            AppsPrompt::storeMemory($conversation, $decision['memory'] ?? [], AppsPrompt::memoryVersion($conversation));
            $text = trim((string) ($decision['text'] ?? ''));
            if ($text !== '') {
                $this->storeText($conversation, $text);
                $replies++;
                if (($decision['pause'] ?? false) === true) {
                    $conversation->forceFill(['bot_mode' => ChatbotConversation::BOT_MODE_PAUSED])->save();
                }
            }
        }

        return ['id' => $id, 'action' => $replies > 0 ? 'replied' : 'stored', 'imported' => $imported, 'replies' => $replies];
    }

    /**
     * @param  list<array<string, mixed>>  $history
     */
    private function importIncoming(ChatbotConversation $conversation, array $history): int
    {
        $knownIds = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('external_message_id')
            ->pluck('external_message_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $knownText = [];
        foreach ($this->turns($conversation) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $knownText[$this->normalize((string) $turn['message'])] = true;
            }
        }

        $imported = 0;
        foreach ($history as $item) {
            if (($item['direction'] ?? '') !== 'in') {
                continue;
            }
            $externalId = (string) ($item['id'] ?? '');
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if ($externalId !== '' && in_array($externalId, $knownIds, true)) {
                continue;
            }
            $key = $this->normalize($text);
            if (isset($knownText[$key])) {
                continue;
            }
            $conversation->messages()->create([
                'role' => 'user',
                'sender_type' => 'customer',
                'external_message_id' => $externalId !== '' ? $externalId : null,
                'message_type' => 'text',
                'message' => $text,
                'delivery_status' => 'local',
                'metadata' => ['catchup' => true],
            ]);
            $knownText[$key] = true;
            if ($externalId !== '') {
                $knownIds[] = $externalId;
            }
            $imported++;
        }
        if ($imported > 0) {
            $conversation->recordCustomerActivity();
        }

        return $imported;
    }

    private function storeText(ChatbotConversation $conversation, string $text): void
    {
        foreach (preg_split('/\n\n‖\n\n/u', $text) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                'message' => $part,
                'delivery_status' => 'local',
                'metadata' => ['not_sent' => true, 'catchup' => true],
            ]);
        }
        $conversation->recordAssistantActivity();
    }

    /**
     * @return list<array{role:string,message:string}>
     */
    private function turns(ChatbotConversation $conversation): array
    {
        return ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get(['role', 'message'])
            ->map(fn (ChatbotMessage $message): array => [
                'role' => (string) $message->role,
                'message' => (string) $message->message,
            ])
            ->all();
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private function hasUser(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'user' && trim((string) ($turn['message'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private function endsWithUser(array $turns): bool
    {
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            $text = trim((string) ($turns[$i]['message'] ?? ''));
            if ($text === '') {
                continue;
            }

            return ($turns[$i]['role'] ?? '') === 'user';
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private function hasSubstantive(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'user') {
                continue;
            }
            if ($this->substantive((string) ($turn['message'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private function lastRealAssistantIsOpening(array $turns): bool
    {
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if (($turns[$i]['role'] ?? '') !== 'assistant') {
                continue;
            }
            $text = trim((string) ($turns[$i]['message'] ?? ''));
            if ($text === '' || $text === '؟' || $text === '?') {
                continue;
            }

            return str_contains($text, 'هل نزّلت');
        }

        return false;
    }

    private function substantive(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return false;
        }

        return ! in_array($text, [
            'الحمدلله', 'الحمد لله', 'الحمدالله', 'تمام', 'منيح', 'اهلا', 'هلا', 'مرحبا', 'مرحبتين',
            'كيفك', 'كويس', 'بخير', 'hi', 'hello', 'تمام الحمدلله', 'تمام الحمد لله', 'اهلين',
        ], true);
    }

    /**
     * @return list<array{direction:string,id:string,text:string}>
     */
    private function history(string $sendUrl, string $chatId): array
    {
        if (preg_match('~^(https://[^/]+/waInstance\d+)/sendMessage/([^/]+)~', $sendUrl, $matches) !== 1) {
            return [];
        }

        $response = null;
        for ($try = 0; $try < 4; $try++) {
            $response = Http::timeout(25)
                ->acceptJson()
                ->post($matches[1].'/getChatHistory/'.$matches[2], [
                    'chatId' => $chatId,
                    'count' => 100,
                ]);
            if ($response->status() !== 429 && $response->status() < 500) {
                break;
            }
            usleep(700_000 * ($try + 1));
        }
        if ($response === null || ! $response->successful()) {
            throw new \RuntimeException('history '.($response?->status() ?? 0));
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            return [];
        }
        if ($payload !== [] && array_is_list($payload) === false) {
            throw new \RuntimeException('history shape');
        }

        $rows = [];
        foreach ($payload as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->historyText($item);
            $rows[] = [
                'direction' => (string) ($item['type'] ?? '') === 'incoming' ? 'in' : 'out',
                'id' => (string) ($item['idMessage'] ?? ''),
                'text' => $text,
            ];
        }

        return array_reverse($rows);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function historyText(array $item): string
    {
        $text = $item['textMessage'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }
        if (is_array($text) && isset($text['text']) && is_string($text['text'])) {
            return trim($text['text']);
        }
        $extended = $item['extendedTextMessage'] ?? null;
        if (is_array($extended) && isset($extended['text']) && is_string($extended['text'])) {
            return trim($extended['text']);
        }
        $caption = $item['caption'] ?? null;
        if (is_string($caption) && trim($caption) !== '') {
            return trim($caption);
        }
        $type = (string) ($item['typeMessage'] ?? '');
        if (str_contains($type, 'image') || str_contains($type, 'sticker')) {
            return '[صورة]';
        }
        if (str_contains($type, 'audio') || str_contains($type, 'ptt')) {
            return '[رسالة صوتية]';
        }

        return '';
    }

    private function lineState(string $sendUrl): string
    {
        if (preg_match('~^(https://[^/]+/waInstance\d+)/sendMessage/([^/]+)~', $sendUrl, $matches) !== 1) {
            return 'missing';
        }

        try {
            $response = Http::timeout(20)->acceptJson()->get($matches[1].'/getStateInstance/'.$matches[2]);
        } catch (Throwable) {
            return 'unread';
        }

        return (string) ($response->json('stateInstance') ?: 'empty');
    }

    private function sendUrl(ChatbotInstance $instance): string
    {
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        foreach ($settings['greenapi_lines'] ?? [] as $line) {
            if (is_array($line) && trim((string) ($line['url'] ?? '')) !== '') {
                return trim((string) $line['url']);
            }
        }

        return trim((string) $instance->greenapi_url);
    }

    private function chatId(ChatbotConversation $conversation): string
    {
        $external = trim((string) $conversation->external_chat_id);
        if (str_ends_with($external, '@c.us')) {
            return $external;
        }
        $digits = preg_replace('/\D+/', '', (string) ($conversation->contact_phone ?: $external)) ?: '';
        if (str_starts_with($digits, '0')) {
            $digits = '972'.substr($digits, 1);
        }
        if (! str_starts_with($digits, '972') || strlen($digits) < 11) {
            return '';
        }

        return $digits.'@c.us';
    }

    private function normalize(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strtolower($text);
    }
}
