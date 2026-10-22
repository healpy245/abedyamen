<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\MansourContact;
use App\Models\AiChatbot\MowahadaLine;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared Green API numbers for the Mowahada bots.
 *
 * The catalog is the place numbers are added. Each bot keeps its own
 * greenapi_lines list, which is the set switched on for that bot.
 */
class MowahadaLinePool
{
    public function __construct(protected MansourLines $lines) {}

    public function appliesTo(ChatbotInstance $instance): bool
    {
        return $instance->hasMansourIntegration()
            && (bool) $instance->user?->isMowahada();
    }

    /**
     * @return Collection<int, ChatbotInstance>
     */
    public function mowahadaBots(): Collection
    {
        $owner = User::query()->where('email', User::MOWAHADA_EMAIL)->first();
        if (! $owner instanceof User) {
            return collect();
        }

        return ChatbotInstance::query()
            ->where('user_id', $owner->id)
            ->where('integration_type', 'mansour')
            ->orderBy('name')
            ->get();
    }

    /**
     * Copy the numbers already saved on Mowahada bots into the catalog.
     * Does not change those bots.
     */
    public function importFromBots(): void
    {
        foreach ($this->mowahadaBots() as $bot) {
            foreach ($this->lines->lines($bot) as $line) {
                $id = (string) ($line['id'] ?? '');
                $url = trim((string) ($line['url'] ?? ''));
                if ($id === '' || $url === '' || MowahadaLine::query()->where('wa_instance', $id)->exists()) {
                    continue;
                }
                $phone = trim((string) ($line['phone'] ?? ''));
                $name = trim((string) ($line['name'] ?? ''));
                MowahadaLine::query()->create([
                    'wa_instance' => $id,
                    'url' => $url,
                    'phone' => $phone !== '' ? $phone : null,
                    'name' => $name !== '' ? mb_substr($name, 0, 120) : null,
                    'status' => (string) ($line['status'] ?: MansourLines::READY),
                    'reason' => $line['reason'] ?? null,
                    'changed_at' => $line['changed_at'] ?? null,
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $raw
     * @return array{0: list<string>, 1: list<string>}
     */
    public function extract(array $raw): array
    {
        $urls = [];
        $bad = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;
            if ($value === '') {
                continue;
            }
            $matched = preg_match_all('#(?:https?://)?[^\s<>"\']*waInstance\d+/[^\s<>"\']+#iu', $value, $matches);
            if ($matched !== false && $matched > 0) {
                foreach ($matches[0] as $found) {
                    $url = rtrim($found, ".,);'\"»«");
                    if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                        $url = 'https://'.$url;
                    }
                    $urls[] = $url;
                }
                continue;
            }
            $bad[] = $value;
        }

        return [$urls, $bad];
    }

    /**
     * Build the send URL Green API expects from the two values on the instance page.
     * The host is the first four digits of idInstance, the same apiUrl the console shows.
     */
    public function sendUrl(string $id, string $token): ?string
    {
        $id = trim($id);
        $token = trim($token);
        $token = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $token) ?? $token;
        if (preg_match('#waInstance(\d+)/[A-Za-z]+/([A-Za-z0-9]+)#i', $id.' '.$token, $found) === 1) {
            $id = $found[1];
            $token = $found[2];
        } else {
            $id = preg_replace('/\D+/', '', $id) ?? '';
        }
        if (preg_match('/^\d{8,20}$/', $id) !== 1 || preg_match('/^[A-Za-z0-9]{20,80}$/', $token) !== 1) {
            return null;
        }

        return 'https://'.substr($id, 0, 4).'.api.greenapi.com/waInstance'.$id.'/sendMessage/'.$token;
    }

    /**
     * Check a new token against Green API, then store that line.
     * Lines already in the catalog are not touched unless this same instance is submitted.
     *
     * @param  list<string>  $urls
     * @return string|null translation key when the lines must not be saved
     */
    public function connect(array $urls): ?string
    {
        $urls = array_values(array_filter(array_map(static fn (string $url): string => trim($url), $urls)));
        if ($urls === []) {
            return 'chatbot.mansour.line_token_invalid';
        }

        $seen = [];
        foreach ($urls as $url) {
            $id = $this->lines->idFor($url);
            if (isset($seen[$id])) {
                return 'chatbot.mansour.line_duplicate';
            }
            $seen[$id] = true;
        }

        $probes = $this->probe($urls);
        if ($probes === null) {
            return 'chatbot.mansour.line_token_rejected';
        }

        $this->remember($urls);
        foreach ($probes as $id => $info) {
            $row = MowahadaLine::query()->where('wa_instance', (string) $id)->first();
            if (! $row instanceof MowahadaLine) {
                continue;
            }
            $this->observe($row, $info['state'], $info['phone'], $info['until']);
        }

        return null;
    }

    public function tokenOf(string $url): string
    {
        return $this->apiBase($url)['token'] ?? '';
    }

    /**
     * Arabic label for a one-day (or longer) Green API suspension, including when it ends.
     */
    public function holdLabel(MowahadaLine $line): ?string
    {
        if (! in_array((string) $line->reason, ['suspended', 'yellowCard'], true)) {
            return null;
        }
        $until = (int) $line->suspended_until;
        if ($until <= time()) {
            return (string) $line->reason === 'yellowCard' ? 'إنذار من واتساب' : 'موقوف';
        }
        $when = Carbon::createFromTimestamp($until, 'Asia/Jerusalem')->format('d/m H:i');
        $hours = (int) max(1, ceil(($until - time()) / 3600));
        if ($hours <= 30) {
            return 'موقوف ليوم · حتى '.$when;
        }

        return 'موقوف حتى '.$when.' · باقي '.$hours.' س';
    }

    /**
     * Pull every line from Green API and return the badges for the pool page.
     *
     * @return array{summary: string, lines: list<array{id: string, phone: string, blocked: bool, badge: string}>}
     */
    public function live(): array
    {
        $this->refresh();
        $labels = [
            'blocked' => 'محظور',
            'yellowCard' => 'إنذار من واتساب',
            'suspended' => 'موقوف',
            'notAuthorized' => 'غير مسجّل',
        ];
        $lines = [];
        $day = 0;
        foreach (MowahadaLine::query()->orderBy('id')->get() as $line) {
            $hold = $this->holdLabel($line);
            if (is_string($hold) && str_contains($hold, 'ليوم')) {
                $day++;
            }
            $blocked = $line->status === MansourLines::BLOCKED;
            $lines[] = [
                'id' => (string) $line->wa_instance,
                'phone' => (string) ($line->phone ?? ''),
                'blocked' => $blocked,
                'badge' => $hold ?? ($blocked ? ($labels[(string) $line->reason] ?? 'محظور') : 'في المصدر'),
            ];
        }

        return [
            'summary' => $day > 0 ? 'موقوف ليوم: '.$day : 'لا يوجد إيقاف ليوم',
            'lines' => $lines,
        ];
    }

    private function observe(MowahadaLine $row, string $state, string $phone, ?int $until): void
    {
        if ($phone !== '') {
            $row->phone = $phone;
        }
        if (in_array($state, MansourLines::DEAD_STATES, true)) {
            $row->status = MansourLines::BLOCKED;
            $row->reason = $state;
            $row->suspended_until = in_array($state, ['suspended', 'yellowCard'], true) ? $until : null;
        } elseif ($state === 'authorized') {
            $row->status = MansourLines::READY;
            $row->reason = null;
            $row->suspended_until = null;
        }
        if ($state !== '') {
            $row->changed_at = now()->toIso8601String();
        }
        $row->save();
    }

    private function onHold(MowahadaLine $line): bool
    {
        if (! in_array((string) $line->reason, ['suspended', 'yellowCard'], true)) {
            return false;
        }
        $until = (int) $line->suspended_until;

        return $until === 0 || $until > time();
    }

    /**
     * Save a new instance id and API token for one catalog line, then pull its phone and state.
     *
     * @return string|null translation key when the line must stay as it was
     */
    public function revise(MowahadaLine $line, string $id, string $token): ?string
    {
        $url = $this->sendUrl($id, $token);
        if ($url === null) {
            return 'chatbot.mansour.line_token_invalid';
        }
        $newId = $this->lines->idFor($url);
        $oldId = (string) $line->wa_instance;
        if ($newId !== $oldId && MowahadaLine::query()->where('wa_instance', $newId)->exists()) {
            return 'chatbot.mansour.line_id_taken';
        }
        $probes = $this->probe([$url]);
        $info = is_array($probes) ? ($probes[$newId] ?? null) : null;
        if (! is_array($info)) {
            return 'chatbot.mansour.line_token_rejected';
        }

        $line->wa_instance = $newId;
        $line->url = $url;
        $this->observe($line, $info['state'], $info['phone'], $info['until']);
        $this->retarget($oldId, $url);
        if ($oldId !== $newId) {
            $this->renameLine($oldId, $newId);
        }

        foreach ($this->mowahadaBots() as $bot) {
            $ids = array_column($this->lines->lines($bot), 'id');
            if (! in_array($newId, $ids, true)) {
                continue;
            }
            $fresh = $bot->fresh() ?? $bot;
            if ($info['state'] !== '') {
                $this->lines->stateChanged($fresh, $newId, $info['state']);
                $fresh = $fresh->fresh() ?? $fresh;
            }
            if ($info['phone'] !== '') {
                $this->lines->rememberPhone($fresh, $newId, $info['phone']);
            }
        }

        return null;
    }

    /**
     * Send one typed message from the chosen lines to the typed phones.
     * The chatbot does not read or answer these messages.
     *
     * @param  list<string>  $ids
     * @return array{sent: int, failed: int}|string
     */
    public function warm(array $ids, string $phonesRaw, string $message): array|string
    {
        $message = trim($message);
        if ($message === '') {
            return 'chatbot.mansour.warm_message';
        }
        $phones = $this->warmPhones($phonesRaw);
        if (is_string($phones)) {
            return $phones;
        }
        $wanted = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $wanted[$id] = true;
            }
        }
        $lines = MowahadaLine::query()->whereIn('wa_instance', array_keys($wanted))->orderBy('id')->get();
        if ($lines->isEmpty()) {
            return 'chatbot.mansour.warm_lines';
        }
        if ($lines->count() * count($phones) > 40) {
            return 'chatbot.mansour.warm_many';
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($lines as $line) {
            if ($this->onHold($line)) {
                $skipped++;
                continue;
            }
            $url = trim((string) $line->url);
            if ($url === '') {
                $failed += count($phones);
                continue;
            }
            foreach ($phones as $phone) {
                try {
                    $response = Http::timeout(15)->acceptJson()->post($url, [
                        'chatId' => $phone.'@c.us',
                        'message' => $message,
                    ]);
                    if ($response->successful()) {
                        $sent++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable) {
                    $failed++;
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * @return list<string>|string
     */
    private function warmPhones(string $raw): array|string
    {
        $phones = [];
        $parts = preg_split('/[\s,;]+/u', $raw) ?: [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $digits = app(ChatbotGreenApiService::class)->normalizePhoneDigits($part);
            if (strlen($digits) < 10 || strlen($digits) > 15) {
                return 'chatbot.mansour.warm_phone';
            }
            $phones[$digits] = $digits;
        }
        if ($phones === []) {
            return 'chatbot.mansour.warm_phone';
        }
        if (count($phones) > 20) {
            return 'chatbot.mansour.warm_many';
        }

        return array_values($phones);
    }

    /**
     * @param  list<string>  $urls
     */
    public function remember(array $urls): void
    {
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $id = $this->lines->idFor($url);
            $row = MowahadaLine::query()->firstOrNew(['wa_instance' => $id]);
            $previous = $row->exists ? (string) $row->url : null;
            $row->url = $url;
            if (! $row->exists) {
                $row->status = MansourLines::READY;
            }
            $row->save();
            if ($previous !== null && $previous !== $url) {
                $this->replaceUrl($id, $url);
            }
        }
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function urlsFor(ChatbotInstance $bot, array $ids): array
    {
        $catalog = MowahadaLine::query()->whereIn('wa_instance', $ids)->get()->keyBy('wa_instance');
        $existing = collect($this->lines->lines($bot))->keyBy('id');
        $urls = [];
        $seen = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $url = $catalog->get($id)?->url ?? ($existing[$id]['url'] ?? null);
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $seen[$id] = true;
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string> id => send URL
     */
    public function catalogUrls(array $ids): array
    {
        return MowahadaLine::query()
            ->whereIn('wa_instance', array_map('strval', $ids))
            ->pluck('url', 'wa_instance')
            ->mapWithKeys(static fn ($url, $id): array => [(string) $id => (string) $url])
            ->all();
    }

    /**
     * The bot each number is on.
     *
     * @return array<string, ChatbotInstance>
     */
    public function owners(): array
    {
        $map = [];
        foreach ($this->mowahadaBots() as $bot) {
            foreach ($this->lines->lines($bot) as $line) {
                $map[(string) $line['id']] ??= $bot;
            }
        }

        return $map;
    }

    /**
     * Copy catalog phone and a dead state onto numbers just switched on.
     *
     * @param  list<string>  $newIds
     */
    public function applyCatalogState(ChatbotInstance $bot, array $newIds): void
    {
        if ($newIds === []) {
            return;
        }
        $fresh = $bot->fresh() ?? $bot;
        $rows = MowahadaLine::query()->whereIn('wa_instance', $newIds)->get()->keyBy('wa_instance');
        foreach ($newIds as $id) {
            $row = $rows->get((string) $id);
            if (! $row instanceof MowahadaLine) {
                continue;
            }
            if (is_string($row->phone) && $row->phone !== '') {
                $this->lines->rememberPhone($fresh, (string) $id, $row->phone);
                $fresh = $fresh->fresh() ?? $fresh;
            }
            if ($row->status === MansourLines::BLOCKED && is_string($row->reason) && $row->reason !== '') {
                $this->lines->stateChanged($fresh, (string) $id, $row->reason);
                $fresh = $fresh->fresh() ?? $fresh;
            }
        }
    }

    public function forget(MowahadaLine $line): void
    {
        $id = (string) $line->wa_instance;
        foreach ($this->mowahadaBots() as $bot) {
            $bot = $bot->fresh() ?? $bot;
            $current = $this->lines->lines($bot);
            $keep = array_values(array_filter(
                $current,
                static fn (array $item): bool => (string) ($item['id'] ?? '') !== $id,
            ));
            if (count($keep) === count($current)) {
                continue;
            }
            $active = [];
            foreach ($keep as $item) {
                if ($this->lines->isOn($item)) {
                    $active[] = (string) $item['id'];
                }
            }
            $this->lines->sync($bot, array_column($keep, 'url'), null, $active);
        }
        $line->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $botLines
     * @return list<array<string, mixed>>
     */
    public function rowsFor(ChatbotInstance $bot, array $botLines): array
    {
        $rows = [];
        foreach ($botLines as $line) {
            $id = (string) ($line['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $line['on_bot'] = true;
            $rows[$id] = $line;
        }
        foreach (MowahadaLine::query()->orderBy('id')->get() as $row) {
            $id = (string) $row->wa_instance;
            if (isset($rows[$id])) {
                continue;
            }
            $rows[$id] = [
                'id' => $id,
                'url' => (string) $row->url,
                'status' => (string) ($row->status ?: MansourLines::READY),
                'reason' => $row->reason,
                'changed_at' => $row->changed_at,
                'name' => (string) ($row->name ?? ''),
                'phone' => (string) ($row->phone ?? ''),
                'avatar' => '',
                'on_bot' => false,
                'active' => false,
            ];
        }

        return array_values($rows);
    }

    /**
     * @return array<string, list<string>>
     */
    public function usageExcept(ChatbotInstance $current): array
    {
        $map = [];
        foreach ($this->mowahadaBots() as $bot) {
            if ($bot->is($current)) {
                continue;
            }
            foreach ($this->lines->lines($bot) as $line) {
                $map[(string) $line['id']][] = (string) $bot->name;
            }
        }

        return $map;
    }

    /**
     * @return array<string, list<string>>
     */
    public function usage(): array
    {
        $map = [];
        foreach ($this->mowahadaBots() as $bot) {
            foreach ($this->lines->lines($bot) as $line) {
                $map[(string) $line['id']][] = (string) $bot->name;
            }
        }

        return $map;
    }

    public function refresh(): int
    {
        $rows = MowahadaLine::query()->orderBy('id')->get();
        $requests = [];
        foreach ($rows as $row) {
            $base = $this->apiBase((string) $row->url);
            if ($base !== null) {
                $requests[(string) $row->wa_instance] = $base;
            }
        }
        if ($requests === []) {
            return 0;
        }

        $responses = Http::pool(function ($pool) use ($requests): void {
            foreach ($requests as $id => $base) {
                $pool->as((string) $id)->timeout(8)->connectTimeout(3)->acceptJson()
                    ->get($base['root'].'/getWaSettings/'.$base['token']);
            }
        });
        if (! is_array($responses)) {
            return 0;
        }

        $bots = $this->mowahadaBots();
        $updated = 0;
        foreach ($rows as $row) {
            $id = (string) $row->wa_instance;
            $response = $responses[$id] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $state = $response->json('stateInstance');
            $phone = preg_replace('/\D+/', '', (string) $response->json('phone')) ?? '';
            $until = $response->json('suspendedUntil');
            $this->observe(
                $row,
                is_string($state) ? $state : '',
                $phone,
                is_numeric($until) ? (int) $until : null,
            );
            $updated++;

            foreach ($bots as $bot) {
                $ids = array_column($this->lines->lines($bot), 'id');
                if (! in_array($id, $ids, true)) {
                    continue;
                }
                $fresh = $bot->fresh() ?? $bot;
                if (is_string($state) && $state !== '') {
                    $this->lines->stateChanged($fresh, $id, $state);
                    $fresh = $fresh->fresh() ?? $fresh;
                }
                if ($phone !== '') {
                    $this->lines->rememberPhone($fresh, $id, $phone);
                }
            }
        }

        return $updated;
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, array{phone: string, state: string, until: ?int}>|null
     */
    private function probe(array $urls): ?array
    {
        $requests = [];
        foreach ($urls as $url) {
            $base = $this->apiBase($url);
            if ($base === null) {
                return null;
            }
            $requests[$this->lines->idFor($url)] = $base;
        }
        if ($requests === []) {
            return null;
        }

        try {
            $responses = Http::pool(function ($pool) use ($requests): void {
                foreach ($requests as $id => $base) {
                    $pool->as((string) $id)->timeout(8)->connectTimeout(3)->acceptJson()
                        ->get($base['root'].'/getWaSettings/'.$base['token']);
                }
            });
        } catch (Throwable) {
            return null;
        }
        if (! is_array($responses)) {
            return null;
        }

        $out = [];
        foreach ($requests as $id => $base) {
            $response = $responses[$id] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                return null;
            }
            $state = $response->json('stateInstance');
            $until = $response->json('suspendedUntil');
            $out[(string) $id] = [
                'phone' => preg_replace('/\D+/', '', (string) $response->json('phone')) ?? '',
                'state' => is_string($state) ? $state : '',
                'until' => is_numeric($until) ? (int) $until : null,
            ];
        }

        return $out;
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

    private function retarget(string $oldId, string $url): void
    {
        foreach ($this->mowahadaBots() as $bot) {
            $settings = is_array($bot->integration_settings) ? $bot->integration_settings : [];
            $stored = is_array($settings['greenapi_lines'] ?? null) ? $settings['greenapi_lines'] : [];
            $changed = false;
            foreach ($stored as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rowUrl = trim((string) ($row['url'] ?? ''));
                if ($rowUrl === '' || $this->lines->idFor($rowUrl) !== $oldId) {
                    continue;
                }
                $stored[$i]['url'] = $url;
                $changed = true;
            }
            $primary = trim((string) $bot->greenapi_url);
            $primaryChanged = $primary !== '' && $this->lines->idFor($primary) === $oldId;
            if (! $changed && ! $primaryChanged) {
                continue;
            }
            if ($changed) {
                $settings['greenapi_lines'] = $stored;
            }
            $bot->forceFill([
                'integration_settings' => $settings,
                'greenapi_url' => $primaryChanged ? $url : $bot->greenapi_url,
            ])->save();
        }
    }

    private function renameLine(string $oldId, string $newId): void
    {
        $botIds = $this->mowahadaBots()->pluck('id')->all();
        if ($botIds === []) {
            return;
        }
        ChatbotConversation::query()
            ->whereIn('instance_id', $botIds)
            ->where('metadata->mansour_line', $oldId)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($newId): void {
                foreach ($rows as $row) {
                    $meta = is_array($row->metadata) ? $row->metadata : [];
                    $meta['mansour_line'] = $newId;
                    $row->forceFill(['metadata' => $meta])->save();
                }
            });
        MansourContact::query()
            ->whereIn('instance_id', $botIds)
            ->whereNotNull('triggered_lines')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($oldId, $newId): void {
                foreach ($rows as $contact) {
                    $map = is_array($contact->triggered_lines) ? $contact->triggered_lines : [];
                    if (! array_key_exists($oldId, $map)) {
                        continue;
                    }
                    $map[$newId] = $map[$oldId];
                    unset($map[$oldId]);
                    $contact->forceFill(['triggered_lines' => $map])->save();
                }
            });
    }

    private function replaceUrl(string $id, string $url): void
    {
        foreach ($this->mowahadaBots() as $bot) {
            $bot = $bot->fresh() ?? $bot;
            $current = $this->lines->lines($bot);
            $changed = false;
            $urls = [];
            $active = [];
            foreach ($current as $line) {
                if ((string) ($line['id'] ?? '') === $id) {
                    $line['url'] = $url;
                    $changed = true;
                }
                $urls[] = $line['url'];
                if ($this->lines->isOn($line)) {
                    $active[] = (string) $line['id'];
                }
            }
            if ($changed) {
                $this->lines->sync($bot, $urls, null, $active);
            }
        }
    }
}
