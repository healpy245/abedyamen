<?php

declare(strict_types=1);

use App\Models\AiChatbot\MowahadaLine;
use App\Services\AiChatbot\MansourLines;
use App\Services\AiChatbot\MowahadaLinePool;
use Illuminate\Support\Facades\Http;

if (! function_exists('poolAvatarDigits')) {
    function poolAvatarDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    function poolAvatarExcluded(string $phone): bool
    {
        $phone = poolAvatarDigits($phone);

        return $phone === '972505133732' || $phone === '0505133732';
    }

    /**
     * @return array{root: string, token: string}|null
     */
    function poolAvatarBase(string $url): ?array
    {
        if (! preg_match('~^(https://[^/\s]+/waInstance\d+)/[A-Za-z]+/([^/\s?]+)~i', trim($url), $m)) {
            return null;
        }

        return ['root' => $m[1], 'token' => $m[2]];
    }

    /**
     * @return array<string, mixed>
     */
    function poolAvatars(bool $apply, string $image, bool $photosOnly = false, int $limit = 0, string $after = '', string $only = '', bool $statesOnly = false): array
    {
        $pool = app(MowahadaLinePool::class);
        $manager = app(MansourLines::class);
        $usage = $pool->usage();
        $invite = $pool->mowahadaBots()->first(fn ($bot) => $bot->isWomenInviteBot());
        $catalog = MowahadaLine::query()->orderBy('id')->get();

        $rows = [];
        foreach ($catalog as $row) {
            $id = (string) $row->wa_instance;
            $rows[] = [
                'id' => $id,
                'phone' => poolAvatarDigits((string) ($row->phone ?? '')),
                'url' => (string) $row->url,
                'bots' => $usage[$id] ?? [],
            ];
        }

        $report = [
            'invite' => $invite?->name,
            'invite_id' => $invite?->id,
            'lines' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'phone' => $row['phone'],
                'bots' => $row['bots'],
                'unlinked' => $row['bots'] === [],
                'skip_photo' => poolAvatarExcluded($row['phone']),
            ], $rows),
        ];
        if (! $apply) {
            return $report;
        }
        if ($statesOnly) {
            $onlyPhones = array_values(array_filter(array_map(
                static fn (string $phone): string => poolAvatarDigits($phone),
                explode(',', $only),
            )));
            $states = [];
            foreach ($rows as $row) {
                $phone = $row['phone'];
                if ($onlyPhones !== [] && ! in_array($phone, $onlyPhones, true)) {
                    continue;
                }
                $base = poolAvatarBase($row['url']);
                if ($base === null) {
                    $states[] = ['id' => $row['id'], 'phone' => $phone, 'state' => 'bad_url'];
                    continue;
                }
                try {
                    $response = Http::timeout(15)->acceptJson()->get($base['root'].'/getStateInstance/'.$base['token']);
                    $statusResponse = Http::timeout(15)->acceptJson()->get($base['root'].'/getStatusInstance/'.$base['token']);
                    $state = (string) ($response->json('stateInstance') ?: '');
                    $status = (string) ($statusResponse->json('statusInstance') ?: '');
                    $entry = [
                        'id' => $row['id'],
                        'phone' => $phone,
                        'state' => $state,
                        'status' => $status,
                    ];
                    if (($state === 'authorized' || $status === 'online') && is_file($image)) {
                        $jpeg = (string) file_get_contents($image);
                        $picture = Http::timeout(40)
                            ->attach('file', $jpeg, 'profile.jpg', ['Content-Type' => 'image/jpeg'])
                            ->post($base['root'].'/setProfilePicture/'.$base['token']);
                        $body = $picture->json();
                        $entry['photo'] = $picture->successful() && is_array($body) && ($body['setProfilePicture'] ?? null) === true ? 'set' : 'failed';
                        $entry['photo_status'] = $picture->status();
                        $entry['reason'] = is_array($body) ? mb_substr((string) ($body['reason'] ?? ''), 0, 180) : mb_substr($picture->body(), 0, 180);
                    }
                    $states[] = $entry;
                } catch (Throwable $e) {
                    $states[] = ['id' => $row['id'], 'phone' => $phone, 'state' => 'error', 'reason' => $e->getMessage()];
                }
                usleep(400_000);
            }
            $report['states'] = $states;

            return $report;
        }
        if ($image === '' || ! is_file($image)) {
            return $report + ['error' => 'missing image'];
        }

        $jpeg = (string) file_get_contents($image);
        $onlyPhones = array_values(array_filter(array_map(
            static fn (string $phone): string => poolAvatarDigits($phone),
            explode(',', $only),
        )));
        $photos = [];
        $passed = $after === '';
        $done = 0;
        $next = null;
        foreach ($rows as $i => $row) {
            if (! $passed) {
                if ($row['id'] !== $after) {
                    continue;
                }
                $passed = true;
            }
            $base = poolAvatarBase($row['url']);
            $phone = $row['phone'];
            if ($base !== null && $phone === '') {
                try {
                    $settings = Http::timeout(15)->acceptJson()->get($base['root'].'/getWaSettings/'.$base['token']);
                    $phone = poolAvatarDigits((string) $settings->json('phone'));
                    $rows[$i]['phone'] = $phone;
                } catch (Throwable) {
                    $phone = '';
                }
            }
            if ($onlyPhones !== [] && ! in_array($phone, $onlyPhones, true)) {
                continue;
            }
            if (poolAvatarExcluded($phone)) {
                $photos[] = ['id' => $row['id'], 'phone' => $phone, 'result' => 'kept'];
                continue;
            }
            if ($limit > 0 && $done >= $limit) {
                $next = $row['id'];
                break;
            }
            if ($base === null) {
                $photos[] = ['id' => $row['id'], 'phone' => $phone, 'result' => 'bad_url'];
                $done++;
                continue;
            }
            if ($done > 0) {
                sleep(11);
            }
            try {
                $response = Http::timeout(40)
                    ->attach('file', $jpeg, 'profile.jpg', ['Content-Type' => 'image/jpeg'])
                    ->post($base['root'].'/setProfilePicture/'.$base['token']);
                $body = $response->json();
                $flag = is_array($body) ? ($body['setProfilePicture'] ?? null) : null;
                $reason = is_array($body) ? ($body['reason'] ?? $body['message'] ?? '') : $response->body();
                $photos[] = [
                    'id' => $row['id'],
                    'phone' => $phone,
                    'result' => $response->successful() && $flag === true ? 'set' : 'failed',
                    'status' => $response->status(),
                    'reason' => is_string($reason) ? mb_substr($reason, 0, 180) : '',
                ];
            } catch (Throwable $e) {
                $photos[] = ['id' => $row['id'], 'phone' => $phone, 'result' => 'error', 'reason' => $e->getMessage()];
            }
            $done++;
        }

        $activated = [];
        if (! $photosOnly && $invite !== null && $after === '') {
            $existing = $manager->lines($invite);
            $urls = [];
            $active = [];
            foreach ($existing as $line) {
                $urls[] = $line['url'];
                if ($manager->isOn($line)) {
                    $active[] = (string) $line['id'];
                }
            }
            foreach ($rows as $row) {
                if ($row['bots'] !== [] || $row['url'] === '') {
                    continue;
                }
                $urls[] = $row['url'];
                $active[] = $row['id'];
                $activated[] = ['id' => $row['id'], 'phone' => $row['phone']];
            }
            if ($activated !== []) {
                $manager->sync($invite->fresh() ?? $invite, $urls, null, $active);
            }
        }

        $report['photos'] = $photos;
        $report['activated'] = $activated;
        $report['next'] = $next;

        return $report;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $apply = in_array('--apply', $argv ?? [], true);
    $image = '';
    foreach ($argv ?? [] as $i => $arg) {
        if ($arg === '--image' && isset($argv[$i + 1])) {
            $image = (string) $argv[$i + 1];
        }
    }
    echo json_encode(poolAvatars($apply, $image), JSON_UNESCAPED_UNICODE);
}
