<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\MansourContact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Several Green API instances ("lines") behind one Mansour bot.
 *
 * Each line has its own webhook, its own Green API instance, and its own on/off
 * switch. Several lines can be on together. A send never moves to another line.
 * A conversation remembers the line it talks on (metadata.mansour_line) and keeps
 * that memory even when the line is paused or blocked.
 */
class MansourLines
{
    public const LIVE = 'live';

    public const READY = 'ready';

    public const BLOCKED = 'blocked';

    /** Green API stateInstance values that mean the line cannot send. `suspended` replaced `yellowCard`. */
    public const DEAD_STATES = ['blocked', 'yellowCard', 'suspended', 'notAuthorized'];

    /** Green API answers 466 when the plan's message quota is used up. */
    private const QUOTA_STATUS = 466;

    /**
     * New chats one number can open today (Asia/Jerusalem). The danger
     * percentage is today's count divided by this cap, so it falls after midnight.
     */
    public const DAY_CAP = 24;

    /** New chats one number opens in a single send. The rest of the day stays for a later send. */
    public const WAVE_CAP = 12;

    /** A third of the daily cap. */
    public const RISK_MEDIUM = 8;

    /** Two thirds of the daily cap. */
    public const RISK_HIGH = 16;

    /** The daily cap. At this count the number should stop opening chats. */
    public const RISK_CRITICAL = 24;

    /** @var array<string, string> */
    public const RISK_LABELS = [
        'low' => 'منخفض',
        'medium' => 'متوسط',
        'high' => 'مرتفع',
        'critical' => 'خطر',
    ];

    /** Green API reasons that mean the number was logged out or restricted. */
    public const ALERT_LABELS = [
        'notAuthorized' => 'غير مسجّل',
        'blocked' => 'محظور',
        'yellowCard' => 'إنذار واتساب',
        'suspended' => 'موقوف',
    ];

    public function __construct(protected ChatbotGreenApiService $greenApi) {}

    /**
     * @return list<array{id: string, url: string, status: string, reason: ?string, changed_at: ?string}>
     */
    public function lines(ChatbotInstance $instance): array
    {
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $stored = is_array($settings['greenapi_lines'] ?? null) ? $settings['greenapi_lines'] : [];

        $lines = [];
        foreach ($stored as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $lines[] = [
                'id' => $this->idFor($url),
                'url' => $url,
                'status' => in_array($row['status'] ?? null, [self::LIVE, self::READY, self::BLOCKED], true) ? $row['status'] : self::READY,
                'reason' => isset($row['reason']) ? (string) $row['reason'] : null,
                'changed_at' => isset($row['changed_at']) ? (string) $row['changed_at'] : null,
                'name' => isset($row['name']) ? (string) $row['name'] : '',
                'phone' => isset($row['phone']) ? (string) $row['phone'] : '',
                'avatar' => isset($row['avatar']) ? (string) $row['avatar'] : '',
                'profile_at' => isset($row['profile_at']) ? (string) $row['profile_at'] : null,
                'webhook_at' => isset($row['webhook_at']) ? (string) $row['webhook_at'] : null,
                'active' => array_key_exists('active', $row) ? (bool) $row['active'] : null,
            ];
        }

        $legacy = trim((string) $instance->greenapi_url);
        if ($lines === [] && $legacy !== '') {
            $lines[] = [
                'id' => $this->idFor($legacy),
                'url' => $legacy,
                'status' => self::LIVE,
                'reason' => null,
                'changed_at' => null,
                'name' => '',
                'phone' => '',
                'avatar' => '',
                'profile_at' => null,
                'webhook_at' => null,
                'active' => null,
            ];
        }

        return $lines;
    }

    /**
     * A line answers and can be triggered only while this is true.
     * Older rows have no flag: the single live line is on, standby lines are off.
     *
     * @param  array{status?: string, active?: ?bool}  $line
     */
    public function isOn(array $line): bool
    {
        if (($line['status'] ?? '') === self::BLOCKED) {
            return false;
        }
        if (array_key_exists('active', $line) && $line['active'] !== null) {
            return (bool) $line['active'];
        }

        return ($line['status'] ?? '') === self::LIVE;
    }

    /**
     * Turn one line on or off without touching the others.
     */
    public function setActive(ChatbotInstance $instance, string $lineId, bool $on): bool
    {
        $lineId = trim($lineId);
        $lines = $this->lines($instance);
        $found = false;
        foreach ($lines as $i => $line) {
            if ($line['id'] !== $lineId) {
                continue;
            }
            if ($on && $line['status'] === self::BLOCKED) {
                return false;
            }
            $lines[$i]['active'] = $on;
            $found = true;
        }
        if (! $found) {
            return false;
        }

        $this->store($instance, $lines);
        if ($on) {
            $this->claim($instance, [$lineId], [$lineId]);
        }

        return true;
    }

