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
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportTicket;
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
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()?->posLiveLogsOnly()) {
            return redirect()->route('kaman-support.live');
        }

        $status = $request->query('status');
        $restaurant = $request->query('restaurant');
        $q = $request->query('q');

        $paginator = $this->tickets->paginate(
            status: is_string($status) ? $status : null,
            restaurant: is_string($restaurant) ? $restaurant : null,
            q: is_string($q) ? $q : null,
            page: (int) $request->query('page', 1),
            limit: 30,
        );

        $deviceKeys = $paginator->getCollection()
            ->filter(static fn (SupportTicket $ticket): bool => filled($ticket->restaurant) && filled($ticket->device_id))
            ->map(static fn (SupportTicket $ticket): string => $ticket->restaurant.'|'.$ticket->device_id)
            ->unique()
            ->values();

        $devices = collect();
        if ($deviceKeys->isNotEmpty()) {
            $devices = SupportDevice::query()
                ->where(function ($query) use ($deviceKeys): void {
                    foreach ($deviceKeys as $key) {
                        [$restaurantKey, $deviceId] = explode('|', $key, 2);
                        $query->orWhere(function ($inner) use ($restaurantKey, $deviceId): void {
                            $inner->where('restaurant', $restaurantKey)->where('device_id', $deviceId);
                        });
                    }
                })
                ->get()
                ->keyBy(static fn (SupportDevice $device): string => $device->restaurant.'|'.$device->device_id);
        }

        $restaurants = SupportTicket::query()
            ->select('restaurant')
            ->where('restaurant', '!=', '')
            ->distinct()
            ->orderBy('restaurant')
            ->pluck('restaurant');

        return view('kaman-support.index', [
            'tickets' => $paginator,
            'devices' => $devices,
            'restaurants' => $restaurants,
            'statuses' => SupportTicketStatus::cases(),
            'filters' => [
                'status' => is_string($status) ? $status : '',
                'restaurant' => is_string($restaurant) ? $restaurant : '',
                'q' => is_string($q) ? $q : '',
            ],
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
        [$level, $source] = $this->logViewPair($filters['view'], $filters['source']);
        $liveDevices = $this->sessions->liveDevices($filters['restaurant'] ?: null, $filters['kind'] ?: null);
        $recentDevices = $this->sessions->recentDevices($filters['restaurant'] ?: null, $filters['kind'] ?: null);
        $failures = $this->sessions->failureSummaries($liveDevices->concat($recentDevices));

        return view('kaman-support.live', [
            'liveDevices' => $liveDevices,
            'recentDevices' => $recentDevices,
            'failures' => $failures,
            'feedLogs' => $this->sessions->recentGlobalLogs(
                restaurant: $filters['restaurant'] ?: null,
                kind: $filters['kind'] ?: null,
                level: $level,
                source: $source,
                q: $filters['q'] ?: null,
                from: $this->sessions->parseDisplayTime($filters['from']),
                to: $this->sessions->parseDisplayTime($filters['to']),
                limit: $filters['view'] === 'devices' ? 40 : 120,
            ),
            'failedHints' => $this->sessions->failedRequestHints(
                restaurant: $filters['restaurant'] ?: null,
                kind: $filters['kind'] ?: null,
            ),
            'restaurants' => $this->sessions->restaurantChoices(),
            'filters' => $filters,
            'liveWindowSeconds' => $this->sessions->liveWindowSeconds(),
        ]);
    }

    public function liveFeed(Request $request): JsonResponse
    {
        $filters = $this->liveFilters($request);
        [$level, $source] = $this->logViewPair($filters['view'], $filters['source']);
        $from = $this->sessions->parseDisplayTime($filters['from']);
        $to = $this->sessions->parseDisplayTime($filters['to']);
        $liveDevices = $this->sessions->liveDevices($filters['restaurant'] ?: null, $filters['kind'] ?: null);
        $recentDevices = $this->sessions->recentDevices($filters['restaurant'] ?: null, $filters['kind'] ?: null);
        $failures = $this->sessions->failureSummaries($liveDevices->concat($recentDevices));
        // The support app live page only needs restaurant cards. Skip the
        // log-table scans until the user opens Logs.
        $cards = $request->boolean('cards');
        $failed = $cards ? collect() : $this->sessions->recentGlobalLogs(
            restaurant: $filters['restaurant'] ?: null,
            kind: $filters['kind'] ?: null,
            level: 'error',
            source: 'api',
            from: $from,
            to: $to,
            limit: 40,
        );
        $logs = $cards ? collect() : $this->sessions->recentGlobalLogs(
            restaurant: $filters['restaurant'] ?: null,
            kind: $filters['kind'] ?: null,
            level: $level,
            source: $source,
            q: $filters['q'] ?: null,
            from: $from,
            to: $to,
            limit: $filters['view'] === 'devices' ? 40 : 120,
        );

        return $this->utf8Json([
            'ok' => true,
            'live' => $liveDevices
                ->map(fn (SupportDevice $device) => $this->sessions->deviceState($device, $failures[$device->id] ?? null))
                ->values(),
            'recent' => $recentDevices
                ->map(fn (SupportDevice $device) => $this->sessions->deviceState($device, $failures[$device->id] ?? null))
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
        $to = $this->sessions->parseDisplayTime($filters['to']);
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
        $to = $this->sessions->parseDisplayTime($filters['to']);
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

    public function device(Request $request, SupportDevice $device): View
    {
        $this->assertDeviceVisible($device);
        $filters = $this->deviceLogFilters($request);
        $resolved = $this->sessions->filtersForView($filters['view'], $filters['level'] ?: null, $filters['source'] ?: null);
        $from = $this->sessions->parseDisplayTime($filters['from']);
        $to = $this->sessions->parseDisplayTime($filters['to']);
        $failures = $this->sessions->failureSummaries([$device]);

        $liveLogs = $this->sessions->recentDeviceLogs(
            $device,
            level: $resolved['level'],
            source: $resolved['source'],
            q: $filters['q'] ?: null,
            from: $from,
            to: $to,
            limit: 120,
        );

        $this->sessions->markWatched($device);

        return view('kaman-support.device', [
            'device' => $device,
            'state' => $this->sessions->deviceState($device, $failures[$device->id] ?? null),
            'commands' => $this->commands->recent($device, 12),
            'commandNames' => SupportCommandName::cases(),
            'liveLogs' => $liveLogs,
            'logs' => $this->sessions->paginateLogs(
                $device,
                level: $resolved['level'],
                source: $resolved['source'],
                q: $filters['q'] ?: null,
                from: $from,
                to: $to,
                page: (int) $request->query('page', 1),
                limit: 100,
                exceptIds: $liveLogs->modelKeys(),
            ),
            'failedHints' => $this->sessions->failedRequestHints(
                restaurant: $device->restaurant,
            ),
            'filters' => $filters,
        ]);
    }

    public function deviceFeed(Request $request, SupportDevice $device): JsonResponse
    {
        $this->assertDeviceVisible($device);
        $this->sessions->markWatched($device);
        $device->refresh();
        $filters = $this->deviceLogFilters($request);
        $resolved = $this->sessions->filtersForView($filters['view'], $filters['level'] ?: null, $filters['source'] ?: null);
        $from = $this->sessions->parseDisplayTime($filters['from']);
        $to = $this->sessions->parseDisplayTime($filters['to']);
        $failures = $this->sessions->failureSummaries([$device]);
        $failed = $this->sessions->recentDeviceLogs(
            $device,
            level: 'error',
            source: 'api',
            from: $from,
            to: $to,
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
                from: $from,
                to: $to,
                limit: 120,
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

    private function assertDeviceVisible(SupportDevice $device): void
    {
        abort_unless($this->sessions->viewerCanSeeDevice($device), 404);
    }

    /**
     * @return array{restaurant: string, kind: string, view: string, q: string, source: string, from: string, to: string}
     */
    private function liveFilters(Request $request): array
    {
        $restaurant = $request->query('restaurant');
        $kind = $request->query('kind');
        $q = $request->query('q');
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            'restaurant' => is_string($restaurant) ? $restaurant : '',
            'kind' => is_string($kind) && in_array($kind, ['tablet', 'desktop', 'web', 'phone'], true) ? $kind : '',
            'view' => $this->sessions->normalizeView($request->query('view'), 'devices'),
            'q' => is_string($q) ? $q : '',
            'source' => $this->sessions->normalizeSource($request->query('source')) ?? '',
            'from' => is_string($from) ? $from : '',
            'to' => is_string($to) ? $to : '',
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
     * @return array{view: string, level: string, source: string, q: string, from: string, to: string}
     */
    private function deviceLogFilters(Request $request): array
    {
        $level = $request->query('level');
        $q = $request->query('q');
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            'view' => $this->sessions->normalizeView($request->query('view'), 'all'),
            'level' => is_string($level) ? $level : '',
            'source' => $this->sessions->normalizeSource($request->query('source')) ?? '',
            'q' => is_string($q) ? $q : '',
            'from' => is_string($from) ? $from : '',
            'to' => is_string($to) ? $to : '',
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function logViewPair(string $view, string $source = ''): array
    {
        $resolved = $this->sessions->filtersForView($view, null, $source !== '' ? $source : null);

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
