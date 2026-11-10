<?php

declare(strict_types=1);

namespace App\Services\KamanSupport;

use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportDeviceLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportDeviceSessionService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function heartbeat(array $payload): SupportDevice
    {
        $restaurant = $this->stringValue($payload['restaurant'] ?? '', 120);
        if ($restaurant === '') {
            $restaurant = '_pending';
        }

        $deviceId = $this->stringValue($payload['deviceId'] ?? $payload['device_id'] ?? '', 191);
        if ($deviceId === '') {
            $deviceId = (string) Str::uuid();
        }

        $state = $this->appState($payload['appState'] ?? $payload['state'] ?? 'open');
        $now = now();

        $device = SupportDevice::query()->firstOrNew([
            'restaurant' => $restaurant,
            'device_id' => $deviceId,
        ]);

        $device->fill([
            'restaurant_name' => $this->nullableString($payload['restaurantName'] ?? $payload['restaurant_name'] ?? null),
            'cashier_name' => $this->nullableString($payload['cashierName'] ?? $payload['cashier_name'] ?? null),
            'cashier_email' => $this->nullableString($payload['cashierEmail'] ?? $payload['cashier_email'] ?? null),
            'role' => $this->nullableString($payload['role'] ?? null, 64),
            'platform' => $this->nullableString($payload['platform'] ?? null, 64),
            // Smaller heartbeats, like a printer update, leave the version out.
            'app_version' => $this->nullableString($payload['appVersion'] ?? $payload['app_version'] ?? null, 64) ?? $device->app_version,
            'product' => $this->product($payload['product'] ?? null) ?? $device->product,
            'hostname' => $this->nullableString($payload['hostname'] ?? $payload['deviceName'] ?? null),
            'locale' => $this->nullableString($payload['locale'] ?? null, 16),
            'session_id' => $this->nullableString($payload['sessionId'] ?? $payload['session_id'] ?? $device->session_id, 64),
            'app_state' => $state,
            'logged_in' => (bool) ($payload['loggedIn'] ?? $payload['logged_in'] ?? false),
            'last_seen_at' => $now,
            'extra' => $this->mergeExtra($device->extra, $payload),
        ]);

        if ($device->opened_at === null || $state === 'open') {
            if ($device->opened_at === null || $device->app_state === 'closed') {
                $device->opened_at = $now;
            }
        }

        $device->closed_at = $state === 'closed' ? $now : null;
        $this->storePrinterLayout($device, $payload);
        $device->save();

        $logs = $payload['logs'] ?? null;
        if (is_array($logs) && $logs !== []) {
            $this->ingestLogs($device, $logs);
        }

        return $device->fresh() ?? $device;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{accepted: int, device: SupportDevice}
     */
    public function ingestLogs(SupportDevice $device, array $rows): array
    {
        $maxBatch = max(1, (int) config('kaman-support.max_log_batch', 120));
        $messageMax = max(200, (int) config('kaman-support.log_message_max', 4000));
        $keep = max(200, (int) config('kaman-support.max_logs_per_device', 5000));
        $accepted = 0;

        $rows = array_slice(array_values($rows), 0, $maxBatch);

        DB::transaction(function () use ($device, $rows, $messageMax, $keep, &$accepted): void {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $clientId = $this->stringValue($row['id'] ?? $row['clientId'] ?? $row['client_id'] ?? '', 80);
                if ($clientId === '') {
                    $clientId = (string) Str::uuid();
                }

                $message = trim((string) ($row['message'] ?? ''));
                if ($message === '') {
                    continue;
                }

                $created = SupportDeviceLog::query()->firstOrCreate(
                    [
                        'support_device_id' => $device->id,
                        'client_id' => $clientId,
                    ],
                    [
                        'level' => $this->level($row['level'] ?? 'info'),
                        'source' => $this->stringValue($row['source'] ?? 'app', 64) ?: 'app',
                        'message' => mb_substr($message, 0, $messageMax),
                        'context' => $this->jsonValue($row['context'] ?? $row['data'] ?? null),
                        'logged_at' => $this->parseTime($row['at'] ?? $row['loggedAt'] ?? $row['logged_at'] ?? null) ?? now(),
                    ],
                );

                if ($created->wasRecentlyCreated) {
                    $accepted++;
                    if ($created->source === 'printer') {
                        $this->recordPrinterIssue($device, $created);
                    }
                }
            }

            $this->deleteExpiredLogs((int) $device->id);
            $this->trimLogCount((int) $device->id, $keep);

            $device->forceFill([
                'log_count' => SupportDeviceLog::query()->where('support_device_id', $device->id)->count(),
                'last_seen_at' => now(),
            ])->save();
        });

        return ['accepted' => $accepted, 'device' => $device->fresh() ?? $device];
    }

    /**
     * Kitchen-print logs become printer issues. A malformed log must never
     * stop the rest of the batch from being stored.
     */
    private function recordPrinterIssue(SupportDevice $device, SupportDeviceLog $log): void
    {
        try {
            // Savepoint: a failure here rolls back only this issue row.
            DB::transaction(fn () => app(PrinterIssueRecorder::class)->record($device, $log));
        } catch (\Throwable $error) {
            report($error);
        }
    }

    /**
     * Drop expired device logs and refresh stored counts.
     * Error and warn rows last 14 days. Info and debug rows last 1 day.
     */
    public function pruneLogs(?int $deviceId = null): int
    {
        $deleted = $this->deleteExpiredLogs($deviceId);
        if ($deleted > 0) {
            $this->refreshLogCounts($deviceId);
        }

        return $deleted;
    }

    private function failureLogDays(): int
    {
        return max(1, (int) config('kaman-support.log_failure_days', 14));
    }

    private function routineLogDays(): int
    {
        $days = max(1, (int) config('kaman-support.log_routine_days', 1));

        return min($days, $this->failureLogDays());
    }

    private function deleteExpiredLogs(?int $deviceId): int
    {
        $failures = ['error', 'warn'];

        return $this->deleteLoggedBefore($deviceId, $failures, now()->subDays($this->failureLogDays()), true)
            + $this->deleteLoggedBefore($deviceId, $failures, now()->subDays($this->routineLogDays()), false);
    }

    /**
     * @param  list<string>  $levels
     */
    private function deleteLoggedBefore(?int $deviceId, array $levels, Carbon $before, bool $matchingLevels): int
    {
        $query = SupportDeviceLog::query();
        if ($deviceId !== null) {
            $query->where('support_device_id', $deviceId);
        }
        if ($matchingLevels) {
            $query->whereIn('level', $levels);
        } else {
            $query->whereNotIn('level', $levels);
        }
        $query->where(function ($inner) use ($before): void {
            $inner->where('logged_at', '<', $before)
                ->orWhere(function ($missing) use ($before): void {
                    $missing->whereNull('logged_at')->where('created_at', '<', $before);
                });
        });

        return $query->delete();
    }

    private function trimLogCount(int $deviceId, int $keep): void
    {
        $total = SupportDeviceLog::query()->where('support_device_id', $deviceId)->count();
        $overflow = $total - $keep;
        if ($overflow <= 0) {
            return;
        }

        $routineIds = SupportDeviceLog::query()
            ->where('support_device_id', $deviceId)
            ->whereNotIn('level', ['error', 'warn'])
            ->orderBy('id')
            ->limit($overflow)
            ->pluck('id');

        if ($routineIds->isNotEmpty()) {
            SupportDeviceLog::query()->whereIn('id', $routineIds)->delete();
            $overflow -= $routineIds->count();
        }

        if ($overflow <= 0) {
            return;
        }

        $oldestIds = SupportDeviceLog::query()
            ->where('support_device_id', $deviceId)
            ->orderBy('id')
            ->limit($overflow)
            ->pluck('id');

        if ($oldestIds->isNotEmpty()) {
            SupportDeviceLog::query()->whereIn('id', $oldestIds)->delete();
        }
    }

    private function refreshLogCounts(?int $deviceId): void
    {
        $devices = SupportDevice::query()
            ->when($deviceId !== null, static fn ($query) => $query->whereKey($deviceId))
            ->when($deviceId === null, static fn ($query) => $query->where('log_count', '>', 0))
            ->get(['id', 'log_count']);

        foreach ($devices as $device) {
            $count = SupportDeviceLog::query()->where('support_device_id', $device->id)->count();
            if ((int) $device->log_count === $count) {
                continue;
            }
            SupportDevice::query()->whereKey($device->id)->update(['log_count' => $count]);
        }
    }

    /**
     * @return Collection<int, SupportDevice>
     */
    public function liveDevices(?string $restaurant = null, ?string $kind = null): Collection
    {
        $since = now()->subSeconds($this->liveWindowSeconds());

        $query = SupportDevice::query()
            ->where('app_state', '!=', 'closed')
            ->where('last_seen_at', '>=', $since)
            ->visibleTo($this->viewer());

        $this->applyRestaurant($query, $restaurant);
        $this->applyDeviceKind($query, $kind);
        $this->applyStableDeviceOrder($query);

        return $query->get();
    }

    /**
     * @return Collection<int, SupportDevice>
     */
    public function recentDevices(?string $restaurant = null, ?string $kind = null, int $hours = 24): Collection
    {
        $liveIds = $this->liveDevices($restaurant, $kind)->modelKeys();

        $query = SupportDevice::query()
            ->where('last_seen_at', '>=', now()->subHours(max(1, $hours)))
            ->when($liveIds !== [], static fn ($inner) => $inner->whereNotIn('id', $liveIds))
            ->visibleTo($this->viewer());

        $this->applyRestaurant($query, $restaurant);
        $this->applyDeviceKind($query, $kind);
        $this->applyStableDeviceOrder($query);

        return $query->limit(80)->get();
    }

    /**
     * Devices this viewer is allowed to see, newest heartbeat first.
     * A row is not online by itself — callers must use SupportDevice::isLive().
     *
     * @return Collection<int, SupportDevice>
     */
    public function visibleDevices(?string $restaurant = null, ?string $kind = null, int $limit = 400): Collection
    {
        $query = SupportDevice::query()->visibleTo($this->viewer())->orderByDesc('last_seen_at');
        $this->applyRestaurant($query, $restaurant);
        $this->applyDeviceKind($query, $kind);

        return $query->limit(max(1, min(500, $limit)))->get();
    }

    /**
     * @return Collection<int, SupportDeviceLog>
     */
    public function recentGlobalLogs(
        ?string $restaurant = null,
        ?string $kind = null,
        ?string $level = null,
        ?string $source = null,
        ?string $q = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $limit = 80,
        ?array $restaurants = null,
        ?string $lane = null,
    ): Collection {
        $query = SupportDeviceLog::query()
            ->with('device')
            ->orderByDesc('id');

        $this->constrainLogsToViewer($query);

        $keys = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            is_array($restaurants) ? $restaurants : [],
        )));
        if ($keys !== []) {
            $query->whereHas('device', function ($inner) use ($keys): void {
                $inner->whereIn('restaurant', $keys);
            });
        } elseif ($restaurant !== null && $restaurant !== '') {
            $query->whereHas('device', function ($inner) use ($restaurant): void {
                $this->applyRestaurant($inner, $restaurant);
            });
        }

        if ($kind !== null && $kind !== '') {
            $query->whereHas('device', function ($inner) use ($kind): void {
                $this->applyDeviceKind($inner, $kind);
            });
        }

        $this->applyLogFilters($query, $level, $source, $q);
        $this->applyTimeRange($query, $from, $to);
        if ($lane === 'server' || $lane === 'device') {
            $this->constrainPaymentServerLane($query, $lane);
        }

        return $query->limit(max(1, min(200, $limit)))->get();
    }

    /**
     * Card-terminal answers belong on the server log lane.
     * Older tablets stored them as device API errors, so the message is checked too.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<SupportDeviceLog>  $query
     */
    public function constrainPaymentServerLane(mixed $query, string $lane): void
    {
        $paymentServer = function ($inner): void {
            $inner->where('source', 'server')
                ->orWhere('message', 'like', '%operation canceled by user%')
                ->orWhere('message', 'like', '%operation cancelled by user%')
                ->orWhere('message', 'like', '%transaction refused%');
        };

        if ($lane === 'server') {
            $query->where($paymentServer);

            return;
        }

        $query->where(function ($inner) use ($paymentServer): void {
            $inner->whereNot($paymentServer);
        });
    }

    /**
     * One chip picks the log filter. Level and source menus used to override
     * the chip, so the form and the results disagreed.
     *
     * @return array{level: ?string, source: ?string}
     */
    public function filtersForView(string $view): array
    {
        $view = $this->normalizeView($view);

        return match ($view) {
            'bugs' => ['level' => 'error', 'source' => null],
            'failed' => ['level' => 'failed', 'source' => null],
            'api' => ['level' => 'error', 'source' => 'api'],
            'flutter' => ['level' => null, 'source' => 'flutter'],
            default => ['level' => null, 'source' => null],
        };
    }

    public function normalizeView(mixed $view, string $default = 'all'): string
    {
        $view = is_string($view) ? $view : '';
        if ($view === 'debug') {
            return 'all';
        }

        return in_array($view, ['devices', 'all', 'bugs', 'failed', 'api', 'flutter'], true) ? $view : $default;
    }

    /**
     * @return list<string>
     */
    public function failedRequestHints(
        ?string $restaurant = null,
        ?string $kind = null,
        int $limit = 8,
    ): array {
        $logs = $this->recentGlobalLogs(
            restaurant: $restaurant,
            kind: $kind,
            level: 'error',
            source: 'api',
            limit: 40,
        );

        $hints = [];
        foreach ($logs as $log) {
            foreach ($this->hintTokens((string) $log->message) as $token) {
                $hints[$token] = true;
            }
        }

        return array_slice(array_keys($hints), 0, max(1, min(12, $limit)));
    }

    /**
     * @return list<string>
     */
    public function hintTokens(string $message): array
    {
        $tokens = [];
        if (preg_match_all('/\b([45]\d{2})\b/', $message, $matches)) {
            foreach ($matches[1] as $status) {
                $tokens[$status] = true;
            }
        }
        if (preg_match_all('#(/[A-Za-z0-9_./-]+)#', $message, $matches)) {
            foreach ($matches[1] as $path) {
                $tokens[$path] = true;
            }
        }
        foreach (['timeout', 'failed', 'unauthorized', 'forbidden'] as $word) {
            if (stripos($message, $word) !== false) {
                $tokens[$word] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @return Collection<int, SupportDeviceLog>
     */
    public function recentDeviceLogs(
        SupportDevice $device,
        ?string $level = null,
        ?string $source = null,
        ?string $q = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $minutes = 15,
        int $limit = 80,
    ): Collection {
        $query = $device->logs()->getQuery()->orderByDesc('id');

        if ($from instanceof Carbon || $to instanceof Carbon) {
            $this->applyTimeRange($query, $from, $to);
        } elseif ($minutes > 0) {
            $query->where('logged_at', '>=', now()->subMinutes($minutes));
        }

        $this->applyLogFilters($query, $level, $source, $q);

        return $query->limit(max(1, min(200, $limit)))->get();
    }

    /**
     * @return LengthAwarePaginator<int, SupportDeviceLog>
     */
    public function paginateLogs(
        SupportDevice $device,
        ?string $level = null,
        ?string $source = null,
        ?string $q = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $page = 1,
        int $limit = 80,
        array $exceptIds = [],
    ): LengthAwarePaginator {
        $limit = max(1, min(200, $limit));
        $page = max(1, $page);

        $query = $device->logs()->getQuery()->orderByDesc('id');
        $this->applyLogFilters($query, $level, $source, $q);
        $this->applyTimeRange($query, $from, $to);

        if ($exceptIds !== []) {
            $query->whereNotIn('id', $exceptIds);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * @return Collection<int, SupportDeviceLog>
     */
    public function failedLogs(
        ?string $restaurant = null,
        ?string $type = null,
        ?string $q = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $limit = 200,
    ): Collection {
        $type = $this->normalizeSource($type);
        $query = SupportDeviceLog::query()
            ->with('device')
            ->where('level', 'error')
            ->orderByDesc('id');

        $this->constrainLogsToViewer($query);

        if ($restaurant !== null && $restaurant !== '') {
            $query->whereHas('device', function ($inner) use ($restaurant): void {
                $this->applyRestaurant($inner, $restaurant);
            });
        }

        if ($type !== null) {
            $this->applySource($query, $type);
        } else {
            $query->where(function ($inner): void {
                $inner->whereIn('source', ['api', 'flutter'])
                    ->orWhere('message', 'like', 'API %');
            });
        }

        $term = trim((string) $q);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('message', 'like', $like)
                    ->orWhere('source', 'like', $like);
            });
        }

        $this->applyTimeRange($query, $from, $to);
        $this->constrainPaymentServerLane($query, 'device');

        return $query->limit(max(1, min(300, $limit)))->get();
    }

    /**
     * @return list<string>
     */
    public function restaurants(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): string => $row['value'],
            $this->restaurantChoices(),
        )));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function restaurantChoices(): array
    {
        $seen = [];
        $choices = [];
        $devices = SupportDevice::query()
            ->select('restaurant', 'restaurant_name', 'app_version')
            ->where('restaurant', '!=', '')
            ->visibleTo($this->viewer());

        $devices = $devices
            ->orderByRaw('COALESCE(NULLIF(restaurant_name, ""), restaurant)')
            ->orderBy('id')
            ->get();

        foreach ($devices as $device) {
            $key = (string) $device->restaurant;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $choices[] = [
                'value' => $key,
                'label' => $device->displayRestaurant(),
            ];
        }

        return $choices;
    }

    public function liveWindowSeconds(): int
    {
        return max(15, (int) config('kaman-support.live_seen_seconds', 90));
    }

    public function markWatched(SupportDevice $device): void
    {
        $extra = is_array($device->extra) ? $device->extra : [];
        $extra['watchedAt'] = now()->toIso8601String();
        $device->forceFill(['extra' => $extra])->save();
    }

    public function isWatched(SupportDevice $device, int $seconds = 12): bool
    {
        $extra = is_array($device->extra) ? $device->extra : [];
        $raw = $extra['watchedAt'] ?? $extra['watched_at'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return false;
        }

        try {
            return Carbon::parse($raw)->greaterThan(now()->subSeconds(max(4, $seconds)));
        } catch (\Throwable) {
            return false;
        }
    }

    public function watchPollMs(SupportDevice $device): int
    {
        return $this->isWatched($device) ? 1500 : 15000;
    }

    /**
     * @param  array{count?: int, source?: string, message?: string, at?: ?string}|null  $failure
     * @return array<string, mixed>
     */
    public function deviceState(SupportDevice $device, ?array $failure = null): array
    {
        $extra = is_array($device->extra) ? $device->extra : [];
        $layout = $this->uniquePrinterLayout($extra);

        return [
            'id' => $device->id,
            'deviceId' => $device->device_id,
            'restaurant' => $device->restaurant,
            'restaurantName' => $device->displayRestaurant(),
            'cashierName' => $device->cashier_name,
            'cashierEmail' => $device->cashier_email,
            'role' => $device->role,
            'platform' => $device->platform,
            'hostname' => $device->hostname,
            'appVersion' => $device->app_version,
            'product' => $device->productKey(),
            'kind' => $device->deviceKind(),
            'online' => $device->isLive(),
            'appState' => $device->app_state,
            'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
            'lastSeen' => $device->last_seen_at?->diffForHumans(),
            'lastSeenClock' => $this->formatDisplayTime($device->last_seen_at, 'H:i'),
            'logCount' => $device->log_count,
            'watch' => $this->isWatched($device),
            'pollMs' => $this->watchPollMs($device),
            'printers' => $layout['printers'],
            'discovered' => $layout['discovered'],
            'kitchenTypes' => is_array($extra['kitchenTypes'] ?? $extra['kitchen_types'] ?? null)
                ? ($extra['kitchenTypes'] ?? $extra['kitchen_types'])
                : [],
            'scan' => is_array($extra['scan'] ?? null) ? $extra['scan'] : ['running' => false, 'done' => 0, 'total' => 0],
            'lan' => is_array($extra['lan'] ?? null) ? $extra['lan'] : ['nodes' => [], 'links' => []],
            'lanScan' => is_array($extra['lanScan'] ?? null) ? $extra['lanScan'] : ['running' => false, 'done' => 0, 'total' => 0],
            'pressure' => is_array($extra['pressure'] ?? null) ? $extra['pressure'] : ['running' => false, 'logs' => []],
            'settings' => $this->storedSettings($extra),
            'pizzaMeals' => $this->storedPizzaMeals($extra),
            'failedCount' => (int) ($failure['count'] ?? 0),
            'failedSource' => $failure['source'] ?? null,
            'failedMessage' => $failure['message'] ?? null,
            'failedAt' => $failure['at'] ?? null,
        ];
    }

    /**
     * Connection facts only: who is online, on which app version.
     * Logs, printer layouts, and LAN maps stay out.
     *
     * @return array<string, mixed>
     */
    public function connectionState(SupportDevice $device): array
    {
        return array_merge($this->deviceState($device), [
            'cashierEmail' => null,
            'logCount' => 0,
            'watch' => false,
            'printers' => [],
            'discovered' => [],
            'kitchenTypes' => [],
            'scan' => ['running' => false, 'done' => 0, 'total' => 0],
            'lan' => ['nodes' => [], 'links' => []],
            'lanScan' => ['running' => false, 'done' => 0, 'total' => 0],
            'pressure' => ['running' => false, 'logs' => []],
            'settings' => null,
            'pizzaMeals' => [],
            'failedCount' => 0,
            'failedSource' => null,
            'failedMessage' => null,
            'failedAt' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storePrinterLayout(SupportDevice $device, array $payload): void
    {
        $extra = is_array($device->extra) ? $device->extra : [];
        $hadLayout = isset($payload['printers']) || isset($payload['discovered'])
            || isset($extra['printers']) || isset($extra['discovered']);
        foreach (['printers', 'discovered', 'scan', 'kitchenTypes', 'kitchen_types', 'pressure', 'lan', 'lanScan'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $extra[$key === 'kitchen_types' ? 'kitchenTypes' : $key] = $payload[$key];
            }
        }
        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $extra['settings'] = $this->normalizeSettings($payload['settings']);
        }
        if (isset($payload['pizzaMeals']) && is_array($payload['pizzaMeals'])) {
            $extra['pizzaMeals'] = $this->storedPizzaMeals(['pizzaMeals' => $payload['pizzaMeals']]);
        }
        if ($hadLayout) {
            $layout = $this->uniquePrinterLayout($extra);
            $extra['printers'] = $layout['printers'];
            $extra['discovered'] = $layout['discovered'];
        }
        $device->extra = $extra === [] ? null : $extra;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    private function storedSettings(array $extra): ?array
    {
        $raw = $extra['settings'] ?? null;

        return is_array($raw) ? $this->normalizeSettings($raw) : null;
    }

    /**
     * Keeps only the POS settings-page fields a support user is allowed to change.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $raw): array
    {
        $flags = [
            'printKitchenCancel',
            'localZReport',
            'printReceiptOnReceive',
            'allowKitchenReprint',
            'autoPrintOnThisTablet',
            'builtInKeyboard',
            'pauseOrder',
            'pizzaInterface',
        ];
        $out = [];
        foreach ($flags as $flag) {
            if (! array_key_exists($flag, $raw)) {
                continue;
            }
            $out[$flag] = filter_var($raw[$flag], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('kitchenReprintPassword', $raw)) {
            $out['kitchenReprintPassword'] = mb_substr(trim((string) $raw['kitchenReprintPassword']), 0, 64);
        }
        if (isset($raw['pizzaMealIds']) && is_array($raw['pizzaMealIds'])) {
            $ids = [];
            foreach ($raw['pizzaMealIds'] as $id) {
                $id = trim((string) $id);
                if ($id === '' || in_array($id, $ids, true)) {
                    continue;
                }
                $ids[] = mb_substr($id, 0, 64);
                if (count($ids) >= 400) {
                    break;
                }
            }
            $out['pizzaMealIds'] = $ids;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return list<array{id: string, name: string}>
     */
    private function storedPizzaMeals(array $extra): array
    {
        $rows = [];
        foreach (is_array($extra['pizzaMeals'] ?? null) ? $extra['pizzaMeals'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $rows[] = [
                'id' => mb_substr($id, 0, 64),
                'name' => mb_substr($name !== '' ? $name : $id, 0, 80),
            ];
            if (count($rows) >= 300) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{printers: list<array<string, mixed>>, discovered: list<array<string, mixed>>}
     */
    private function uniquePrinterLayout(array $extra): array
    {
        $saved = [];
        foreach (is_array($extra['printers'] ?? null) ? $extra['printers'] : [] as $row) {
            if (! is_array($row) || ! $this->isSavedPrinterRow($row)) {
                continue;
            }
            $saved = $this->pushUniquePrinter($saved, $row);
        }

        $discovered = [];
        $incoming = array_merge(
            is_array($extra['discovered'] ?? null) ? $extra['discovered'] : [],
            array_values(array_filter(
                is_array($extra['printers'] ?? null) ? $extra['printers'] : [],
                fn ($row) => is_array($row) && ! $this->isSavedPrinterRow($row),
            )),
        );
        foreach ($incoming as $row) {
            if (! is_array($row) || $this->findPrinter($saved, $row) !== null) {
                continue;
            }
            $discovered = $this->pushUniquePrinter($discovered, $row);
        }

        return [
            'printers' => $saved,
            'discovered' => $discovered,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $printer
     * @return list<array<string, mixed>>
     */
    private function pushUniquePrinter(array $rows, array $printer): array
    {
        $index = $this->findPrinter($rows, $printer);
        if ($index === null) {
            $host = $this->printerHost($printer);
            if ($host !== '') {
                $printer['ip'] = $host;
                $printer['port'] = $this->printerPort($printer);
            }
            $rows[] = $printer;

            return $rows;
        }

        $current = $rows[$index];
        $rows[$index] = array_merge($current, $printer, [
            'mac' => $this->printerMac($printer['mac'] ?? '') ?: $this->printerMac($current['mac'] ?? '') ?: ($printer['mac'] ?? $current['mac'] ?? ''),
            'ip' => $this->printerHost($printer) ?: $this->printerHost($current) ?: ($printer['ip'] ?? $current['ip'] ?? ''),
            'port' => $this->printerPort($printer) ?: $this->printerPort($current),
            'saved' => ($current['saved'] ?? true) !== false || ($printer['saved'] ?? true) !== false,
            'receipt' => ! empty($current['receipt']) || ! empty($printer['receipt']),
            'sticker' => ! empty($current['sticker']) || ! empty($printer['sticker']),
            'kitchen' => ! empty($current['kitchen']) || ! empty($printer['kitchen']),
            'stations' => array_values(array_unique(array_merge(
                is_array($current['stations'] ?? null) ? $current['stations'] : [],
                is_array($printer['stations'] ?? null) ? $printer['stations'] : [],
            ))),
        ]);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $printer
     */
    private function findPrinter(array $rows, array $printer): ?int
    {
        foreach ($rows as $index => $row) {
            if ($this->samePrinter($row, $printer)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    /**
     * @param  array<string, mixed>  $row
     */
    private function isSavedPrinterRow(array $row): bool
    {
        $saved = $row['saved'] ?? true;
        if ($saved === false || $saved === 'false') {
            return ! empty($row['receipt']) || ! empty($row['sticker']) || ! empty($row['kitchen']);
        }

        return true;
    }

    private function samePrinter(array $left, array $right): bool
    {
        $leftMac = $this->printerMac($left['mac'] ?? '');
        $rightMac = $this->printerMac($right['mac'] ?? '');
        if ($leftMac !== '' && $rightMac !== '' && $leftMac === $rightMac) {
            return true;
        }
        $host = $this->printerHost($left);

        return $host !== '' && $host === $this->printerHost($right);
    }

    /**
     * @param  array<string, mixed>  $printer
     */
    private function printerHost(array $printer): string
    {
        $raw = trim((string) ($printer['ip'] ?? $printer['address'] ?? $printer['host'] ?? ''));
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::(\d+))?$/', $raw, $match) === 1) {
            return $match[1];
        }
        $colon = strrpos($raw, ':');
        if ($colon !== false && is_numeric(substr($raw, $colon + 1))) {
            return substr($raw, 0, $colon);
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $printer
     */
    private function printerPort(array $printer): int
    {
        $raw = trim((string) ($printer['ip'] ?? $printer['address'] ?? ''));
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/', $raw, $match) === 1) {
            return (int) $match[2];
        }

        return (int) ($printer['port'] ?? 9100);
    }

    private function printerMac(mixed $raw): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', (string) $raw) ?? '');
        if (strlen($hex) < 12) {
            return '';
        }

        return implode(':', str_split(substr($hex, 0, 12), 2));
    }

    public function displayTimezone(): string
    {
        return (string) config('kaman-support.display_timezone', 'Asia/Jerusalem');
    }

    public function formatDisplayTime(mixed $time, string $format = 'Y-m-d H:i:s'): ?string
    {
        if (! $time instanceof Carbon) {
            return null;
        }

        return $time->copy()->timezone($this->displayTimezone())->format($format);
    }

    public function parseDisplayTime(mixed $value, bool $endOfDay = false): ?Carbon
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($raw, $this->displayTimezone());
            if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                $parsed = $parsed->endOfDay();
            }

            return $parsed->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public function normalizeSource(mixed $source): ?string
    {
        $source = strtolower(trim((string) $source));

        return in_array($source, ['api', 'flutter', 'printer'], true) ? $source : null;
    }

    /**
     * @param  iterable<int, SupportDevice>  $devices
     * @return array<int, array{count: int, source: string, message: string, at: ?string}>
     */
    public function failureSummaries(iterable $devices): array
    {
        $ids = [];
        foreach ($devices as $device) {
            if ($device instanceof SupportDevice && $device->id) {
                $ids[] = (int) $device->id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $since = now()->subDay();
        $base = SupportDeviceLog::query()
            ->whereIn('support_device_id', $ids)
            ->where('level', 'error')
            ->where('logged_at', '>=', $since)
            ->where(function ($inner): void {
                $inner->whereIn('source', ['api', 'flutter'])
                    ->orWhere('message', 'like', 'API %');
            });
        $this->constrainPaymentServerLane($base, 'device');

        $counts = (clone $base)
            ->select('support_device_id')
            ->selectRaw('count(*) as error_count')
            ->groupBy('support_device_id')
            ->get();

        $latestIds = (clone $base)
            ->select('support_device_id')
            ->selectRaw('max(id) as latest_id')
            ->groupBy('support_device_id')
            ->pluck('latest_id');

        $latest = SupportDeviceLog::query()
            ->whereIn('id', $latestIds)
            ->get(['id', 'support_device_id', 'source', 'message', 'logged_at'])
            ->keyBy(static fn (SupportDeviceLog $log): int => (int) $log->support_device_id);

        $summaries = [];
        foreach ($counts as $row) {
            $deviceId = (int) $row->support_device_id;
            $log = $latest->get($deviceId);
            $summaries[$deviceId] = [
                'count' => (int) $row->error_count,
                'source' => (string) ($log->source ?? ''),
                'message' => mb_substr((string) ($log->message ?? ''), 0, 160),
                'at' => $log instanceof SupportDeviceLog ? $this->formatDisplayTime($log->logged_at, 'H:i') : null,
            ];
        }

        return $summaries;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SupportDevice>  $query
     */
    private function applyStableDeviceOrder(mixed $query): void
    {
        $query->orderByRaw('COALESCE(NULLIF(restaurant_name, ""), restaurant)')
            ->orderBy('hostname')
            ->orderBy('id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SupportDeviceLog>  $query
     */
    private function applyTimeRange(mixed $query, ?Carbon $from, ?Carbon $to): void
    {
        if ($from instanceof Carbon) {
            $query->where('logged_at', '>=', $from);
        }
        if ($to instanceof Carbon) {
            $query->where('logged_at', '<=', $to);
        }
    }

    private function viewer(): ?\App\Models\User
    {
        $user = auth()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SupportDeviceLog>  $query
     */
    private function constrainLogsToViewer(mixed $query): void
    {
        if ($this->viewer()?->supportProduct() !== null) {
            $query->whereHas('device', fn ($device) => $device->visibleTo($this->viewer()));
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SupportDevice>|\Illuminate\Database\Eloquent\Builder<SupportDeviceLog>  $query
     */
    private function applyRestaurant(mixed $query, ?string $restaurant): void
    {
        $restaurant = trim((string) $restaurant);
        if ($restaurant === '') {
            return;
        }

        $query->where(function ($inner) use ($restaurant): void {
            $inner->where('restaurant', $restaurant)
                ->orWhere('restaurant_name', $restaurant);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SupportDevice>  $query
     */
    private function applyDeviceKind(mixed $query, ?string $kind): void
    {
        $kind = strtolower(trim((string) $kind));
        if (! in_array($kind, ['tablet', 'desktop', 'web', 'phone'], true)) {
            return;
        }

        if ($kind === 'phone') {
            $query->where('extra->deviceKind', 'phone');

            return;
        }

        $platforms = match ($kind) {
            'tablet' => ['android', 'ios', 'tablet'],
            'web' => ['web'],
            default => ['windows', 'macos', 'linux', 'desktop'],
        };

        $query->where(function ($inner) use ($kind, $platforms): void {
            $inner->where('extra->deviceKind', $kind)
                ->orWhere(function ($fallback) use ($platforms): void {
                    $fallback->whereIn('platform', $platforms)
                        ->where(function ($missing): void {
                            $missing->whereNull('extra->deviceKind')
                                ->orWhere('extra->deviceKind', '');
                        });
                });
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SupportDeviceLog>  $query
     */
    private function applyLogFilters(mixed $query, ?string $level, ?string $source, ?string $q): void
    {
        if ($level === 'failed') {
            // Errors, and any request the server refused or failed, whatever level the device gave it.
            $query->where(function ($inner): void {
                $inner->where('level', 'error');
                foreach (['4', '5'] as $band) {
                    foreach (['-> ', ' /% '] as $before) {
                        $inner->orWhere('message', 'like', "%{$before}{$band}__")
                            ->orWhere('message', 'like', "%{$before}{$band}__ %");
                    }
                }
            });
        } elseif ($level !== null && $level !== '') {
            $query->where('level', $this->level($level));
        }

        $this->applySource($query, $source);

        $term = trim((string) $q);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('message', 'like', $like)
                    ->orWhere('source', 'like', $like)
                    ->orWhere('level', 'like', $like);
            });
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SupportDeviceLog>  $query
     */
    private function applySource(mixed $query, ?string $source): void
    {
        $source = trim((string) $source);
        if ($source === '') {
            return;
        }

        if ($source === 'api') {
            $query->where(function ($inner): void {
                $inner->where('source', 'api')
                    ->orWhere('message', 'like', 'API %');
            });

            return;
        }

        $query->where('source', $source);
    }

    /**
     * @param  array<mixed>|null  $current
     * @param  array<string, mixed>  $payload
     * @return array<mixed>|null
     */
    private function mergeExtra(?array $current, array $payload): ?array
    {
        $extra = $this->jsonValue($payload['extra'] ?? null) ?? (is_array($current) ? $current : []);
        $kind = $this->nullableString($payload['deviceKind'] ?? $payload['device_kind'] ?? null, 32);
        if ($kind !== null) {
            $extra['deviceKind'] = $kind;
        }

        return $extra === [] ? null : $extra;
    }

    private function product(mixed $value): ?string
    {
        $product = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($product, ['mall', 'lite'], true) ? $product : null;
    }

    private function appState(mixed $value): string
    {
        $state = strtolower(trim((string) $value));

        return in_array($state, ['open', 'background', 'closed'], true) ? $state : 'open';
    }

    private function level(mixed $value): string
    {
        $level = strtolower(trim((string) $value));

        return match ($level) {
            'error', 'err', 'fatal' => 'error',
            'warn', 'warning' => 'warn',
            'debug', 'trace' => 'debug',
            default => 'info',
        };
    }

    private function stringValue(mixed $value, int $max = 191): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function nullableString(mixed $value, int $max = 191): ?string
    {
        $text = $this->stringValue($value, $max);

        return $text === '' ? null : $text;
    }

    /**
     * @return array<mixed>|null
     */
    private function jsonValue(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