    /**
     * Turn several lines on or off in one save. A blocked line is left off.
     *
     * @param  list<string>  $lineIds
     * @return list<string> ids that ended in the requested state
     */
    public function setManyActive(ChatbotInstance $instance, array $lineIds, bool $on): array
    {
        $wanted = [];
        foreach ($lineIds as $lineId) {
            $lineId = trim((string) $lineId);
            if ($lineId !== '') {
                $wanted[$lineId] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $lines = $this->lines($instance);
        $applied = [];
        $dirty = false;
        foreach ($lines as $i => $line) {
            if (! isset($wanted[$line['id']])) {
                continue;
            }
            if ($on && $line['status'] === self::BLOCKED) {
                continue;
            }
            if ($this->isOn($line) !== $on) {
                $dirty = true;
            }
            $lines[$i]['active'] = $on;
            $applied[] = $line['id'];
        }
        if ($dirty) {
            $this->store($instance, $lines);
        }
        if ($on && $applied !== []) {
            $this->claim($instance, $applied, $applied);
        }

        return $applied;
    }

    /**
     * A number belongs to one bot. Taking it here removes it from the owner's other bots,
     * and its webhook follows this bot so replies do not land on the other one.
     *
     * @param  list<string>  $lineIds
     * @param  list<string>  $repoint  ids whose webhook is pointed here even when no other bot had them
     */
    private function claim(ChatbotInstance $instance, array $lineIds, array $repoint = []): void
    {
        $wanted = [];
        foreach ($lineIds as $lineId) {
            $lineId = trim((string) $lineId);
            if ($lineId !== '') {
                $wanted[$lineId] = true;
            }
        }
        if ($wanted === []) {
            return;
        }

        $moved = [];
        foreach ($this->siblings($instance) as $other) {
            foreach ($this->drop($other, array_map('strval', array_keys($wanted))) as $id) {
                $moved[$id] = true;
            }
        }
        foreach ($repoint as $id) {
            if (isset($wanted[(string) $id])) {
                $moved[(string) $id] = true;
            }
        }

        if ($moved === [] || app()->runningUnitTests() || app(WhapiCloudClient::class)->usesWhapi()) {
            return;
        }

        $fresh = $instance->fresh() ?? $instance;
        $urls = [];
        foreach ($this->lines($fresh) as $line) {
            if (isset($moved[$line['id']])) {
                $urls[] = $line['url'];
            }
        }
        $this->pointWebhooks($fresh, $urls, true);
    }

    /**
     * The owner's other bots that can hold the same numbers.
     *
     * @return \Illuminate\Support\Collection<int, ChatbotInstance>
     */
    public function siblings(ChatbotInstance $instance): \Illuminate\Support\Collection
    {
        return ChatbotInstance::query()
            ->where('user_id', $instance->user_id)
            ->where('integration_type', 'mansour')
            ->where('id', '!=', $instance->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Take numbers off one bot. Returns the ids that were there.
     *
     * @param  list<string>  $lineIds
     * @return list<string>
     */
    public function drop(ChatbotInstance $instance, array $lineIds): array
    {
        $wanted = [];
        foreach ($lineIds as $lineId) {
            $wanted[trim((string) $lineId)] = true;
        }
        $keep = [];
        $dropped = [];
        foreach ($this->lines($instance) as $line) {
            if (isset($wanted[$line['id']])) {
                $dropped[] = $line['id'];

                continue;
            }
            $keep[] = $line;
        }
        if ($dropped !== []) {
            $this->store($instance, $keep);
        }

        return $dropped;
    }

    /**
     * Add numbers to this bot and switch them on. Each one leaves any other bot it was on.
     *
     * @param  list<string>  $urls
     * @return list<string> ids that were not on this bot before
     */
    public function attach(ChatbotInstance $instance, array $urls): array
    {
        $current = $this->lines($instance);
        $have = array_column($current, 'id');
        $active = [];
        foreach ($current as $line) {
            if ($this->isOn($line)) {
                $active[] = $line['id'];
            }
        }
        $added = [];
        foreach ($urls as $url) {
            $id = $this->idFor((string) $url);
            if (! in_array($id, $have, true) && ! in_array($id, $added, true)) {
                $added[] = $id;
            }
        }
        if ($added === []) {
            return [];
        }

        $this->sync($instance, array_merge(array_column($current, 'url'), $urls), null, array_merge($active, $added));

        return $added;
    }

    /**
     * Give numbers to one bot of the same owner, or to none when $target is null.
     * $catalog maps ids to URLs for numbers that are on no bot yet.
     *
     * @param  list<string>  $lineIds
     * @param  array<string, string>  $catalog
     * @return list<string> ids that moved
     */
    public function assign(ChatbotInstance $owner, array $lineIds, ?ChatbotInstance $target, array $catalog = []): array
    {
        $wanted = [];
        foreach ($lineIds as $lineId) {
            $lineId = trim((string) $lineId);
            if ($lineId !== '') {
                $wanted[$lineId] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $bots = $this->siblings($owner)->prepend($owner);
        if ($target === null) {
            $moved = [];
            foreach ($bots as $bot) {
                foreach ($this->drop($bot, array_map('strval', array_keys($wanted))) as $id) {
                    $moved[$id] = true;
                }
            }

            return array_map('strval', array_keys($moved));
        }

        $urls = [];
        foreach ($bots as $bot) {
            foreach ($this->lines($bot) as $line) {
                if (isset($wanted[$line['id']]) && ! isset($urls[$line['id']])) {
                    $urls[$line['id']] = $line['url'];
                }
            }
        }
        foreach ($catalog as $id => $url) {
            $id = (string) $id;
            if (isset($wanted[$id]) && ! isset($urls[$id]) && trim((string) $url) !== '') {
                $urls[$id] = (string) $url;
            }
        }

        return $this->attach($target, array_values($urls));
    }

    /**
     * Older data could keep one number on several bots. Leave it only on the bot where it is on,
     * or on the first bot that has it.
     *
     * @return int numbers removed from a bot
     */
    public function dedupe(): int
    {
        $removed = 0;
        $byOwner = ChatbotInstance::query()
            ->where('integration_type', 'mansour')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');
        foreach ($byOwner as $bots) {
            $keeper = [];
            foreach ($bots as $bot) {
                foreach ($this->lines($bot) as $line) {
                    if (! isset($keeper[$line['id']]) || ($this->isOn($line) && ! $keeper[$line['id']]['on'])) {
                        $keeper[$line['id']] = ['bot' => (int) $bot->id, 'on' => $this->isOn($line)];
                    }
                }
            }
            foreach ($bots as $bot) {
                $fresh = $bot->fresh() ?? $bot;
                $extra = [];
                foreach ($this->lines($fresh) as $line) {
                    if (($keeper[$line['id']]['bot'] ?? (int) $bot->id) !== (int) $bot->id) {
                        $extra[] = $line['id'];
                    }
                }
                if ($extra !== []) {
                    $removed += count($this->drop($fresh, $extra));
                }
            }
        }

        return $removed;
    }

    /**
     * @return array{id: string, url: string, status: string, reason: ?string, changed_at: ?string, active: ?bool}|null
     */
    public function lineById(ChatbotInstance $instance, string $lineId): ?array
    {
        $lineId = trim($lineId);
        if ($lineId === '') {
            return null;
        }
        foreach ($this->lines($instance) as $line) {
            if ($line['id'] === $lineId) {
                return $line;
            }
        }

        return null;
    }

    /**
     * The line a webhook belongs to. A missing id is accepted only when the bot has one line.
     */
    public function inboundLineId(ChatbotInstance $instance, ?string $explicit): ?string
    {
        $explicit = trim((string) $explicit);
        $lines = $this->lines($instance);
        if ($explicit !== '') {
            foreach ($lines as $line) {
                if ($line['id'] === $explicit) {
                    return $explicit;
                }
            }

            return null;
        }
        if (count($lines) === 1) {
            return $lines[0]['id'];
        }

        return null;
    }

    public function primaryId(ChatbotInstance $instance): ?string
    {
        return $this->idOfLive($instance) ?? ($this->lines($instance)[0]['id'] ?? null);
    }

    /**
     * The chat this phone has on this line. An untagged older chat belongs only to the primary line.
     */
    public function conversationOnLine(ChatbotInstance $instance, string $chatId, string $lineId): ?ChatbotConversation
    {
        $base = ChatbotConversation::query()
            ->forExternalChat($instance->id, ChatbotConversation::CHANNEL_WHATSAPP, $chatId);

        $tagged = (clone $base)->where('metadata->mansour_line', $lineId)->first();
        if ($tagged !== null) {
            return $tagged;
        }
        if ($lineId !== $this->primaryId($instance)) {
            return null;
        }

        return (clone $base)->where(function ($inner): void {
            $inner->whereNull('metadata->mansour_line')
                ->orWhere('metadata->mansour_line', '');
        })->first();
    }

    /**
     * Replace the list from the settings form. Known lines keep their status.
     *
     * @param  list<string>  $urls
     */
    /**
     * @param  list<string>|null  $activeIds  null keeps each line's current switch
     */
    public function sync(ChatbotInstance $instance, array $urls, ?string $liveId = null, ?array $activeIds = null): void
    {
        $known = [];
        foreach ($this->lines($instance) as $line) {
            $known[$line['id']] = $line;
        }

        $lines = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $id = $this->idFor($url);
            if (isset($lines[$id])) {
                continue;
            }
            $line = $known[$id] ?? [
                'id' => $id,
                'url' => $url,
                'status' => self::READY,
                'reason' => null,
                'changed_at' => null,
                'name' => '',
                'phone' => '',
                'avatar' => '',
                'profile_at' => null,
                'webhook_at' => null,
                'active' => null,
            ];
            $line['url'] = $url;
            $lines[$id] = $line;
        }

        if ($liveId !== null && isset($lines[$liveId])) {
            foreach ($lines as $id => $line) {
                if ($line['status'] === self::LIVE) {
                    $lines[$id]['status'] = self::READY;
                }
            }
            $lines[$liveId] = array_merge($lines[$liveId], ['status' => self::LIVE, 'reason' => null, 'changed_at' => now()->toIso8601String()]);
        }

        if ($activeIds !== null) {
            $activeIds = array_map('strval', $activeIds);
            foreach ($lines as $id => $line) {
                $lines[$id]['active'] = ($line['status'] ?? '') !== self::BLOCKED
                    && (in_array((string) $id, $activeIds, true) || ! isset($known[$id]));
            }
        }

        $this->store($instance, array_values($lines));
        $claimed = [];
        $repoint = [];
        foreach ($lines as $id => $line) {
            $id = (string) $id;
            $isNew = ! isset($known[$id]);
            if ($isNew || $this->isOn($line)) {
                $claimed[] = $id;
            }
            if ($isNew || ($this->isOn($line) && ! $this->isOn($known[$id]))) {
                $repoint[] = $id;
            }
        }
        $this->claim($instance, $claimed, $repoint);
    }

    public function refreshProfiles(ChatbotInstance $instance, bool $force = false): void
    {
        $lines = $this->lines($instance);
        $due = [];
        foreach ($lines as $line) {
            $stamped = $line['profile_at'] ?? null;
            if (! $force && is_string($stamped) && $stamped !== '' && now()->parse($stamped)->gt(now()->subHours(12)) && ($line['name'] !== '' || $line['avatar'] !== '')) {
                continue;
            }
            $due[] = $line;
        }
        $profiles = $this->profiles($due);
        if ($profiles === []) {
            return;
        }

        $fresh = $instance->fresh() ?? $instance;
        $this->store($fresh, $this->withProfiles($this->lines($fresh), $profiles));
    }

    /**
     * @return array{name: string, phone: string, avatar: string}|null
     */
    public function profileFor(string $url): ?array
    {
        $profile = $this->profiles([['id' => $this->idFor($url), 'url' => $url]])[$this->idFor($url)] ?? null;
        if ($profile === null) {
            return null;
        }

        return [
            'name' => $profile['name'],
            'phone' => $profile['phone'],
            'avatar' => $profile['avatar'],
        ];
    }

    /**
     * WhatsApp state, phone, name and photo of many lines in two parallel rounds,
     * so a bot with dozens of numbers answers in seconds instead of minutes.
     *
     * @param  list<array{id: string, url: string}>  $lines
     * @return array<string, array{state: string, name: string, phone: string, avatar: string}>
     */
    private function profiles(array $lines): array
    {
        $bases = [];
        foreach ($lines as $line) {
            $base = $this->apiBase((string) $line['url']);
            if ($base !== null) {
                $bases[(string) $line['id']] = $base;
            }
        }
        if ($bases === []) {
            return [];
        }

        $settings = $this->parallel($bases, static fn ($request, array $base) => $request
            ->get($base['root'].'/getWaSettings/'.$base['token']));

        $out = [];
        $contacts = [];
        foreach ($settings as $id => $response) {
            $body = $response->json();
            if (! is_array($body)) {
                continue;
            }
            $phone = preg_replace('/\D+/', '', (string) ($body['phone'] ?? '')) ?? '';
            $out[$id] = [
                'state' => is_string($body['stateInstance'] ?? null) ? $body['stateInstance'] : '',
                'name' => '',
                'phone' => $phone,
                'avatar_url' => (string) ($body['avatar'] ?? ''),
                'avatar64' => (string) ($body['base64Avatar'] ?? ''),
            ];
            if ($phone !== '') {
                $contacts[$id] = $bases[$id] + ['phone' => $phone];
            }
        }

        $infos = $this->parallel($contacts, static fn ($request, array $base) => $request
            ->post($base['root'].'/getContactInfo/'.$base['token'], ['chatId' => $base['phone'].'@c.us']));
        foreach ($infos as $id => $info) {
            $out[$id]['name'] = $this->profileName((string) $info->json('name'));
            $contactAvatar = (string) $info->json('base64Avatar');
            if (strlen($contactAvatar) > strlen($out[$id]['avatar64'])) {
                $out[$id]['avatar64'] = $contactAvatar;
            }
        }

        return array_map(fn (array $row): array => [
            'state' => $row['state'],
            'name' => $row['name'],
            'phone' => $row['phone'],
            'avatar' => $this->avatarSrc($row['avatar_url'], $row['avatar64']),
        ], $out);
    }

    /**
     * Fire one Green API request per line at once. Only successful answers come back.
     *
     * @param  array<string, array<string, string>>  $bases
     * @param  callable(\Illuminate\Http\Client\PendingRequest, array<string, string>): mixed  $send
     * @return array<string, \Illuminate\Http\Client\Response>
     */
    private function parallel(array $bases, callable $send): array
    {
        if ($bases === []) {
            return [];
        }
        try {
            $responses = Http::pool(function ($pool) use ($bases, $send): void {
                foreach ($bases as $id => $base) {
                    $send($pool->as((string) $id)->timeout(8)->connectTimeout(3)->acceptJson(), $base);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Green API parallel request failed', ['error' => $e->getMessage()]);

            return [];
        }

        $ok = [];
        foreach (array_keys($bases) as $id) {
            $response = $responses[(string) $id] ?? null;
            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                $ok[(string) $id] = $response;
            }
        }

        return $ok;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array{state: string, name: string, phone: string, avatar: string}>  $profiles
     * @return list<array<string, mixed>>
     */
    private function withProfiles(array $lines, array $profiles): array
    {
        foreach ($lines as $i => $line) {
            $profile = $profiles[$line['id']] ?? null;
            if ($profile === null) {
                continue;
            }
            $patch = ['avatar' => $profile['avatar'], 'profile_at' => now()->toIso8601String()];
            if ($profile['phone'] !== '') {
                $patch['phone'] = $profile['phone'];
            }
            if ($profile['name'] !== '') {
                $patch['name'] = $profile['name'];
            }
            $lines[$i] = array_merge($line, $patch);
        }

        return $lines;
    }

    /**
     * Send through this conversation's own line. A failure stays on that line.
     *
     * @return array{status: int, body: mixed}
     */
    public function send(ChatbotInstance $instance, ?ChatbotConversation $conversation, string $chatId, string $message, ?int $typingMs = null): array
    {
        if (! app()->runningUnitTests() && ! app(WhapiCloudClient::class)->usesWhapi()) {
            $this->greenApi->ensureOutgoingPhoneWebhook($instance);
        }

        $url = $this->urlFor($instance, $conversation, true);
        if (! app(WhapiCloudClient::class)->transportReady($url)) {
            return ['status' => 0, 'body' => 'missing_greenapi_url'];
        }

        $result = $this->greenApi->sendMessage($url, $chatId, $message, $typingMs);
        if (! $this->succeeded($result) && ! app(WhapiCloudClient::class)->usesWhapi()) {
            $this->reportFailure($instance, $url, $result);
        }

        return $result;
    }

    /**
     * The conversation's own line. Automatic sends also require the line to be on.
     */
    public function canDeliver(ChatbotInstance $instance, ?ChatbotConversation $conversation = null): bool
    {
        return app(WhapiCloudClient::class)->transportReady($this->urlFor($instance, $conversation, true));
    }

    public function urlFor(ChatbotInstance $instance, ?ChatbotConversation $conversation = null, bool $requireActive = false): string
    {
        $meta = $conversation !== null && is_array($conversation->metadata) ? $conversation->metadata : [];
        $wanted = trim((string) ($meta['mansour_line'] ?? ''));
        if ($wanted === '') {
            $lines = $this->lines($instance);
            $wanted = count($lines) === 1 ? $lines[0]['id'] : (string) $this->idOfLive($instance);
        }
        $line = $wanted !== '' ? $this->lineById($instance, $wanted) : null;
        if ($line === null || $line['status'] === self::BLOCKED) {
            return '';
        }
        if ($requireActive && ! $this->isOn($line)) {
            return '';
        }

        return $line['url'];
    }

    public function useLine(ChatbotConversation $conversation, ?string $lineId): void
    {
        if ($lineId === null || $lineId === '') {
            return;
        }
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        if (($meta['mansour_line'] ?? null) === $lineId) {
            return;
        }
        $meta['mansour_line'] = $lineId;
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    /**
     * Remember the inbound line on the conversation when it is one of ours and usable.
     */
    public function rememberInbound(ChatbotInstance $instance, ChatbotConversation $conversation, ?string $idInstance): void
    {
        $idInstance = trim((string) $idInstance);
        if ($idInstance === '') {
            return;
        }
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $current = trim((string) ($meta['mansour_line'] ?? ''));
        if ($current !== '' && $current !== $idInstance) {
            return;
        }
        foreach ($this->lines($instance) as $line) {
            if ($line['id'] === $idInstance && $line['status'] !== self::BLOCKED) {
                $this->useLine($conversation, $idInstance);

                return;
            }
        }
    }

    public function idOfLive(ChatbotInstance $instance): ?string
    {
        foreach ($this->lines($instance) as $line) {
            if ($line['status'] === self::LIVE) {
                return $line['id'];
            }
        }

        return null;
    }

    /**
     * Triggered chats on each line, and how close that line is to a restriction.
     *
     * @return array<string, array{triggered: int, today: int, cap: int, percent: int, level: string, level_label: string}>
     */
    public function usage(ChatbotInstance $instance): array
    {
        $lines = $this->lines($instance);
        $stats = [];
        foreach ($lines as $line) {
            $stats[$line['id']] = [
                'triggered' => 0,
                'today' => 0,
                'cap' => self::DAY_CAP,
                'percent' => 0,
                'level' => 'low',
                'level_label' => self::RISK_LABELS['low'],
            ];
        }
        if ($stats === []) {
            return $stats;
        }

        $start = now('Asia/Jerusalem')->startOfDay();
        $primary = $this->primaryId($instance);
        $contacts = MansourContact::query()
            ->where('instance_id', $instance->id)
            ->where(function ($query): void {
                $query->whereNotNull('triggered_at')->orWhereNotNull('triggered_lines');
            })
            ->get(['id', 'triggered_at', 'triggered_lines', 'conversation_id']);

        $legacy = [];
        foreach ($contacts as $contact) {
            $map = is_array($contact->triggered_lines) ? $contact->triggered_lines : [];
            if ($map !== []) {
                foreach ($map as $lineId => $at) {
                    $lineId = (string) $lineId;
                    if (! isset($stats[$lineId]) || ! $at) {
                        continue;
                    }
                    $stats[$lineId]['triggered']++;
                    $when = $this->moment($at);
                    if ($when !== null && $when->greaterThanOrEqualTo($start)) {
                        $stats[$lineId]['today']++;
                    }
                }

                continue;
            }
            if ($contact->triggered_at !== null) {
                $legacy[] = $contact;
            }
        }

        if ($legacy !== []) {
            $conversationIds = array_values(array_filter(array_map(
                static fn (MansourContact $contact): ?int => $contact->conversation_id !== null ? (int) $contact->conversation_id : null,
                $legacy,
            )));
            $conversations = $conversationIds === []
                ? collect()
                : ChatbotConversation::query()->whereIn('id', $conversationIds)->get(['id', 'metadata'])->keyBy('id');
            foreach ($legacy as $contact) {
                $contact->setRelation(
                    'conversation',
                    $contact->conversation_id !== null ? $conversations->get($contact->conversation_id) : null,
                );
                foreach (array_keys($stats) as $lineId) {
                    $lineId = (string) $lineId;
                    if (! $contact->sentOnLine($lineId, $primary)) {
                        continue;
                    }
                    $stats[$lineId]['triggered']++;
                    if ($contact->triggered_at->greaterThanOrEqualTo($start)) {
                        $stats[$lineId]['today']++;
                    }
                }
            }
        }

        foreach ($stats as $id => $row) {
            $level = $this->riskLevel($row['today']);
            $stats[$id]['level'] = $level;
            $stats[$id]['level_label'] = self::RISK_LABELS[$level];
            $stats[$id]['cap'] = self::DAY_CAP;
            $stats[$id]['percent'] = $this->dangerPercent($row['today']);
        }

        return $stats;
    }

    /**
     * How full today's first-day budget is. Zero chats today is 0%, even if
     * yesterday was full, so the number cools down on the next day.
     */
    public function dangerPercent(int $today): int
    {
        if ($today <= 0) {
            return 0;
        }

        return (int) round($today / self::DAY_CAP * 100);
    }

    public function riskLevel(int $today): string
    {
        return match (true) {
            $today >= self::RISK_CRITICAL => 'critical',
            $today >= self::RISK_HIGH => 'high',
            $today >= self::RISK_MEDIUM => 'medium',
            default => 'low',
        };
    }

    /**
     * Ask Green API for each line's state. Repeated checks wait 12 seconds, unless forced.
     */
    public function refreshStatesIfDue(ChatbotInstance $instance, bool $force = false): void
    {
        if (app()->runningUnitTests() || app(WhapiCloudClient::class)->usesWhapi()) {
            return;
        }
        $key = 'mansour-line-state:'.$instance->id;
        if ($force) {
            Cache::put($key, 1, now()->addSeconds(12));
        } elseif (! Cache::add($key, 1, now()->addSeconds(12))) {
            return;
        }
        try {
            $this->refreshStates($instance);
        } catch (Throwable $e) {
            Log::warning('Mansour line state check failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function refreshStates(ChatbotInstance $instance): void
    {
        if (app(WhapiCloudClient::class)->usesWhapi()) {
            return;
        }
        $requests = [];
        foreach ($this->lines($instance) as $line) {
            $base = $this->apiBase($line['url']);
            if ($base !== null) {
                $requests[$line['id']] = $base;
            }
        }
        if ($requests === []) {
            return;
        }

        $responses = Http::pool(function ($pool) use ($requests): void {
            foreach ($requests as $id => $base) {
                $pool->as((string) $id)->timeout(6)->connectTimeout(2)->acceptJson()
                    ->get($base['root'].'/getWaSettings/'.$base['token']);
            }
        });
        if (! is_array($responses)) {
            return;
        }

        foreach ($requests as $id => $base) {
            $response = $responses[(string) $id] ?? null;
            if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
                continue;
            }
            $id = (string) $id;
            $state = $response->json('stateInstance');
            if (is_string($state) && $state !== '') {
                $this->stateChanged($instance->fresh() ?? $instance, $id, $state);
            }
            $phone = preg_replace('/\D+/', '', (string) $response->json('phone')) ?? '';
            if ($phone !== '') {
                $this->rememberPhone($instance->fresh() ?? $instance, $id, $phone);
            }
        }
    }

    public function rememberPhone(ChatbotInstance $instance, string $lineId, string $phone): void
    {
        $lines = $this->lines($instance);
        foreach ($lines as $i => $line) {
            if ($line['id'] !== $lineId) {
                continue;
            }
            $current = preg_replace('/\D+/', '', (string) ($line['phone'] ?? '')) ?? '';
            if ($current === $phone) {
                return;
            }
            $lines[$i]['phone'] = $phone;
            $lines[$i]['profile_at'] = now()->toIso8601String();
            $this->store($instance, $lines, false);

            return;
        }
    }

    /**
     * Lines as the chats page shows them. The send URL stays off this list.
     *
     * @return list<array{id: string, status: string, name: string, phone: string, label: string, avatar: string, triggered: int, today: int, level: string, level_label: string, alert: ?string}>
     */
    public function choices(ChatbotInstance $instance): array
    {
        $usage = app()->runningUnitTests()
            ? $this->usage($instance)
            : Cache::remember(
                'mansour-line-usage:'.$instance->id,
                now()->addSeconds(15),
                fn (): array => $this->usage($instance),
            );

        return array_map(function (array $line) use ($usage): array {
            $row = $this->present($line);
            $stat = $usage[$line['id']] ?? [
                'triggered' => 0,
                'today' => 0,
                'cap' => self::DAY_CAP,
                'percent' => 0,
                'level' => 'low',
                'level_label' => self::RISK_LABELS['low'],
            ];
            $reason = isset($line['reason']) ? (string) $line['reason'] : '';
            $alert = $line['status'] === self::BLOCKED ? (self::ALERT_LABELS[$reason] ?? null) : null;
            if ($line['status'] === self::BLOCKED) {
                $stat['level'] = 'dead';
                $stat['level_label'] = $alert ?? ($reason === 'quota' ? 'انتهى الرصيد' : 'متوقف');
            }

            return $row + $stat + ['alert' => $alert];
        }, $this->lines($instance));
    }

    /**
     * The line open on the chats page. An unknown id falls back to the live line.
     *
     * @return array{id: string, status: string, name: string, phone: string, label: string, avatar: string}|null
     */
    public function selected(ChatbotInstance $instance, ?string $requested): ?array
    {
        $choices = $this->choices($instance);
        if ($choices === []) {
            return null;
        }

        $requested = trim((string) $requested);
        foreach ($choices as $line) {
            if ($requested !== '' && $line['id'] === $requested) {
                return $line;
            }
        }

        foreach ($choices as $line) {
            if ($line['status'] === self::LIVE) {
                return $line;
            }
        }

        return $choices[0];
    }

    /**
     * @param  array{id: string, status: string, name?: string, phone?: string, avatar?: string}  $line
     * @return array{id: string, status: string, name: string, phone: string, label: string, avatar: string}
     */
    private function present(array $line): array
    {
        $phone = $this->localPhone((string) ($line['phone'] ?? ''));
        $name = trim((string) ($line['name'] ?? ''));
        $label = $name !== '' ? $name : ($phone !== '' ? $phone : '#'.$line['id']);

        return [
            'id' => $line['id'],
            'status' => $line['status'],
            'name' => $name,
            'phone' => $phone,
            'label' => $label,
            'avatar' => (string) ($line['avatar'] ?? ''),
            'active' => $this->isOn($line),
        ];
    }

    private function moment(mixed $at): ?Carbon
    {
        if ($at instanceof \DateTimeInterface) {
            return Carbon::parse($at);
        }
        if (! is_string($at) || trim($at) === '') {
            return null;
        }
        try {
            return Carbon::parse($at);
        } catch (Throwable) {
            return null;
        }
    }

    private function localPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '972') && strlen($digits) >= 11) {
            return '0'.substr($digits, 3);
        }

        return $digits;
    }

    /**
     * A send failed: find out whether the line is dead and, if so, move on.
     * Returns the new live URL when the failed line was replaced.
     *
     * @param  array{status: int, body: mixed}  $result
     */
    public function reportFailure(ChatbotInstance $instance, string $url, array $result): ?string
    {
        $reason = (int) ($result['status'] ?? 0) === self::QUOTA_STATUS ? 'quota' : $this->deadState($url);
        if ($reason === null) {
            return null;
        }

        return $this->markBlocked($instance, $this->idFor($url), $reason);
    }

    /**
     * Green API stateInstanceChanged webhook.
     */
    public function stateChanged(ChatbotInstance $instance, string $idInstance, string $state): void
    {
        if (in_array($state, self::DEAD_STATES, true)) {
            $this->markBlocked($instance, $idInstance, $state);

            return;
        }
        if ($state === 'authorized') {
            $this->markHealthy($instance, $idInstance);
        }
    }

    /**
     * The settings "تحديث" button: ask Green API for every line's state, phone, name and photo,
     * then point each line's webhook back at this bot. All lines are asked at once.
     *
     * @return array{checked: int, blocked: int}
     */
    public function checkAll(ChatbotInstance $instance): array
    {
        if (app(WhapiCloudClient::class)->usesWhapi()) {
            return ['checked' => 0, 'blocked' => 0];
        }

        $profiles = $this->profiles($this->lines($instance));
        foreach ($profiles as $id => $profile) {
            if ($profile['state'] !== '') {
                $this->stateChanged($instance->fresh() ?? $instance, (string) $id, $profile['state']);
            }
        }

        $fresh = $instance->fresh() ?? $instance;
        $lines = $this->withProfiles($this->lines($fresh), $profiles);
        if ($profiles !== []) {
            $this->store($fresh, $lines);
        }
        if (! app()->runningUnitTests()) {
            $this->pointWebhooks($fresh->fresh() ?? $fresh, array_column($lines, 'url'), true);
        }

        $blocked = 0;
        foreach ($this->lines($instance->fresh() ?? $instance) as $line) {
            if ($line['status'] === self::BLOCKED) {
                $blocked++;
            }
        }

        return ['checked' => count($profiles), 'blocked' => $blocked];
    }

    /**
     * @return string|null new live URL when the blocked line was the live one
     */
    public function markBlocked(ChatbotInstance $instance, string $lineId, string $reason): ?string
    {
        $lines = $this->lines($instance);
        $found = false;
        foreach ($lines as $i => $line) {
            if ($line['id'] !== $lineId) {
                continue;
            }
            $found = true;
            if ($line['status'] === self::BLOCKED && $line['reason'] === $reason) {
                return null;
            }
            $lines[$i] = array_merge($line, [
                'status' => self::BLOCKED,
                'active' => false,
                'reason' => $reason,
                'changed_at' => now()->toIso8601String(),
            ]);
        }
        if (! $found) {
            return null;
        }

        $this->store($instance, $lines, false);
        Log::warning('Mansour line blocked', ['instance_id' => $instance->id, 'line' => $lineId, 'reason' => $reason]);

        return null;
    }

    private function markHealthy(ChatbotInstance $instance, string $lineId): void
    {
        $lines = $this->lines($instance);
        $changed = false;
        foreach ($lines as $i => $line) {
            if ($line['id'] === $lineId && $line['status'] === self::BLOCKED) {
                $lines[$i] = array_merge($line, [
                    'status' => self::READY,
                    'active' => false,
                    'reason' => null,
                    'changed_at' => now()->toIso8601String(),
                ]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->store($instance, $lines, false);
        }
    }

    /**
     * @param  list<array{id: string, url: string, status: string, reason: ?string, changed_at: ?string}>  $lines
     */
    private function store(ChatbotInstance $instance, array $lines, bool $promote = true): void
    {
        $hasLive = false;
        foreach ($lines as $i => $line) {
            if ($line['status'] !== self::LIVE) {
                continue;
            }
            if ($hasLive) {
                $lines[$i]['status'] = self::READY;
            }
            $hasLive = true;
        }
        if ($promote && ! $hasLive) {
            foreach ($lines as $i => $line) {
                if ($line['status'] === self::READY) {
                    $lines[$i]['status'] = self::LIVE;
                    $hasLive = true;
                    break;
                }
            }
        }

        $live = null;
        foreach ($lines as $line) {
            if ($this->isOn($line)) {
                $live = $line['url'];
                break;
            }
        }
        if ($live === null) {
            foreach ($lines as $line) {
                if ($line['status'] === self::LIVE) {
                    $live = $line['url'];
                    break;
                }
            }
        }

        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $settings['greenapi_lines'] = array_map(
            static function (array $line): array {
                $row = [
                    'url' => $line['url'],
                    'status' => $line['status'],
                    'reason' => $line['reason'],
                    'changed_at' => $line['changed_at'],
                    'name' => (string) ($line['name'] ?? ''),
                    'phone' => (string) ($line['phone'] ?? ''),
                    'avatar' => (string) ($line['avatar'] ?? ''),
                    'profile_at' => $line['profile_at'] ?? null,
                    'webhook_at' => $line['webhook_at'] ?? null,
                ];
                if (array_key_exists('active', $line) && $line['active'] !== null) {
                    $row['active'] = (bool) $line['active'];
                }

                return $row;
            },
            array_values($lines),
        );

        $instance->forceFill([
            'integration_settings' => $settings,
            'greenapi_url' => $live,
        ])->save();
    }

    private function deadState(string $url): ?string
    {
        $state = $this->state($url);

        return $state !== null && in_array($state, self::DEAD_STATES, true) ? $state : null;
    }

    private function state(string $url): ?string
    {
        if (app(WhapiCloudClient::class)->usesWhapi()) {
            return 'authorized';
        }

        $base = $this->apiBase($url);
        if ($base === null) {
            return null;
        }
        try {
            $response = Http::timeout(6)->acceptJson()->get($base['root'].'/getStateInstance/'.$base['token']);
            $state = $response->successful() ? $response->json('stateInstance') : null;

            return is_string($state) && $state !== '' ? $state : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Write this bot's webhook back onto every Green API line it uses.
     *
     * @return list<array{id: string, phone: string, ok: bool}>
     */
    public function restoreWebhooks(ChatbotInstance $instance): array
    {
        $rows = [];
        foreach ($this->lines($instance) as $line) {
            $this->pointWebhookAtBot($instance, $line['url'], true);
            $expected = $this->greenApi->webhookUrlForLine($instance, $line['id']);
            $hook = '';
            $base = $this->apiBase($line['url']);
            if ($base !== null) {
                try {
                    $current = Http::timeout(12)->acceptJson()->get($base['root'].'/getSettings/'.$base['token']);
                    $settings = $current->successful() && is_array($current->json()) ? $current->json() : [];
                    $hook = trim((string) ($settings['webhookUrl'] ?? ''));
                } catch (Throwable) {
                    $hook = '';
                }
            }
            $rows[] = [
                'id' => $line['id'],
                'phone' => $line['phone'],
                'ok' => $hook === $expected,
            ];
        }

        return $rows;
    }

    private function pointWebhookAtBot(ChatbotInstance $instance, string $url, bool $enableEvents): void
    {
        $this->pointWebhooks($instance, [$url], $enableEvents);
    }

    /**
     * Point several Green API lines at this bot's webhooks in one parallel round.
     *
     * @param  list<string>  $urls
     */
    private function pointWebhooks(ChatbotInstance $instance, array $urls, bool $enableEvents): void
    {
        $bases = [];
        foreach ($urls as $url) {
            $base = $this->apiBase((string) $url);
            if ($base === null) {
                continue;
            }
            $id = $this->idFor((string) $url);
            $base['webhook'] = $this->greenApi->webhookUrlForLine($instance, $id);
            $bases[$id] = $base;
        }

        $done = $this->parallel($bases, static function ($request, array $base) use ($enableEvents) {
            $payload = ['webhookUrl' => $base['webhook']];
            if ($enableEvents) {
                $payload['incomingWebhook'] = 'yes';
                $payload['outgoingMessageWebhook'] = 'yes';
                $payload['stateWebhook'] = 'yes';
            }

            return $request->post($base['root'].'/setSettings/'.$base['token'], $payload);
        });
        if ($done === []) {
            if ($bases !== []) {
                Log::warning('Could not point Green API lines at the Mansour webhook', ['instance_id' => $instance->id]);
            }

            return;
        }

        $fresh = $instance->fresh() ?? $instance;
        $lines = $this->lines($fresh);
        foreach ($lines as $i => $line) {
            if (isset($done[$line['id']])) {
                $lines[$i]['webhook_at'] = now()->toIso8601String();
            }
        }
        $this->store($fresh, $lines);
    }

    private function profileName(string $name): string
    {
        $name = trim($name);
        $name = ltrim($name, "~ \t");

        return trim($name);
    }

    private function avatarSrc(string $url, string $base64): string
    {
        $base64 = preg_replace('/\s+/', '', $base64) ?? '';
        if ($base64 !== '' && strlen($base64) <= 60000 && preg_match('/^[A-Za-z0-9+\/=]+$/', $base64) === 1) {
            return 'data:image/jpeg;base64,'.$base64;
        }
        if (str_starts_with($url, 'https://')) {
            return $url;
        }

        return '';
    }

    /**
     * @return array{root: string, token: string}|null
     */
    private function apiBase(string $url): ?array
    {
        if (! preg_match('~^(https://[^/\s]+/waInstance\d+)/[A-Za-z]+/([^/\s?]+)~i', trim($url), $m)) {
            return null;
        }

        return ['root' => $m[1], 'token' => $m[2]];
    }

    public function idFor(string $url): string
    {
        return $this->greenApi->greenApiInstanceIdFromUrl($url) ?? substr(sha1(trim($url)), 0, 12);
    }

    /**
     * @param  array{status: int, body: mixed}  $result
     */
    private function succeeded(array $result): bool
    {
        $status = (int) ($result['status'] ?? 0);

        return $status >= 200 && $status < 300;
    }
}
