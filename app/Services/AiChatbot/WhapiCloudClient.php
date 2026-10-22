<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Whapi.Cloud channel used as the WhatsApp transport for every chatbot instance.
 * Green API URLs stay stored and are used again when the provider is switched back.
 */
class WhapiCloudClient
{
    public bool $webhookWarning = false;

    public function provider(): string
    {
        $forced = strtolower(trim((string) config('whapi.provider', '')));
        if (in_array($forced, ['whapi', 'greenapi'], true)) {
            return $forced;
        }

        if (app()->runningUnitTests()) {
            return $this->storedProvider();
        }

        $stored = Cache::remember('whatsapp_provider', 15, fn (): string => $this->storedProvider());

        return $stored === 'whapi' ? 'whapi' : 'greenapi';
    }

    public function usesWhapi(): bool
    {
        return $this->provider() === 'whapi';
    }

    public function isReady(): bool
    {
        return $this->usesWhapi() && $this->token() !== '';
    }

    public function token(): string
    {
        if (! app()->runningUnitTests() && Cache::has('whapi_token')) {
            return trim((string) Cache::get('whapi_token'));
        }

        $stored = trim((string) ChatbotSetting::query()->where('key', 'whapi_token')->value('value'));
        if ($stored === '') {
            $stored = trim((string) config('whapi.token', ''));
        }

        if (! app()->runningUnitTests()) {
            Cache::put('whapi_token', $stored, 30);
        }

        return $stored;
    }

    /**
     * Whapi is a full stand-in for a Green API send URL.
     */
    public function transportReady(?string $greenApiUrl = null): bool
    {
        if ($this->isReady()) {
            return true;
        }

        return trim((string) $greenApiUrl) !== '';
    }

    public function defaultInstanceId(): int
    {
        $fromConfig = (int) config('whapi.default_instance_id', 0);
        if ($fromConfig > 0) {
            return $fromConfig;
        }

        if (! app()->runningUnitTests() && Cache::has('whapi_default_instance_id')) {
            return (int) Cache::get('whapi_default_instance_id');
        }

        $stored = (int) ChatbotSetting::query()->where('key', 'whapi_default_instance_id')->value('value');
        if (! app()->runningUnitTests()) {
            Cache::put('whapi_default_instance_id', $stored, 30);
        }

        return $stored;
    }

    public function appsBot(): ?ChatbotInstance
    {
        return ChatbotInstance::query()
            ->where('integration_type', 'mansour')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get()
            ->first(fn (ChatbotInstance $candidate): bool => $candidate->isAppsBot());
    }

    /**
     * New chats on this channel go to بوت التطبيق when no conversation exists yet.
     */
    public function bindAppsBot(): ?ChatbotInstance
    {
        $bot = $this->appsBot();

        if (! $bot instanceof ChatbotInstance) {
            return null;
        }

        ChatbotSetting::query()->updateOrCreate(
            ['key' => 'whapi_default_instance_id'],
            ['value' => (string) $bot->id],
        );
        Cache::forget('whapi_default_instance_id');

        return $bot;
    }

    public function channelId(): string
    {
        return trim((string) config('whapi.channel_id', 'HULKBR-69QST'));
    }

    public function baseUrl(): string
    {
        $base = trim((string) config('whapi.base_url', 'https://gate.whapi.cloud'));

        return rtrim($base !== '' ? $base : 'https://gate.whapi.cloud', '/');
    }

    public function webhookToken(): string
    {
        $stored = trim((string) ChatbotSetting::query()->where('key', 'whapi_webhook_token')->value('value'));
        if ($stored !== '') {
            return $stored;
        }

        $configured = trim((string) config('whapi.webhook_token', ''));
        if ($configured !== '') {
            return $configured;
        }

        return substr(hash('sha256', (string) config('app.key').'|whapi|'.$this->channelId()), 0, 48);
    }

