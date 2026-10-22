<?php

declare(strict_types=1);

namespace App\Http\Controllers\KamanSupport;

use App\Enums\KamanSupport\SupportCommandName;
use App\Enums\KamanSupport\SupportTicketEventType;
use App\Enums\KamanSupport\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\KamanSupport\StoreSupportTicketCommandRequest;
use App\Http\Requests\KamanSupport\StoreSupportTicketEventRequest;
use App\Http\Requests\KamanSupport\UpdateSupportTicketRequest;
use App\Models\FormWorkflowRun;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportDeviceLog;
use App\Models\KamanSupport\SupportTicket;
use App\Services\KamanSupport\LogIssueGrouper;
use App\Services\KamanSupport\RestaurantLogoResolver;
use App\Services\KamanSupport\SupportDeviceCommandService;
use App\Services\KamanSupport\SupportDeviceSessionService;
use App\Services\KamanSupport\SupportTicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConsoleController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets,
        private readonly SupportDeviceSessionService $sessions,
        private readonly SupportDeviceCommandService $commands,
        private readonly LogIssueGrouper $grouper,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()?->limitedSupportConsole()) {
            return redirect()->route('kaman-support.live');
        }

        $restaurant = $request->query('restaurant');
        $kind = $request->query('kind');
        $restaurant = is_string($restaurant) ? $restaurant : '';
        $kind = is_string($kind) && in_array($kind, ['tablet', 'desktop', 'web', 'phone'], true) ? $kind : '';
        $devices = $this->sessions->visibleDevices($restaurant ?: null, $kind ?: null);
        $connectionsOnly = (bool) $request->user()?->supportConnectionsOnly();
        $failures = $connectionsOnly ? [] : $this->sessions->failureSummaries($devices);

        $cards = $this->restaurantCards($devices, $failures, $connectionsOnly);

        return view('kaman-support.board', [
            'restaurants' => $cards,
            'choices' => array_values(array_filter(
                $this->sessions->restaurantChoices(),
                fn (array $choice): bool => ! $this->hiddenRestaurant((string) $choice['value'], (string) $choice['label']),
            )),
            'filters' => [
                'restaurant' => $restaurant,
                'kind' => $kind,
            ],
            'liveWindowSeconds' => $this->sessions->liveWindowSeconds(),
            'feedUrl' => route('kaman-support.live.feed'),
        ]);
    }

    public function logos(Request $request, RestaurantLogoResolver $logos): JsonResponse
    {
        $slugs = $request->query('restaurants', []);
        if (! is_array($slugs)) {
            $slugs = [];
        }

        return response()->json([
            'logos' => $logos->resolve(array_slice(array_map(strval(...), $slugs), 0, 80)),
        ]);
    }

    public function inbox(Request $request): View
    {
        $state = (string) $request->query('state', '');
        if (! in_array($state, ['', 'open', 'closed'], true)) {
            $state = '';
        }
        $q = $request->query('q');
        $query = SupportTicket::query()->with('closedBy:id,name')->orderByDesc('id');
        if ($state === 'closed') {
            $query->where('status', SupportTicketStatus::Closed);
        } elseif ($state === 'open') {
            $query->where('status', '!=', SupportTicketStatus::Closed->value);
        }
        if (is_string($q) && $q !== '') {
            $query->search($q);
        }
        $paginator = $query->paginate(30)->withQueryString();

        $total = SupportTicket::query()->count();
        $closed = SupportTicket::query()->where('status', SupportTicketStatus::Closed)->count();
        $progress = SupportTicket::query()->where('status', SupportTicketStatus::InProgress)->count();

        return view('kaman-support.inbox', [
            'tickets' => $paginator,
            'state' => $state,
            'search' => is_string($q) ? $q : '',
            'stats' => [
                'total' => $total,
                'open' => max(0, $total - $closed - $progress),
                'progress' => $progress,
                'closed' => $closed,
            ],
        ]);
    }

    public function close(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->tickets->updateTicket($ticket, [
            'status' => SupportTicketStatus::Closed->value,
            'note' => $data['note'] ?? null,
        ], $request->user());

        $ticket->forceFill([
            'closed_at' => $ticket->closed_at ?? now(),
            'closed_by' => $request->user()->id,
            'close_note' => $data['note'] ?? null,
        ])->save();

        return redirect()
            ->route('kaman-support.inbox', ['state' => 'closed'])
            ->with('status', __('kaman-support.close_ticket'));
    }

    public function escalate(Request $request, SupportTicket $ticket): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class), 403);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:20000'],
        ]);
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $description = $ticket->details ?: $ticket->summary;
        }

        $dev = \App\Models\AppDevelopment\AppDevelopmentTicket::query()->create([
            'ticket_number' => 'TMP-'.\Illuminate\Support\Str::ulid(),
            'title' => \Illuminate\Support\Str::limit((string) $ticket->summary, 255, ''),
            'description' => $description,
            'restaurant_name' => $ticket->restaurant_name ?: $ticket->restaurant,
            'support_ticket_id' => $ticket->id,
            'type' => \App\Enums\AppDevelopmentTicketType::Bug,
            'priority' => \App\Enums\AppDevelopmentTicketPriority::Normal,
            'status' => \App\Enums\AppDevelopmentTicketStatus::Open,
            'created_by' => $request->user()->id,
        ]);
        app(\App\Services\AppDevelopment\TicketNumberService::class)->assign($dev);
        $dev->syncAppTypes(['cashier']);
        $ticket->forceFill(['escalated_ticket_id' => $dev->id])->save();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'ticket' => $dev->ticket_number,
                'message' => __('kaman-support.escalated_as', ['ticket' => $dev->ticket_number]),
            ]);
        }

        return redirect()
            ->route('kaman-support.show', $ticket)
            ->with('status', $dev->ticket_number);
    }

    public function logs(Request $request): View
    {
        $lane = $request->query('lane') === 'server' ? 'server' : 'device';
        $filters = $this->failedFilters($request);
        $picked = $this->pickedRestaurants($request, $filters['restaurant']);
        $range = $request->query('range');
        $range = is_string($range) ? $range : '';
        $from = $this->sessions->parseDisplayTime($filters['from']);
        $to = $this->sessions->parseDisplayTime($filters['to'], true);
        if ($range === '15m') {
            $from = now()->subMinutes(15);
            $to = null;
        } elseif ($range === '1h') {
            $from = now()->subHour();
            $to = null;
        } elseif ($range === 'today') {
            $from = now()->timezone($this->sessions->displayTimezone())->startOfDay()->utc();
            $to = null;
        } elseif ($range === '7d') {
            $from = now()->subDays(7);
            $to = null;
        }
        $shared = [
            'q' => $filters['q'] ?: null,
            'from' => $from,
            'to' => $to,
            'limit' => 200,
            'restaurants' => $picked,
        ];
        // support_device_logs rows are captured by restaurant devices.
        // An API failure reported by the cashier app stays in Device Logs.
        // Server Logs stays empty until a real server integration writes its own records.
        $logs = $lane === 'server'
            ? collect()
            : $this->sessions->recentGlobalLogs(...$shared, level: 'error');

        $rows = [];
        foreach ($logs as $log) {
            if (! $log instanceof SupportDeviceLog || ! $log->support_device_id) {
                continue;
            }
            $logKind = $this->logKind($log);
            $device = $log->device;
            $message = $this->redactText((string) $log->message);
            $service = $this->logService($log);
            $presented = $this->presentLog($message, $logKind, $service);
            $cashier = trim((string) ($device?->cashier_name ?? ''));
            $deviceId = trim((string) ($device?->device_id ?? ''));
            $friendly = $cashier;
            if ($friendly === '' && $deviceId !== '' && ! $this->grouper->looksTechnicalId($deviceId) && ! $this->grouper->isPlaceholder($deviceId)) {
                $friendly = $deviceId;
            }
            if ($this->grouper->isPlaceholder($friendly)) {
                $friendly = '';
            }
            $restaurantName = (string) ($device?->displayRestaurant() ?? '');
            if ($this->grouper->isPlaceholder($restaurantName)) {
                $restaurantName = '';
            }
            $rows[] = [
                'id' => $log->id,
                'level' => (string) $log->level,
                'source' => (string) $log->source,
                'logKind' => $logKind,
                'service' => $presented['service'],
                'sourceLabel' => $this->sourceLabel($logKind, $presented['service'], (string) $log->source),
                'summary' => $presented['summary'],
                'method' => $presented['method'],
                'endpoint' => $presented['endpoint'],
                'code' => $presented['code'],
                'network' => $presented['network'],
                'message' => $message,
                'context' => $this->redactValue(is_array($log->context) ? $log->context : []),
                'at' => $this->sessions->formatDisplayTime($log->logged_at),
                'clock' => $this->sessions->formatDisplayTime($log->logged_at, 'H:i'),
                'restaurant' => $restaurantName,
                'restaurantId' => (string) ($device?->restaurant ?? ''),
                'device' => $friendly,
                'deviceId' => $deviceId,
                'kind' => $device?->deviceKind() ?? '',
                'version' => (string) ($device?->app_version ?? ''),
                'platform' => (string) ($device?->platform ?? ''),
            ];
        }

        $client = $this->logClientFilters($request);
        $issues = $this->grouper->group($rows);
        $deviceOptions = [];
        $httpOptions = [];
        $visibleCount = 0;
        foreach ($issues as $index => $issue) {
            $issues[$index]['visible'] = $this->grouper->accepts($issue, $client);
            if ($issues[$index]['visible']) {
                $visibleCount++;
            }
            foreach ($issue['devices'] as $deviceName) {
                if ($deviceName !== '') {
                    $deviceOptions[$deviceName] = $deviceName;
                }
            }
            if ($issue['code'] !== '') {
                $httpOptions[$issue['code']] = $issue['code'];
            }
        }
        natcasesort($deviceOptions);
        ksort($httpOptions, SORT_STRING);

        $choices = $this->sessions->restaurantChoices();
        $logos = app(RestaurantLogoResolver::class)->cached(array_merge(
            array_column($issues, 'restaurantId'),
            array_column($choices, 'value'),
        ));
        foreach ($issues as $index => $issue) {
            $id = (string) ($issue['restaurantId'] ?? '');
            $issues[$index]['logo'] = $id !== '' ? ($logos[$id] ?? null) : null;
            $issues[$index]['products'] = $this->logProductKeys($issue);
        }

        return view('kaman-support.logs', [
            'issues' => $issues,
            'loaded' => count($rows),
            'visibleCount' => $visibleCount,
            'loadedAt' => now()->timezone($this->sessions->displayTimezone())->format('H:i'),
            'lane' => $lane,
            'choices' => $choices,
            'logos' => $logos,
            'picked' => $picked,
            'range' => $range,
            'filters' => $filters,
            'client' => $client,
            'deviceOptions' => array_values($deviceOptions),
            'httpOptions' => array_values($httpOptions),
        ]);
    }

    public function menu(Request $request): View
    {
        $user = $request->user();
        $runs = collect();
        if ($user !== null) {
            $runs = FormWorkflowRun::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(8)
                ->get(['id', 'method_type', 'subdomain', 'environment', 'status', 'error', 'started_at', 'finished_at']);
        }

        $base = (string) $request->session()->get('full_ai_kaman_base_url', '');
        $host = (string) (parse_url($base, PHP_URL_HOST) ?: '');

        return view('kaman-support.menu', [
            'choices' => $this->sessions->restaurantChoices(),
            'runs' => $runs,
            'signedHost' => $host,
            'authUrl' => route('kaman-support.menu.auth'),
            'loginUrl' => route('kaman-support.menu.login'),
            'chatUrl' => route('kaman-support.menu.chat'),
        ]);
    }

    public function show(SupportTicket $ticket): View
    {
        $ticket->load('events');

        $device = null;
        if (filled($ticket->restaurant) && filled($ticket->device_id)) {
            $device = SupportDevice::query()
                ->where('restaurant', $ticket->restaurant)
                ->where('device_id', $ticket->device_id)
                ->first();
        }

        return view('kaman-support.show', [
            'ticket' => $ticket,
            'device' => $device,
            'commands' => $device ? $this->commands->recent($device) : collect(),
            'commandNames' => SupportCommandName::cases(),
            'statuses' => SupportTicketStatus::cases(),
        ]);
    }

    public function live(Request $request): View
    {
        $filters = $this->liveFilters($request);
        [$level, $source] = $this->logViewPair($filters['view']);
        $liveDevices = $this->sessions->liveDevices($filters['restaurant'] ?: null, $filters['kind'] ?: null);
        $recentDevices = $this->sessions->recentDevices($filters['restaurant'] ?: null, $filters['kind'] ?: null);
        $connectionsOnly = (bool) $request->user()?->supportConnectionsOnly();
        $failures = $connectionsOnly
            ? []
            : $this->sessions->failureSummaries($liveDevices->concat($recentDevices));
        $showingLogs = ! $connectionsOnly && $filters['view'] !== 'devices';

        return view('kaman-support.live', [
            'liveDevices' => $liveDevices,
            'recentDevices' => $recentDevices,
            'failures' => $failures,
            'feedLogs' => $showingLogs
                ? $this->sessions->recentGlobalLogs(
                    restaurant: $filters['restaurant'] ?: null,
                    kind: $filters['kind'] ?: null,
                    level: $level,
                    source: $source,
                    q: $filters['q'] ?: null,
                    limit: 120,
                )
                : collect(),
            'failedHints' => [],
            'restaurants' => $this->sessions->restaurantChoices(),
            'filters' => $filters,
            'liveWindowSeconds' => $this->sessions->liveWindowSeconds(),
        ]);
    }

    public function liveFeed(Request $request): JsonResponse
    {
        $filters = $this->liveFilters($request);
        [$level, $source] = $this->logViewPair($filters['view']);
        $liveDevices = $this->sessions->liveDevices($filters['restaurant'] ?: null, $filters['kind'] ?: null);
        $recentDevices = $this->sessions->recentDevices($filters['restaurant'] ?: null, $filters['kind'] ?: null);
        $connectionsOnly = (bool) $request->user()?->supportConnectionsOnly();
        $failures = $connectionsOnly
            ? []
            : $this->sessions->failureSummaries($liveDevices->concat($recentDevices));
        // Devices is the restaurant list. Log scans wait until a log chip is open.
        $cards = $connectionsOnly || $request->boolean('cards') || $filters['view'] === 'devices';
        $hours = (int) $request->query('hours', 0);
        $from = $hours > 0 ? now()->subHours(min($hours, 72)) : null;
        $limit = max(1, min(200, (int) $request->query('limit', 120)));
        $failed = $cards ? collect() : $this->sessions->recentGlobalLogs(
            restaurant: $filters['restaurant'] ?: null,
            kind: $filters['kind'] ?: null,
            level: 'error',
            source: 'api',
            from: $from,
            limit: 40,
        );
        $logs = $cards ? collect() : $this->sessions->recentGlobalLogs(
            restaurant: $filters['restaurant'] ?: null,
            kind: $filters['kind'] ?: null,
            level: $level,
            source: $source,
            q: $filters['q'] ?: null,
            from: $from,
            limit: $limit,
        );

        return $this->utf8Json([
            'ok' => true,
            'live' => $liveDevices
                ->map(fn (SupportDevice $device) => $this->boardState($device, $failures[$device->id] ?? null, $connectionsOnly))
                ->values(),
            'recent' => $recentDevices
                ->map(fn (SupportDevice $device) => $this->boardState($device, $failures[$device->id] ?? null, $connectionsOnly))
                ->values(),
            'logs' => $this->serializeLogs($logs, showDevice: true),
            'hints' => [
                'failedApi' => $failed->count(),
                'failedHints' => $cards ? [] : $this->sessions->failedRequestHints(
                    restaurant: $filters['restaurant'] ?: null,
                    kind: $filters['kind'] ?: null,
                ),
            ],
            'now' => now()->timezone($this->sessions->displayTimezone())->toIso8601String(),
        ]);
    }

    public function failed(Request $request): View
    {
        $filters = $this->failedFilters($request);
        $from = $this->sessions->parseDisplayTime($filters['from']);
        $to = $this->sessions->parseDisplayTime($filters['to'], true);
        $logs = $this->sessions->failedLogs(
            restaurant: $filters['restaurant'] ?: null,
            type: $filters['type'] ?: null,
            q: $filters['q'] ?: null,
            from: $from,
            to: $to,
            limit: 200,
        );

        return view('kaman-support.failed', [
            'logs' => $logs,
            'restaurants' => $this->sessions->restaurantChoices(),
            'failedHints' => $this->sessions->failedRequestHints(
                restaurant: $filters['restaurant'] ?: null,
            ),
            'filters' => $filters,
        ]);
    }

    public function failedFeed(Request $request): JsonResponse
    {
        $filters = $this->failedFilters($request);
        $from = $this->sessions->parseDisplayTime($filters['from']);
        $to = $this->sessions->parseDisplayTime($filters['to'], true);
        $logs = $this->sessions->failedLogs(
            restaurant: $filters['restaurant'] ?: null,
            type: $filters['type'] ?: null,
            q: $filters['q'] ?: null,
            from: $from,
            to: $to,
            limit: 200,
        );

        return $this->utf8Json([
            'ok' => true,
            'count' => $logs->count(),
            'logs' => $this->serializeLogs($logs, showDevice: true),
            'hints' => [
                'failedApi' => $logs->count(),
                'failedHints' => $this->sessions->failedRequestHints(
                    restaurant: $filters['restaurant'] ?: null,
                ),
            ],
            'now' => now()->timezone($this->sessions->displayTimezone())->toIso8601String(),
        ]);
    }

    public function device(Request $request, SupportDevice $device): RedirectResponse
    {
        $this->assertDeviceVisible($device);
        if ($request->user()?->limitedSupportConsole()) {
            return redirect()->route('kaman-support.live');
        }

        return redirect()->route('kaman-support.index', ['open' => $device->restaurant]);
    }

    public function deviceFeed(Request $request, SupportDevice $device): JsonResponse
    {
        $this->assertDeviceVisible($device);
        if ($request->user()?->supportConnectionsOnly()) {
            return $this->utf8Json([
                'ok' => true,
                'device' => $this->sessions->connectionState($device),
                'commands' => [],
                'logs' => [],
                'hints' => [
                    'failedApi' => 0,
                    'failedHints' => [],
                ],
            ]);
        }

        $this->sessions->markWatched($device);
        $device->refresh();
        $filters = $this->deviceLogFilters($request);
        $resolved = $this->sessions->filtersForView($filters['view']);
        $failures = $this->sessions->failureSummaries([$device]);
        $failed = $this->sessions->recentDeviceLogs(
            $device,
            level: 'error',
            source: 'api',
            minutes: 0,
            limit: 40,
        );

        return $this->utf8Json([
            'ok' => true,
            'device' => $this->sessions->deviceState($device, $failures[$device->id] ?? null),
            'commands' => $this->commands->recent($device, 12)->map(fn ($command) => [
                'id' => $command->public_id,
                'name' => $command->name instanceof SupportCommandName ? $command->name->value : (string) $command->name,
                'label' => $command->name instanceof SupportCommandName ? $command->name->label() : (string) $command->name,
                'status' => $command->status?->value,
                'args' => $command->args,
                'result' => $command->result,
                'at' => $command->completed_at?->toIso8601String() ?? $command->created_at?->toIso8601String(),
            ])->values(),
            'logs' => $this->serializeLogs($this->sessions->recentDeviceLogs(
                $device,
                level: $resolved['level'],
                source: $resolved['source'],
                q: $filters['q'] ?: null,
                minutes: 0,
                limit: 80,
            )),
            'hints' => [
                'failedApi' => $failed->count(),
                'failedHints' => collect($failed)
                    ->flatMap(fn ($log) => $this->sessions->hintTokens((string) $log->message))
                    ->unique()
                    ->take(8)
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket): RedirectResponse
    {
        $this->tickets->updateTicket($ticket, $request->validated(), $request->user());

        return redirect()
            ->route('kaman-support.show', $ticket)
            ->with('status', __('kaman-support.flash.updated'));
    }

    public function storeEvent(StoreSupportTicketEventRequest $request, SupportTicket $ticket): RedirectResponse
    {
        $this->tickets->addEvent(
            $ticket,
            SupportTicketEventType::from($request->validated('type')),
            $request->validated('message'),
            $request->validated('data'),
            $request->user(),
        );

        return redirect()
            ->route('kaman-support.show', $ticket)
            ->with('status', __('kaman-support.flash.note_added'));
    }

    public function storeTicketCommand(StoreSupportTicketCommandRequest $request, SupportTicket $ticket): RedirectResponse
    {
        $device = $this->commands->resolveTicketDevice($ticket);
        if ($device === null) {
            return redirect()
                ->route('kaman-support.show', $ticket)
                ->with('status', __('kaman-support.flash.command_offline'));
        }

        try {
            $this->commands->queue(
                $device,
                SupportCommandName::from($request->validated('name')),
                $request->validated('args') ?? [],
                $ticket,
                $request->user(),
            );
        } catch (\RuntimeException) {
            return redirect()
                ->route('kaman-support.show', $ticket)
                ->with('status', __('kaman-support.flash.command_busy'));
        }

        return redirect()
            ->route('kaman-support.show', $ticket)
            ->with('status', __('kaman-support.flash.command_queued'));
    }

    public function storeDeviceCommand(StoreSupportTicketCommandRequest $request, SupportDevice $device): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->wantsJson() || $request->ajax();

        try {
            $command = $this->commands->queue(
                $device,
                SupportCommandName::from($request->validated('name')),
                $request->validated('args') ?? [],
                null,
                $request->user(),
            );
        } catch (\RuntimeException) {
            if ($wantsJson) {
                return $this->utf8Json([
                    'ok' => false,
                    'error' => 'device_busy',
                    'message' => __('kaman-support.flash.command_busy'),
                ], 409);
            }

            return redirect()
                ->route('kaman-support.device', $device)
                ->with('status', __('kaman-support.flash.command_busy'));
        }

        if ($wantsJson) {
            return $this->utf8Json([
                'ok' => true,
                'id' => $command->public_id,
                'name' => $command->name instanceof SupportCommandName ? $command->name->value : (string) $command->name,
                'status' => $command->status?->value,
                'message' => __('kaman-support.flash.command_queued'),
            ], 202);
        }

        return redirect()
            ->route('kaman-support.device', $device)
            ->with('status', __('kaman-support.flash.command_queued'));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SupportDevice>  $devices
     * @param  array<int, array{count?: int, source?: string, message?: string, at?: ?string}>  $failures
     * @return list<array<string, mixed>>
     */
    private function restaurantCards($devices, array $failures, bool $connectionsOnly): array
    {
        $grouped = [];
        foreach ($devices as $device) {
            if (! $device instanceof SupportDevice) {
                continue;
            }
            $key = trim((string) $device->restaurant);
            if ($key === '') {
                $key = 'unknown';
            }
            if ($this->hiddenRestaurant($key, $device->displayRestaurant())) {
                continue;
            }
            $failure = $failures[$device->id] ?? null;
            $message = $connectionsOnly ? null : (isset($failure['message']) ? $this->redactText((string) $failure['message']) : null);
            $source = strtolower((string) ($failure['source'] ?? ''));
            $printerish = is_string($message) && $message !== '' && (
                $source === 'flutter'
                || $source === 'printer'
                || str_contains(strtolower($message), 'print')
            );
            $seen = $device->last_seen_at;
            $online = $device->isLive();
            $status = match (true) {
                $online && $printerish => 'warning',
                $online => 'online',
                $seen !== null => 'offline',
                default => 'unknown',
            };
            $grouped[$key][] = [
                'id' => $device->id,
                'deviceId' => (string) $device->device_id,
                'name' => (string) ($device->cashier_name ?: $device->hostname ?: $device->device_id),
                'kind' => $device->deviceKind(),
                'platform' => (string) $device->platform,
                'version' => (string) $device->app_version,
                'hostname' => (string) $device->hostname,
                'online' => $online,
                'status' => $status,
                'appState' => (string) $device->app_state,
                'lastSeenAt' => $seen?->toIso8601String(),
                'lastSeen' => $seen?->diffForHumans() ?? '',
                'error' => $message !== '' ? $message : null,
                'href' => route('kaman-support.device', $device),
            ];
        }

        $keys = array_keys($grouped);
        $tickets = $keys === []
            ? collect()
            : SupportTicket::query()
                ->whereIn('restaurant', $keys)
                ->latest('id')
                ->limit(240)
                ->get(['id', 'public_id', 'restaurant', 'restaurant_name', 'summary', 'status', 'device_id', 'created_at'])
                ->groupBy('restaurant');

        $logos = app(RestaurantLogoResolver::class)->cached(array_map(strval(...), array_keys($grouped)));
        $cards = [];
        foreach ($grouped as $key => $rows) {
            $online = 0;
            $warning = 0;
            $offline = 0;
            $unknown = 0;
            $lastAt = null;
            $lastHuman = '';
            $error = null;
            $name = $key;
            foreach ($rows as $row) {
                match ($row['status']) {
                    'online' => $online++,
                    'warning' => $warning++,
                    'offline' => $offline++,
                    default => $unknown++,
                };
                if ($row['status'] === 'warning') {
                    $online++;
                }
                if ($error === null && $row['status'] === 'warning' && is_string($row['error']) && $row['error'] !== '') {
                    $error = $this->shortFailure($row['error']);
                }
                if (is_string($row['lastSeenAt']) && ($lastAt === null || $row['lastSeenAt'] > $lastAt)) {
                    $lastAt = $row['lastSeenAt'];
                    $lastHuman = $row['lastSeen'];
                }
            }
            $sample = $devices->first(static fn (SupportDevice $device): bool => (string) $device->restaurant === (string) $key);
            if ($sample instanceof SupportDevice) {
                $name = $sample->displayRestaurant();
            }
            if ($key === '_pending') {
                $name = (string) __('kaman-support.ws_login_page');
            }
            $total = count($rows);
            $live = $online;
            $status = match (true) {
                $total === 0 || ($live === 0 && $offline === 0 && $unknown === $total) => 'unknown',
                $live === 0 => 'offline',
                $warning > 0 || $live < $total => 'warning',
                default => 'healthy',
            };
            $ticketRows = [];
            foreach ($tickets->get($key, collect()) as $ticket) {
                if (! $ticket instanceof SupportTicket) {
                    continue;
                }
                $ticketRows[] = [
                    'id' => $ticket->public_id,
                    'summary' => (string) $ticket->summary,
                    'status' => $ticket->status?->value,
                    'label' => $ticket->status?->label() ?? '',
                    'href' => route('kaman-support.show', $ticket),
                    'at' => $ticket->created_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i'),
                ];
                if (count($ticketRows) >= 6) {
                    break;
                }
            }

            $cards[] = [
                'id' => (string) $key,
                'name' => $name,
                'logo' => $logos[(string) $key] ?? null,
                'products' => $this->productKeys($rows),
                'online' => $live,
                'total' => $total,
                'status' => $status,
                'lastSeenAt' => $lastAt,
                'lastSeen' => $lastHuman,
                'error' => $error,
                'devices' => $rows,
                'tickets' => $ticketRows,
            ];
        }

        usort($cards, static function (array $a, array $b): int {
            $login = (($a['id'] ?? '') === '_pending' ? 1 : 0) <=> (($b['id'] ?? '') === '_pending' ? 1 : 0);
            if ($login !== 0) {
                return $login;
            }
            $rank = ['warning' => 0, 'offline' => 1, 'unknown' => 2, 'healthy' => 3];

            return ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9)
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        return $cards;
    }

    private function hiddenRestaurant(string $key, string $name): bool
    {
        $haystack = strtolower($key.' '.$name);

        return str_contains($haystack, 'password')
            || str_contains($haystack, 'restaurantnamekamanemail');
    }

    /**
     * 1.0.x is Kaman Cashier Mall. 1.3.x is Kaman Lite.
     * Online devices win. If nobody is online, the last known versions still label the restaurant.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function productKeys(array $rows): array
    {
        $online = array_values(array_filter($rows, static fn (array $row): bool => (bool) ($row['online'] ?? false)));
        $source = $online !== [] ? $online : $rows;
        $keys = [];
        foreach ($source as $row) {
            $key = $this->productKey((string) ($row['version'] ?? ''));
            if ($key !== null) {
                $keys[$key] = $key;
            }
        }

        return array_values(array_filter(
            ['mall', 'lite'],
            static fn (string $key): bool => isset($keys[$key]),
        ));
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return list<string>
     */
    private function logProductKeys(array $issue): array
    {
        $keys = [];
        $versions = [(string) ($issue['version'] ?? '')];
        foreach (is_array($issue['occurrences'] ?? null) ? $issue['occurrences'] : [] as $occurrence) {
            if (is_array($occurrence)) {
                $versions[] = (string) ($occurrence['version'] ?? '');
            }
        }
        foreach ($versions as $version) {
            $key = $this->productKey($version);
            if ($key !== null) {
                $keys[$key] = $key;
            }
        }

        return array_values(array_filter(
            ['mall', 'lite'],
            static fn (string $key): bool => isset($keys[$key]),
        ));
    }

    private function productKey(string $version): ?string
    {
        $version = ltrim(strtolower(trim($version)), 'v');
        if (preg_match('/^1\.0(?:\.|$)/', $version) === 1) {
            return 'mall';
        }
        if (preg_match('/^1\.3(?:\.|$)/', $version) === 1) {
            return 'lite';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function pickedRestaurants(Request $request, string $fallback): array
    {
        $picked = $request->query('restaurants', []);
        if (is_string($picked)) {
            $picked = $picked === '' ? [] : explode(',', $picked);
        }
        if (! is_array($picked)) {
            $picked = [];
        }
        $keys = [];
        foreach ($picked as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $keys[] = $value;
            }
        }
        if ($keys === [] && $fallback !== '') {
            $keys[] = $fallback;
        }

        return array_values(array_unique($keys));
    }

    private function sourceLabel(string $logKind, string $service, string $source): string
    {
        return match ($logKind) {
            'print' => __('kaman-support.ws_print_short'),
            'flutter' => 'Flutter',
            'api' => $service !== '' && ! in_array($service, ['api', 'other'], true) ? ucfirst($service) : 'API',
            default => $source !== '' ? $source : __('kaman-support.ws_source'),
        };
    }

    /**
     * @return array{severity: string, category: string, integration: string, device: string, http: string}
     */
    private function logClientFilters(Request $request): array
    {
        $severity = $request->query('severity');
        $category = $request->query('category');
        $integration = $request->query('integration');
        $device = $request->query('device');
        $http = $request->query('http');

        return [
            'severity' => is_string($severity) && in_array($severity, ['error', 'warn', 'info'], true) ? $severity : '',
            'category' => is_string($category) && in_array($category, ['flutter', 'api', 'print', 'network'], true) ? $category : '',
            'integration' => is_string($integration) && in_array($integration, ['alram', 'pelecard', 'haat', 'shoofi'], true) ? $integration : '',
            'device' => is_string($device) ? trim(mb_substr($device, 0, 80)) : '',
            'http' => is_string($http) && preg_match('/^\d{3}$/', $http) === 1 ? $http : '',
        ];
    }

    /**
     * @return array{summary: string, method: string, endpoint: string, code: string, service: string, network: bool}
     */
    private function presentLog(string $message, string $logKind, string $service): array
    {
        $method = '';
        $endpoint = '';
        $code = '';
        $summary = trim($message);
        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD)\s+(\S+)(?:\s*->\s*(\d{3}))?(?:\s+(.*))?$/is', $message, $match) === 1) {
            $method = strtoupper($match[1]);
            $endpoint = $match[2];
            $code = (string) ($match[3] ?? '');
            $tail = trim((string) ($match[4] ?? ''));
            $summary = $tail !== '' ? $tail : $this->shortPath($endpoint);
        } elseif (preg_match('/^(\S+)\s*->\s*(\d{3})\s*(.*)$/is', $message, $match) === 1) {
            $endpoint = $match[1];
            $code = $match[2];
            $tail = trim($match[3]);
            $summary = $tail !== '' ? $tail : $this->shortPath($endpoint);
        } elseif ($logKind === 'flutter' && preg_match('/([A-Za-z_][A-Za-z0-9_]*(?:Exception|Error))/', $message, $match) === 1) {
            $summary = $match[1];
        } elseif (str_contains($summary, '->')) {
            $summary = $this->shortFailure($summary);
        }
        if (str_contains($summary, '->')) {
            $summary = $this->shortFailure($summary);
        }
        $summary = $this->readableSummary($summary);
        if ($service !== '' && ! in_array($service, ['api', 'flutter', 'printer', 'print', 'other'], true) && ! str_starts_with(strtolower($summary), strtolower($service))) {
            $summary = ucfirst($service).': '.$summary;
        }
        $summary = trim(preg_replace('/\s+/', ' ', $summary) ?? $summary);
        if (mb_strlen($summary) > 90) {
            $summary = mb_substr($summary, 0, 87).'…';
        }
        $network = preg_match('/network|sync|connection|host lookup|socket/i', $message) === 1;

        return [
            'summary' => $summary,
            'method' => $method,
            'endpoint' => $endpoint,
            'code' => $code,
            'service' => $service,
            'network' => $network,
        ];
    }

    private function readableSummary(string $summary): string
    {
        if (preg_match("/Failed host lookup:\\s*'([^']+)'/i", $summary, $match) === 1) {
            return 'Failed host lookup: '.$match[1];
        }
        if (preg_match('/^failed\s+.+?:\s*(.+)$/is', $summary, $match) === 1) {
            $summary = trim($match[1]);
        }

        return trim(preg_replace('/\s+This indicates an error.*$/i', '', $summary) ?? $summary);
    }

    private function shortPath(string $path): string
    {
        $path = strtok($path, '?') ?: $path;
        $path = preg_replace('/[0-9a-f]{8,}[-0-9a-f]*/i', '…', $path) ?? $path;

        return mb_strlen($path) > 42 ? '…'.mb_substr($path, -36) : $path;
    }

    private function logKind(SupportDeviceLog $log): string
    {
        $source = strtolower((string) $log->source);
        $message = strtolower((string) $log->message);
        if (in_array($source, ['printer', 'print'], true) || preg_match('/print|printer/', $message) === 1) {
            return 'print';
        }
        if ($source === 'flutter') {
            return 'flutter';
        }
        if ($source === 'api' || str_starts_with((string) $log->message, 'API ')) {
            return 'api';
        }

        return 'other';
    }

    private function logLane(SupportDeviceLog $log): string
    {
        return $log->support_device_id ? 'device' : 'unknown';
    }

    private function logService(SupportDeviceLog $log): string
    {
        $haystack = strtolower((string) $log->source.' '.(string) $log->message);
        foreach (['alram', 'haat', 'shoofi', 'pelecard'] as $service) {
            if (str_contains($haystack, $service)) {
                return $service;
            }
        }

        $source = strtolower((string) $log->source);

        return $source !== '' ? $source : 'other';
    }

    private function shortFailure(string $message): string
    {
        if (preg_match('/->\s*(.+)$/u', $message, $match) === 1) {
            $message = trim($match[1]);
        }

        return mb_substr($message, 0, 90);
    }

    private function redactText(string $text): string
    {
        $text = preg_replace('/Bearer\s+\S+/i', 'Bearer —', $text) ?? $text;
        $text = preg_replace('/(token|api[_-]?key|authorization|password|secret)\s*[:=]\s*\S+/i', '$1: —', $text) ?? $text;

        return $text;
    }

    private function redactValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? $this->redactText($value) : $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $name = strtolower((string) $key);
            if (preg_match('/token|authorization|password|secret|api_key|card|pan|cvv/', $name) === 1) {
                $out[$key] = '—';

                continue;
            }
            $out[$key] = $this->redactValue($item);
        }

        return $out;
    }

    /**
     * @param  array{count?: int, source?: string, message?: string, at?: ?string}|null  $failure
     * @return array<string, mixed>
     */
    private function boardState(SupportDevice $device, ?array $failure, bool $connectionsOnly): array
    {
        if ($connectionsOnly) {
            return $this->sessions->connectionState($device);
        }

        return $this->sessions->deviceState($device, $failure);
    }

    private function assertDeviceVisible(SupportDevice $device): void
    {
        abort_unless($this->sessions->viewerCanSeeDevice($device), 404);
    }

    /**
     * @return array{restaurant: string, kind: string, view: string, q: string}
     */
    private function liveFilters(Request $request): array
    {
        $restaurant = $request->query('restaurant');
        $kind = $request->query('kind');
        $q = $request->query('q');

        return [
            'restaurant' => is_string($restaurant) ? $restaurant : '',
            'kind' => is_string($kind) && in_array($kind, ['tablet', 'desktop', 'web', 'phone'], true) ? $kind : '',
            'view' => $request->user()?->supportConnectionsOnly()
                ? 'devices'
                : $this->sessions->normalizeView($request->query('view'), 'devices'),
            'q' => is_string($q) ? $q : '',
        ];
    }

    /**
     * @return array{restaurant: string, type: string, q: string, from: string, to: string}
     */
    private function failedFilters(Request $request): array
    {
        $restaurant = $request->query('restaurant');
        $q = $request->query('q');
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            'restaurant' => is_string($restaurant) ? $restaurant : '',
            'type' => $this->sessions->normalizeSource($request->query('type')) ?? '',
            'q' => is_string($q) ? $q : '',
            'from' => is_string($from) ? $from : '',
            'to' => is_string($to) ? $to : '',
        ];
    }

    /**
     * @return array{view: string, q: string}
     */
    private function deviceLogFilters(Request $request): array
    {
        $q = $request->query('q');

        return [
            'view' => $this->sessions->normalizeView($request->query('view'), 'all'),
            'q' => is_string($q) ? $q : '',
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function logViewPair(string $view): array
    {
        $resolved = $this->sessions->filtersForView($view);

        return [$resolved['level'], $resolved['source']];
    }

    /**
     * @param  iterable<int, \App\Models\KamanSupport\SupportDeviceLog>  $logs
     * @return list<array<string, mixed>>
     */
    private function serializeLogs(iterable $logs, bool $showDevice = false): array
    {
        $rows = [];
        foreach ($logs as $log) {
            $device = $showDevice ? $log->device : null;
            $rows[] = [
                'id' => $log->id,
                'level' => $log->level,
                'source' => $log->source,
                'message' => $log->message,
                'context' => is_array($log->context) ? $log->context : null,
                'failed' => $log->level === 'error' && ($log->source === 'api' || str_starts_with((string) $log->message, 'API ')),
                'type' => $this->sessions->normalizeSource($log->source)
                    ?? (($log->source === 'api' || str_starts_with((string) $log->message, 'API ')) ? 'api' : null),
                'at' => $this->sessions->formatDisplayTime($log->logged_at),
                'device' => $device ? [
                    'id' => $device->id,
                    'name' => $device->displayRestaurant(),
                    'restaurant' => $device->restaurant,
                    'kind' => $device->deviceKind(),
                    'cashier' => $device->cashier_name,
                    'version' => (string) ($device->app_version ?? ''),
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function utf8Json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(
            $payload,
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