    /**
     * The last path segment of a pasted webhook URL, or the token itself.
     */
    public static function parseWebhookToken(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (str_contains($input, '/')) {
            $path = parse_url($input, PHP_URL_PATH);
            $input = trim((string) basename(is_string($path) && $path !== '' ? $path : $input), '/');
        }

        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $input) === 1 ? $input : null;
    }

    /**
     * @return string|null error translation key
     */
    public function applyWebhookInput(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $token = self::parseWebhookToken($input);
        if ($token === null) {
            return 'chatbot.webhook_token_invalid';
        }

        if ($token === $this->webhookToken()) {
            return null;
        }

        ChatbotSetting::query()->updateOrCreate(
            ['key' => 'whapi_webhook_token'],
            ['value' => $token],
        );

        return null;
    }

    public function webhookUrl(): string
    {
        return route('ai-chatbot.whapi.webhook', ['token' => $this->webhookToken()]);
    }

    public function webhookTokenMatches(string $token): bool
    {
        $expected = $this->webhookToken();

        return $expected !== '' && hash_equals($expected, $token);
    }

    /**
     * Save the global provider. An empty token keeps the token already stored.
     *
     * @return string|null error translation key
     */
    public function consumeSettingsRequest(array $validated): ?string
    {
        if (! array_key_exists('whatsapp_provider', $validated)) {
            return null;
        }

        if (array_key_exists('whapi_webhook_url', $validated)) {
            $webhookError = $this->applyWebhookInput((string) ($validated['whapi_webhook_url'] ?? ''));
            if ($webhookError !== null) {
                return $webhookError;
            }
        }

        $provider = (string) $validated['whatsapp_provider'] === 'whapi' ? 'whapi' : 'greenapi';
        $incomingToken = trim((string) ($validated['whapi_token'] ?? ''));

        if ($provider === 'whapi' && $incomingToken === '' && $this->token() === '') {
            return 'chatbot.whapi_token_required';
        }

        $this->saveProvider($provider, $incomingToken !== '' ? $incomingToken : null);

        if ($provider === 'whapi') {
            $hook = $this->configureWebhook();
            $this->webhookWarning = ($hook['ok'] ?? false) !== true;
        }

        return null;
    }

    public function saveProvider(string $provider, ?string $token): void
    {
        $provider = $provider === 'whapi' ? 'whapi' : 'greenapi';

        ChatbotSetting::query()->updateOrCreate(
            ['key' => 'whatsapp_provider'],
            ['value' => $provider],
        );

        if (is_string($token) && trim($token) !== '') {
            ChatbotSetting::query()->updateOrCreate(
                ['key' => 'whapi_token'],
                ['value' => trim($token)],
            );
        }

        Cache::forget('whatsapp_provider');
        Cache::forget('whapi_token');
    }

    /**
     * Point the channel webhook at this app and download inbound media.
     *
     * @return array{ok:bool,status?:int,error?:string,webhook_url:string}
     */
    public function configureWebhook(): array
    {
        $webhookUrl = $this->webhookUrl();
        $token = $this->token();
        if ($token === '') {
            return ['ok' => false, 'error' => 'missing_token', 'webhook_url' => $webhookUrl];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->patch($this->baseUrl().'/settings', [
                    'callback_persist' => true,
                    'media' => [
                        'auto_download' => ['image', 'audio', 'voice', 'video', 'document', 'sticker'],
                    ],
                    'webhooks' => [[
                        'mode' => 'body',
                        'url' => $webhookUrl,
                        'events' => [
                            ['type' => 'messages', 'method' => 'post'],
                            ['type' => 'messages', 'method' => 'put'],
                        ],
                    ]],
                ]);
        } catch (Throwable $e) {
            Log::warning('Whapi webhook update failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'request_failed', 'webhook_url' => $webhookUrl];
        }

        if (! $response->successful()) {
            Log::warning('Whapi webhook update failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return [
                'ok' => false,
                'status' => $response->status(),
                'error' => 'rejected',
                'webhook_url' => $webhookUrl,
            ];
        }

        return ['ok' => true, 'status' => $response->status(), 'webhook_url' => $webhookUrl];
    }

    /**
     * @return array<int, Request>|null
     */
    public function expand(Request $request): ?array
    {
        $messages = $this->whapiMessages($request);
        if ($messages === null || count($messages) < 2) {
            return null;
        }

        if ($this->channelRejected($request->input('channel_id'))) {
            return null;
        }

        $out = [];
        foreach ($messages as $message) {
            $out[] = $this->greenRequestFromMessage($message);
        }

        return $out;
    }

    public function asGreenRequest(Request $request): Request
    {
        $messages = $this->whapiMessages($request);
        if ($messages === null) {
            return $request;
        }

        if ($this->channelRejected($request->input('channel_id'))) {
            return $this->ignoredRequest();
        }

        $message = $messages[0] ?? null;
        if (! is_array($message)) {
            return $this->ignoredRequest();
        }

        return $this->greenRequestFromMessage($message);
    }

    public function resolveInstance(Request $request): ?ChatbotInstance
    {
        $chatId = $this->firstChatId($request);
        if ($chatId !== null) {
            $phone = preg_replace('/\D+/', '', explode('@', $chatId)[0] ?? '') ?? '';
            $conversation = ChatbotConversation::query()
                ->where('channel', ChatbotConversation::CHANNEL_WHATSAPP)
                ->where(function ($query) use ($chatId, $phone): void {
                    $query->where('external_chat_id', $chatId);
                    if ($phone !== '') {
                        $query->orWhere('external_chat_id', $phone.'@s.whatsapp.net')
                            ->orWhere('contact_phone', $phone);
                    }
                })
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();

            $instance = $conversation?->instance;
            if ($instance !== null && $this->transportReady($instance->greenapi_url)) {
                return $instance;
            }
        }

        $preferredId = $this->defaultInstanceId();
        if ($preferredId > 0) {
            $preferred = ChatbotInstance::query()->find($preferredId);
            if ($preferred !== null && $this->transportReady($preferred->greenapi_url)) {
                return $preferred;
            }
        }

        if ($this->isReady()) {
            $apps = $this->appsBot();
            if ($apps !== null) {
                return $apps;
            }

            return ChatbotInstance::query()
                ->where('is_active', true)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();
        }

        return ChatbotInstance::query()
            ->whereNotNull('greenapi_url')
            ->where('greenapi_url', '!=', '')
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first()
            ?? ChatbotInstance::query()
                ->whereNotNull('greenapi_url')
                ->where('greenapi_url', '!=', '')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();
    }

    /**
     * @return array{status:int,body:mixed}
     */
    public function sendText(string $chatId, string $message, ?int $typingTimeMs = null): array
    {
        $payload = [
            'to' => $this->recipient($chatId),
            'body' => $message,
        ];
        $typingSeconds = $this->typingSeconds($typingTimeMs);
        if ($typingSeconds !== null) {
            $payload['typing_time'] = $typingSeconds;
        }

        return $this->post('/messages/text', $payload, $chatId, 30);
    }

    public function deliveryStatus(string $messageId): ?string
    {
        $messageId = trim($messageId);
        if ($messageId === '' || $this->token() === '') {
            return null;
        }

        try {
            $response = Http::withToken($this->token())
                ->acceptJson()
                ->timeout(15)
                ->get($this->baseUrl().'/messages/'.rawurlencode($messageId));
        } catch (Throwable $e) {
            Log::debug('Whapi delivery check failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $status = $response->json('status');

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * @return array{status:int,body:mixed}
     */
    public function sendFileByUrl(string $chatId, string $fileUrl, string $fileName, ?string $caption = null, bool $asDocument = false): array
    {
        $payload = [
            'to' => $this->recipient($chatId),
            'media' => $fileUrl,
        ];
        $caption = trim((string) $caption);
        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        $endpoint = $asDocument ? '/messages/document' : $this->mediaEndpoint($fileName, $fileUrl);
        if ($endpoint === '/messages/document') {
            $name = trim($fileName) !== '' ? trim($fileName) : 'file';
            $payload['filename'] = $name;
        }

        return $this->post($endpoint, $payload, $chatId, 60);
    }

    /**
     * @return array{status:int,body:mixed}|null
     */
    public function sendTyping(string $chatId, ?int $typingTimeMs = null): ?array
    {
        $presenceId = rawurlencode($this->presenceChatId($chatId));
        $payload = ['presence' => 'typing'];

        try {
            $response = Http::withToken($this->token())
                ->acceptJson()
                ->timeout(8)
                ->put($this->baseUrl().'/presences/'.$presenceId, $payload);

            return [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        } catch (Throwable $e) {
            Log::debug('Whapi typing presence failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function canonicalChatId(string $chatId): string
    {
        $chatId = trim($chatId);
        if (preg_match('/^(\d+)@s\.whatsapp\.net$/i', $chatId, $matches) === 1) {
            return $matches[1].'@c.us';
        }

        return $chatId;
    }

    public function recipient(string $chatId): string
    {
        $chatId = trim($chatId);
        $lower = strtolower($chatId);
        if ($chatId === '') {
            return $chatId;
        }

        if (! str_ends_with($lower, '@c.us') && ! str_ends_with($lower, '@s.whatsapp.net')) {
            if (str_contains($chatId, '@')) {
                return $chatId;
            }

            $digits = preg_replace('/\D+/', '', $chatId) ?? '';

            return $digits !== '' ? $digits : $chatId;
        }

        $digits = preg_replace('/\D+/', '', explode('@', $chatId)[0] ?? '') ?? '';

        return $digits !== '' ? $digits : $chatId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:int,body:mixed}
     */
    private function post(string $path, array $payload, string $chatId, int $timeout): array
    {
        $token = $this->token();
        if ($token === '') {
            return ['status' => 0, 'body' => 'missing_whapi_token'];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($timeout)
                ->post($this->baseUrl().$path, $payload);
        } catch (Throwable $e) {
            Log::warning('Whapi send failed', [
                'path' => $path,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 0, 'body' => 'request_failed'];
        }

        $json = $response->json();
        $body = is_array($json) ? $json : ['raw' => $response->body()];
        $id = $body['message']['id'] ?? $body['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $body['idMessage'] = $id;
        }

        if (! $response->successful()) {
            Log::warning('Whapi send failed', [
                'path' => $path,
                'status' => $response->status(),
                'chat_id' => $chatId,
                'body' => mb_substr($response->body(), 0, 500),
            ]);
        }

        return [
            'status' => $response->status(),
            'body' => $body,
        ];
    }

    private function mediaEndpoint(string $fileName, string $fileUrl): string
    {
        $path = strtolower($fileName.' '.parse_url($fileUrl, PHP_URL_PATH));

        return match (true) {
            str_contains($path, '.png'),
            str_contains($path, '.jpg'),
            str_contains($path, '.jpeg'),
            str_contains($path, '.webp') => '/messages/image',
            str_contains($path, '.gif') => '/messages/gif',
            str_contains($path, '.mp4'),
            str_contains($path, '.mov'),
            str_contains($path, '.webm') => '/messages/video',
            str_contains($path, '.ogg'),
            str_contains($path, '.mp3'),
            str_contains($path, '.m4a'),
            str_contains($path, '.wav'),
            str_contains($path, '.opus') => '/messages/audio',
            default => '/messages/document',
        };
    }

    private function typingSeconds(?int $typingTimeMs): ?int
    {
        if ($typingTimeMs === null || $typingTimeMs <= 0) {
            return null;
        }

        return max(1, min(60, (int) round($typingTimeMs / 1000)));
    }

    private function presenceChatId(string $chatId): string
    {
        $chatId = trim($chatId);
        if (preg_match('/^(\d+)@c\.us$/i', $chatId, $matches) === 1) {
            return $matches[1].'@s.whatsapp.net';
        }
        if (! str_contains($chatId, '@')) {
            $digits = preg_replace('/\D+/', '', $chatId) ?? '';

            return $digits !== '' ? $digits.'@s.whatsapp.net' : $chatId;
        }

        return $chatId;
    }

    private function storedProvider(): string
    {
        $stored = strtolower(trim((string) ChatbotSetting::query()->where('key', 'whatsapp_provider')->value('value')));

        return $stored === 'whapi' ? 'whapi' : 'greenapi';
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function whapiMessages(Request $request): ?array
    {
        if ($request->attributes->get('whapi_normalized') === true) {
            return null;
        }

        $event = $request->input('event');
        $messages = $request->input('messages');
        if (! is_array($event) || ! is_array($messages)) {
            return null;
        }

        $type = strtolower(trim((string) ($event['type'] ?? '')));
        if ($type !== '' && $type !== 'messages') {
            return [];
        }

        return array_values(array_filter($messages, 'is_array'));
    }

    private function channelRejected(mixed $channelId): bool
    {
        $expected = $this->channelId();
        $channelId = trim((string) $channelId);
        if ($expected === '' || $channelId === '') {
            return false;
        }

        return strcasecmp($channelId, $expected) !== 0;
    }

    private function firstChatId(Request $request): ?string
    {
        $messages = $request->input('messages');
        if (! is_array($messages)) {
            $chatId = $request->input('senderData.chatId', $request->input('chatId'));

            return is_string($chatId) && trim($chatId) !== '' ? $this->canonicalChatId($chatId) : null;
        }

        $first = $messages[0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        $chatId = trim((string) ($first['chat_id'] ?? ''));

        return $chatId !== '' ? $this->canonicalChatId($chatId) : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function greenRequestFromMessage(array $message): Request
    {
        $type = strtolower(trim((string) ($message['type'] ?? 'text')));
        $fromMe = filter_var($message['from_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $source = strtolower(trim((string) ($message['source'] ?? '')));
        $chatId = $this->canonicalChatId((string) ($message['chat_id'] ?? ''));
        $text = $this->textOf($message);
        $media = $this->mediaOf($message);
        $typeMessage = $this->greenType($type);

        $typeWebhook = match (true) {
            $typeMessage === null || $chatId === '' => 'whapiIgnored',
            $fromMe && $source === 'api' => 'outgoingAPIMessageReceived',
            $fromMe => 'outgoingMessageReceived',
            default => 'incomingMessageReceived',
        };

        $messageData = ['typeMessage' => $typeMessage ?? 'textMessage'];
        if ($text !== null && $typeMessage === 'textMessage') {
            $messageData['textMessageData'] = ['textMessage' => $text];
        }

        if ($media !== null && $typeMessage !== null) {
            $block = array_filter([
                'downloadUrl' => $media['link'],
                'caption' => $media['caption'] ?? $text,
                'mimeType' => $media['mime'],
                'fileName' => $media['name'],
            ], static fn ($value) => is_string($value) && $value !== '');

            $key = match ($typeMessage) {
                'imageMessage' => 'imageMessageData',
                'audioMessage' => 'audioMessageData',
                'documentMessage' => 'fileMessageData',
                'stickerMessage' => 'stickerMessageData',
                default => 'fileMessageData',
            };
            $messageData[$key] = $block;
        } elseif ($text !== null && $typeMessage !== null && $typeMessage !== 'textMessage') {
            $messageData['caption'] = $text;
        }

        $quoted = $message['context']['quoted_content']['body'] ?? $message['context']['quoted_content']['caption'] ?? null;
        if (is_string($quoted) && trim($quoted) !== '') {
            $messageData['quotedMessage'] = ['textMessage' => trim($quoted)];
        }

        $messageId = trim((string) ($message['id'] ?? ''));
        $senderName = trim((string) ($message['from_name'] ?? ''));
        $timestamp = is_numeric($message['timestamp'] ?? null) ? (int) $message['timestamp'] : null;

        $payload = [
            'typeWebhook' => $typeWebhook,
            'idMessage' => $messageId !== '' ? $messageId : null,
            'timestamp' => $timestamp,
            'senderData' => array_filter([
                'chatId' => $chatId,
                'senderName' => $senderName !== '' ? $senderName : null,
                'fromMe' => $fromMe,
            ], static fn ($value) => $value !== null && $value !== ''),
            'messageData' => $messageData,
        ];

        $converted = Request::create('/', 'POST', $payload);
        $converted->attributes->set('whapi_normalized', true);

        return $converted;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function textOf(array $message): ?string
    {
        foreach ([
            $message['text']['body'] ?? null,
            $message['link_preview']['body'] ?? null,
            $message['reply']['buttons_reply']['title'] ?? null,
            $message['reply']['list_reply']['title'] ?? null,
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $type = (string) ($message['type'] ?? '');
        $block = $message[$type] ?? null;
        if (is_array($block)) {
            foreach (['caption', 'body', 'title'] as $key) {
                $value = $block[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        if ($type === 'location' && isset($message['location']['latitude'], $message['location']['longitude'])) {
            return $message['location']['latitude'].', '.$message['location']['longitude'];
        }

        if ($type === 'contact') {
            $name = trim((string) ($message['contact']['name'] ?? ''));

            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{link:string,mime:?string,name:?string,caption:?string}|null
     */
    private function mediaOf(array $message): ?array
    {
        $type = (string) ($message['type'] ?? '');
        $block = $message[$type] ?? null;
        if (! is_array($block)) {
            return null;
        }

        $link = $block['link'] ?? $block['url'] ?? null;
        if (! is_string($link) || ! str_starts_with($link, 'http')) {
            $mediaId = trim((string) ($block['id'] ?? ''));
            if ($mediaId === '') {
                return null;
            }
            $link = $this->baseUrl().'/media/'.rawurlencode($mediaId);
        }

        return [
            'link' => $link,
            'mime' => is_string($block['mime_type'] ?? null) ? $block['mime_type'] : null,
            'name' => is_string($block['file_name'] ?? null)
                ? $block['file_name']
                : (is_string($block['filename'] ?? null) ? $block['filename'] : null),
            'caption' => is_string($block['caption'] ?? null) ? $block['caption'] : null,
        ];
    }

    private function greenType(string $type): ?string
    {
        return match ($type) {
            'image', 'gif', 'short', 'video' => 'imageMessage',
            'audio', 'voice' => 'audioMessage',
            'document' => 'documentMessage',
            'sticker' => 'stickerMessage',
            'action', 'call', 'system', 'unknown' => null,
            default => 'textMessage',
        };
    }

    private function ignoredRequest(): Request
    {
        $request = Request::create('/', 'POST', ['typeWebhook' => 'whapiIgnored']);
        $request->attributes->set('whapi_normalized', true);

        return $request;
    }
}
