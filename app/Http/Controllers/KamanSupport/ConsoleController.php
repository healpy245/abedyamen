<?php

declare(strict_types=1);

namespace App\Http\Controllers\KamanSupport;

use App\Enums\KamanSupport\SupportCommandName;
use App\Enums\KamanSupport\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\KamanSupport\StoreSupportTicketCommandRequest;
use App\Models\FormWorkflowRun;
use App\Models\KamanSupport\MenuAgentChat;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportDeviceLog;
use App\Models\KamanSupport\SupportPrinterIssue;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use App\Services\KamanSupport\LogIssueGrouper;
use App\Services\KamanSupport\RestaurantLogoResolver;
use App\Services\KamanSupport\RestaurantSupport;
use App\Services\KamanSupport\SupportDeviceCommandService;
use App\Services\KamanSupport\SupportDeviceSessionService;
use App\Services\KamanSupport\SupportTicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * The board for the phone app: the same cards the page draws, with the words it needs.
     */
    public function boardFeed(Request $request): JsonResponse
    {
        $devices = $this->sessions->visibleDevices();
        $connectionsOnly = (bool) $request->user()?->supportConnectionsOnly();
        $failures = $connectionsOnly ? [] : $this->sessions->failureSummaries($devices);

        return $this->utf8Json([
            'restaurants' => $this->restaurantCards($devices, $failures, $connectionsOnly),
            'logs' => ! $connectionsOnly,
            'labels' => [
                'title' => __('kaman-support.board_title'),
                'live' => __('kaman-support.ws_live'),
                'online' => __('kaman-support.ws_online'),
                'warning' => __('kaman-support.ws_warning'),
                'offline' => __('kaman-support.ws_offline'),
                'unknown' => __('kaman-support.ws_unknown'),
                'healthy' => __('kaman-support.ws_healthy'),
                'devices' => __('kaman-support.ws_devices'),
                'search' => __('kaman-support.ws_search'),
                'count' => __('kaman-support.ws_device_count', ['online' => ':online', 'total' => ':total']),
                'never' => __('kaman-support.never_seen'),
                'none' => __('kaman-support.ws_none'),
                'mall' => __('kaman-support.ws_cashier_mall'),
                'lite' => __('kaman-support.ws_lite'),
                'recent' => __('kaman-support.ws_recent'),
                'tickets' => __('kaman-support.nav_tickets'),
                'emptyLogs' => __('kaman-support.ws_empty_logs'),
                'empty' => __('kaman-support.empty'),
                'last' => __('kaman-support.ws_last'),
                'viewAllLogs' => __('kaman-support.view_all_logs'),
            ],
        ]);
    }

    /**
     * One restaurant's board card (devices, their state and its latest tickets), for the Support section
     * of the restaurant modal in the other doors. Same data the board's own modal shows.
     */
    public function restaurantCard(Request $request, string $restaurant): JsonResponse
    {
        $devices = $this->sessions->visibleDevices($restaurant);
        $connectionsOnly = (bool) $request->user()?->supportConnectionsOnly();
        $failures = $connectionsOnly ? [] : $this->sessions->failureSummaries($devices);
        $card = collect($this->restaurantCards($devices, $failures, $connectionsOnly))
            ->first(fn (array $card): bool => strcasecmp((string) $card['id'], $restaurant) === 0);

        // A restaurant with no POS devices yet still has its tickets.
        $card ??= [
            'id' => $restaurant,
            'name' => $restaurant,
            'logo' => null,
            'products' => [],
            'online' => 0,
            'total' => 0,
            'status' => 'unknown',
            'lastSeenAt' => null,
            'lastSeen' => '',
            'error' => null,
            'devices' => [],
            'tickets' => SupportTicket::query()->visibleTo(auth()->user())
                ->where('restaurant', $restaurant)
                ->latest('id')
                ->limit($request->user()?->canOpenSubDoor(\App\Enums\Door::Support, 'tickets') ? 6 : 0)
                ->get(['id', 'public_id', 'summary', 'status', 'created_at'])
                ->map(fn (SupportTicket $ticket): array => [
                    'id' => $ticket->public_id,
                    'summary' => (string) $ticket->summary,
                    'status' => $ticket->status?->value,
                    'label' => $ticket->status?->label() ?? '',
                    'at' => $ticket->created_at?->timezone(config('kaman-support.display_timezone', 'Asia/Jerusalem'))->format('Y-m-d H:i'),
                ])->all(),
        ];

        return response()->json(['card' => $card, 'logs' => ! $connectionsOnly]);
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

    /**
     * The inbox for the phone app: the same list and numbers, with the words it needs.
     */
    public function inboxFeed(Request $request, RestaurantLogoResolver $logos): JsonResponse
    {
        $state = (string) $request->query('state', '');
        $q = $request->query('q');
        $query = SupportTicket::query()->visibleTo(auth()->user())->with('closedBy:id,name')->orderByDesc('id');
        if ($state === 'closed') {
            $query->where('status', SupportTicketStatus::Closed);
        } elseif ($state === 'open') {
            $query->where('status', '!=', SupportTicketStatus::Closed->value);
        }
        if (is_string($q) && $q !== '') {
            $query->search($q);
        }
        $paginator = $query->paginate(30);
        $images = $logos->resolve($paginator->getCollection()->pluck('restaurant')->filter()->unique()->values()->all());
        $user = $request->user();

        $total = SupportTicket::query()->visibleTo(auth()->user())->count();
        $closed = SupportTicket::query()->visibleTo(auth()->user())->where('status', SupportTicketStatus::Closed)->count();
        $progress = SupportTicket::query()->visibleTo(auth()->user())->where('status', SupportTicketStatus::InProgress)->count();

        return $this->utf8Json([
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'can_escalate' => (bool) $user?->can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class),
            'stats' => [
                'total' => $total,
                'open' => max(0, $total - $closed - $progress),
                'progress' => $progress,
                'closed' => $closed,
            ],
            'tickets' => collect($paginator->items())->map(function (SupportTicket $ticket) use ($images): array {
                $level = $ticket->reportLevel();

                return [
                    'id' => $ticket->public_id,
                    'title' => (string) $ticket->summary,
                    'details' => (string) $ticket->details,
                    'report' => $ticket->report(),
                    'escalateText' => $ticket->escalationText(),
                    'contact' => $ticket->contact(),
                    'restaurant' => (string) ($ticket->restaurant_name ?: $ticket->restaurant),
                    'slug' => (string) $ticket->restaurant,
                    'logo' => $images[(string) $ticket->restaurant] ?? null,
                    'status' => $ticket->status?->value,
                    'statusLabel' => $ticket->status?->label() ?? '',
                    'closed' => $ticket->status === SupportTicketStatus::Closed,
                    'escalated' => filled($ticket->escalated_ticket_id),
                    'level' => $level,
                    'levelLabel' => $level ? __('kaman-support.level.'.$level) : null,
                    'closeNote' => (string) $ticket->close_note,
                    'closedBy' => $ticket->closedBy === null ? null : __('kaman-support.closed_by', ['name' => $ticket->closedBy->name]),
                    'closeTime' => $ticket->closeDurationLabel() ? __('kaman-support.time_to_close', ['time' => $ticket->closeDurationLabel()]) : null,
                ];
            })->values()->all(),
            'labels' => [
                'title' => __('kaman-support.nav_tickets'),
                'lead' => __('kaman-support.ticket_lead'),
                'all' => __('app-development.tabs.all'),
                'open' => __('app-development.tickets.open_state'),
                'closed' => __('app-development.tickets.closed_state'),
                'total' => __('app-development.tickets.total'),
                'progress' => __('kaman-support.status.in_progress'),
                'empty' => __('kaman-support.empty'),
                'escalate' => __('kaman-support.escalate'),
                'escalateLead' => __('kaman-support.escalate_lead'),
                'escalated' => __('kaman-support.escalated'),
                'close' => __('kaman-support.close_ticket'),
                'closeTitle' => __('kaman-support.close_title'),
                'closeNote' => __('kaman-support.close_note'),
                'details' => __('kaman-support.details'),
                'whatsapp' => __('kaman-support.whatsapp'),
                'contact' => __('kaman-support.contact'),
                'restaurant' => __('kaman-support.restaurant'),
                'device' => __('kaman-support.device'),
                'events' => __('kaman-support.events'),
                'voice' => __('kaman-support.voice'),
                'screen' => __('kaman-support.screen'),
            ],
        ]);
    }

    public function inbox(Request $request): View
    {
        $state = (string) $request->query('state', '');
        if (! in_array($state, ['', 'open', 'closed'], true)) {
            $state = '';
        }
        $q = $request->query('q');
        $query = SupportTicket::query()->visibleTo(auth()->user())->with('closedBy:id,name')->orderByDesc('id');
        if ($state === 'closed') {
            $query->where('status', SupportTicketStatus::Closed);
        } elseif ($state === 'open') {
            $query->where('status', '!=', SupportTicketStatus::Closed->value);
        }
        if (is_string($q) && $q !== '') {
            $query->search($q);
        }
        $paginator = $query->paginate(30)->withQueryString();
        $logos = app(RestaurantLogoResolver::class)->cached(
            $paginator->getCollection()
                ->pluck('restaurant')
                ->filter(fn (mixed $slug): bool => is_string($slug) && $slug !== '')
                ->map(fn (string $slug): string => $slug)
                ->unique()
                ->values()
                ->all(),
        );

        $total = SupportTicket::query()->visibleTo(auth()->user())->count();
        $closed = SupportTicket::query()->visibleTo(auth()->user())->where('status', SupportTicketStatus::Closed)->count();
        $progress = SupportTicket::query()->visibleTo(auth()->user())->where('status', SupportTicketStatus::InProgress)->count();

        return view('kaman-support.inbox', [
            'tickets' => $paginator,
            'state' => $state,
            'search' => is_string($q) ? $q : '',
            'logos' => $logos,
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
            $description = $ticket->escalationText();
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
        $this->attachVoice($ticket, $dev, $request->user());
        $ticket->forceFill(['escalated_ticket_id' => $dev->id])->save();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'ticket' => $dev->ticket_number,
                'message' => __('kaman-support.escalated_as', ['ticket' => $dev->ticket_number]),
            ]);
        }

        return redirect()
            ->route('kaman-support.inbox', ['ticket' => $ticket->public_id])
            ->with('status', $dev->ticket_number);
    }

    /**
     * The cashier's voice note goes with the escalation, so development can play it on their ticket.
     * The screen recording is in the POS's own format and only plays on the support ticket, which the
     * escalated text links to.
     */
    private function attachVoice(SupportTicket $ticket, \App\Models\AppDevelopment\AppDevelopmentTicket $dev, User $user): void
    {
        $path = $ticket->mediaPath('audioPath');
        $disk = Storage::disk('local');
        if ($path === null || ! $disk->exists($path)) {
            return;
        }

        try {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'm4a';
            $target = 'app-development/tickets/'.$dev->id.'/attachments/'.\Illuminate\Support\Str::uuid()->toString().'.'.$extension;
            if (! $disk->copy($path, $target)) {
                return;
            }
            \App\Models\AppDevelopment\AppDevelopmentTicketAttachment::query()->create([
                'ticket_id' => $dev->id,
                'uploaded_by' => $user->id,
                'original_name' => 'voice-'.$ticket->public_id.'.'.$extension,
                'disk' => 'local',
                'path' => $target,
                'mime_type' => match ($extension) {
                    'wav' => 'audio/wav',
                    'mp3' => 'audio/mpeg',
                    'ogg' => 'audio/ogg',
                    default => 'audio/mp4',
                },
                'size' => $disk->size($target),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function logs(Request $request): View
    {
        return view('kaman-support.logs', $this->logsData($request));
    }

    /**
     * The logs explorer for the phone app: the same grouped issues, with the words it needs.
     */
    public function logsFeed(Request $request): JsonResponse
    {
        return $this->utf8Json($this->logsData($request) + [
            'labels' => [
                'deviceLogs' => __('kaman-support.ws_device_logs'),
                'serverLogs' => __('kaman-support.ws_server_logs'),
                'search' => __('kaman-support.ws_search_logs'),
                'searchList' => __('kaman-support.ws_search'),
                'restaurants' => __('kaman-support.ws_restaurants'),
                'time' => __('kaman-support.ws_time'),
                'last15' => __('kaman-support.ws_last_15'),
                'lastHour' => __('kaman-support.ws_last_hour'),
                'today' => __('kaman-support.ws_today'),
                'lastWeek' => __('kaman-support.ws_last_week'),
                'apply' => __('kaman-support.filter'),
                'severity' => __('kaman-support.ws_severity'),
                'category' => __('kaman-support.ws_inv_category'),
                'integration' => __('kaman-support.ws_inv_integration'),
                'status' => __('kaman-support.ws_inv_status'),
                'device' => __('kaman-support.device'),
                'levelError' => __('kaman-support.ws_level_error'),
                'levelWarn' => __('kaman-support.ws_warning'),
                'levelInfo' => __('kaman-support.ws_level_info'),
                'flutter' => __('kaman-support.ws_flutter_short'),
                'print' => __('kaman-support.ws_print_short'),
                'network' => __('kaman-support.ws_network'),
                'reset' => __('kaman-support.ws_reset'),
                'issues' => __('kaman-support.ws_inv_issues'),
                'scope' => __('kaman-support.ws_inv_scope', ['count' => ':count']),
                'empty' => __('kaman-support.ws_empty_logs'),
                'noMatch' => __('kaman-support.ws_inv_no_match'),
                'serverTitle' => __('kaman-support.ws_inv_server_title'),
                'serverSub' => __('kaman-support.ws_inv_server_sub'),
                'select' => __('kaman-support.ws_inv_select'),
                'pending' => __('kaman-support.ws_inv_pending'),
                'affected' => __('kaman-support.ws_inv_affected'),
                'technical' => __('kaman-support.ws_inv_technical'),
                'copy' => __('kaman-support.ws_inv_copy_json'),
                'copied' => __('kaman-support.ws_inv_copied'),
                'endpoint' => __('kaman-support.ws_inv_endpoint'),
                'body' => __('kaman-support.ws_inv_body'),
                'message' => __('kaman-support.ws_inv_message'),
                'stack' => __('kaman-support.ws_inv_stack'),
                'exchange' => __('kaman-support.ws_inv_request'),
                'meta' => __('kaman-support.ws_inv_meta'),
                'mall' => __('kaman-support.ws_cashier_mall'),
                'lite' => __('kaman-support.ws_lite'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function logsData(Request $request): array
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
        // Card-terminal refusals are filed on the server lane. Every other
        // error the tablet uploaded stays on the device lane.
        $logs = $this->sessions->recentGlobalLogs(...$shared, level: 'error', lane: $lane);

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
                'product' => (string) ($device?->productKey() ?? ''),
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

        $choices = $this->logRestaurantChoices($picked);
        $logos = array_filter(array_column($choices, 'logo', 'value')) + app(RestaurantLogoResolver::class)->cached(array_merge(
            array_column($issues, 'restaurantId'),
            array_column($choices, 'value'),
        ));
        foreach ($issues as $index => $issue) {
            $id = (string) ($issue['restaurantId'] ?? '');
            $issues[$index]['logo'] = $id !== '' ? ($logos[$id] ?? null) : null;
            $issues[$index]['products'] = $this->logProductKeys($issue);
        }

        return [
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
        ];
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
            'chats' => $user === null ? [] : MenuAgentChat::query()
                ->where('user_id', $user->id)
                ->latest('updated_at')
                ->limit(50)
                ->get(['id', 'subdomain', 'title', 'updated_at'])
                ->map(fn (MenuAgentChat $chat): array => [
                    'id' => $chat->id,
                    'subdomain' => $chat->subdomain,
                    'title' => $chat->title,
                    'updated' => $chat->updated_at?->diffForHumans() ?? '',
                ])->all(),
            'chatsUrl' => route('kaman-support.menu.chats.store'),
            // __ID__ is swapped for the chat id in the page script.
            'chatUrlTemplate' => route('kaman-support.menu.chats.show', ['chat' => '__ID__']),
        ]);
    }

    /**
     * One ticket for the ticket modal. There is no ticket page: an old link lands on the inbox with the modal open.
     */
    public function show(Request $request, SupportTicket $ticket, RestaurantSupport $support, RestaurantLogoResolver $logos): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return redirect()->route('kaman-support.inbox', ['ticket' => $ticket->public_id]);
        }

        $ticket->load('events');
        $timezone = config('kaman-support.display_timezone', 'Asia/Jerusalem');
        $level = $ticket->reportLevel();
        // The "created" event repeats the summary word for word; the description is shown once, above.
        $repeats = array_filter([trim((string) $ticket->summary), trim((string) $ticket->details)]);
        $canEscalate = (bool) $request->user()?->can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class);

        return response()->json([
            'ticket' => [
                'id' => $ticket->public_id,
                'title' => (string) $ticket->summary,
                'details' => (string) $ticket->details,
                'report' => $ticket->report(),
                'contact' => $ticket->contact(),
                'cashier' => (string) $ticket->cashier_name,
                'slug' => (string) $ticket->restaurant,
                'logo' => $logos->cached([(string) $ticket->restaurant])[strtolower(trim((string) $ticket->restaurant))] ?? null,
                'voiceSeconds' => $ticket->hasVoice() ? $ticket->mediaSeconds('voice') : null,
                'screenSeconds' => $ticket->hasScreen() ? $ticket->mediaSeconds('screen') : null,
                'escalated' => filled($ticket->escalated_ticket_id),
                'escalateText' => $canEscalate ? $ticket->escalationText() : null,
                'status' => $ticket->status?->value,
                'statusLabel' => $ticket->status?->label() ?? '',
                'level' => $level,
                'levelLabel' => $level ? __('kaman-support.level.'.$level) : null,
                'restaurant' => (string) ($ticket->restaurant_name ?: $ticket->restaurant),
                'restaurantKey' => $support->keyFor($request->user(), $ticket->restaurant),
                'device' => $ticket->device_id,
                'at' => $ticket->created_at?->timezone($timezone)->format('Y-m-d H:i'),
                'voice' => $ticket->hasVoice() ? route('kaman-support.tickets.audio', $ticket) : null,
                'screen' => $ticket->hasScreen() ? route('kaman-support.tickets.screen', $ticket) : null,
                'events' => $ticket->events->map(fn ($event): array => [
                    'type' => $event->type?->label() ?? '',
                    'actor' => (string) $event->actor_name,
                    'at' => $event->created_at?->timezone($timezone)->format('Y-m-d H:i'),
                    'message' => in_array(trim((string) $event->message), $repeats, true) ? '' : (string) $event->message,
                ])->values()->all(),
            ],
            'copy' => [
                'details' => __('kaman-support.details'),
                'report' => __('kaman-support.report'),
                'noReport' => __('kaman-support.no_written_report'),
                'cashier' => __('kaman-support.cashier'),
                'whatsapp' => __('kaman-support.whatsapp'),
                'contact' => __('kaman-support.contact'),
                'restaurant' => __('kaman-support.restaurant'),
                'device' => __('kaman-support.device'),
                'voice' => __('kaman-support.voice'),
                'screen' => __('kaman-support.screen'),
                'events' => __('kaman-support.events'),
                'close' => __('kaman-support.ws_close'),
            ],
        ]);
    }

    public function audio(SupportTicket $ticket): StreamedResponse
    {
        return $this->recording($ticket, 'audioPath');
    }

    public function screen(SupportTicket $ticket): StreamedResponse
    {
        return $this->recording($ticket, 'screenPath');
    }

    private function recording(SupportTicket $ticket, string $key): StreamedResponse
    {
        $path = $ticket->mediaPath($key);
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        $name = basename($path);
        $mime = match (true) {
            str_ends_with($name, '.wav') => 'audio/wav',
            str_ends_with($name, '.m4a'), str_ends_with($name, '.aac') => 'audio/mp4',
            str_ends_with($name, '.mp3') => 'audio/mpeg',
            str_ends_with($name, '.ogg') => 'audio/ogg',
            default => 'application/octet-stream',
        };

        return Storage::disk('local')->response($path, $name, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
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

    /**
     * Kitchen tickets that did not print, one row per order and kitchen.
     */
    public function printerIssues(Request $request): View
    {
        return view('kaman-support.printer-issues', $this->printerIssuesData($request, 300) + [
            'restaurants' => $this->sessions->restaurantChoices(),
            'faults' => SupportPrinterIssue::FAULTS,
            'sessions' => $this->sessions,
        ]);
    }

    /**
     * The printer issues page for the phone app: the same rows, with every word already translated.
     */
    public function printerIssuesFeed(Request $request): JsonResponse
    {
        $data = $this->printerIssuesData($request, 200);
        $named = static function (string $group, ?string $value): string {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            $key = 'kaman-support.printer_issues.'.$group.'.'.$value;
            $label = __($key);

            return $label === $key ? $value : (string) $label;
        };
        $attemptLabel = static function (string $attempt): string {
            $key = 'kaman-support.printer_issues.attempt_'.$attempt;
            $label = __($key);

            return $label === $key ? $attempt : (string) $label;
        };

        $issues = [];
        foreach ($data['issues'] as $issue) {
            $device = $issue->device;
            $timeline = [];
            foreach (array_reverse(is_array($issue->attempts_log) ? $issue->attempts_log : []) as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $at = null;
                if (isset($step['at']) && is_string($step['at'])) {
                    try {
                        $at = Carbon::parse($step['at']);
                    } catch (\Throwable) {
                    }
                }
                $event = (string) ($step['event'] ?? 'failed');
                $timeline[] = array_filter([
                    'at' => $this->sessions->formatDisplayTime($at, 'm-d H:i:s'),
                    'event' => $event,
                    'eventLabel' => (string) __('kaman-support.printer_issues.event_'.$event),
                    'attempt' => ! empty($step['attempt']) ? $attemptLabel((string) $step['attempt']) : null,
                    'fault' => ! empty($step['fault']) ? $named('faults', (string) $step['fault']) : null,
                    'mayHavePrinted' => ! empty($step['mayHavePrinted']) ? true : null,
                    'printer' => isset($step['printer']) && is_scalar($step['printer']) ? (string) $step['printer'] : null,
                    'seconds' => isset($step['elapsedMs']) && is_numeric($step['elapsedMs']) ? round(((int) $step['elapsedMs']) / 1000, 1) : null,
                    'printedOn' => ! empty($step['printedOn']) && is_array($step['printedOn']) ? array_values($step['printedOn']) : null,
                    'configuredPrinters' => ! empty($step['configuredPrinters']) && is_array($step['configuredPrinters']) ? array_values($step['configuredPrinters']) : null,
                    'cashier' => isset($step['cashier']) && is_scalar($step['cashier']) ? (string) $step['cashier'] : null,
                    'error' => isset($step['error']) && is_scalar($step['error']) ? (string) $step['error'] : null,
                ], static fn ($value) => $value !== null && $value !== '');
            }
            $minutes = $issue->minutesToResolve();
            $link = trim((string) $issue->printer_link);
            $issues[] = [
                'id' => $issue->id,
                'open' => $issue->isOpen(),
                'fault' => (string) $issue->fault,
                'faultLabel' => $named('faults', $issue->fault),
                'mayHavePrinted' => (bool) $issue->may_have_printed,
                'station' => (string) ($issue->station ?? ''),
                'orderNumber' => $issue->order_number,
                'orderType' => $named('types', $issue->order_type),
                'table' => (string) ($issue->order_table ?? ''),
                'external' => (string) ($issue->external_order_id ?? ''),
                'channel' => $named('channels', $issue->order_channel),
                'where' => $named('classes', $issue->fault_class),
                'restaurant' => $device?->displayRestaurant() ?? (string) $issue->restaurant,
                'printerName' => (string) ($issue->printer_name ?? ''),
                'printerAddress' => $issue->printerAddress(),
                'printerLink' => $link !== '' && $link !== 'network' ? $named('links', $link) : '',
                'printerMac' => (string) ($issue->printer_mac ?? ''),
                'attempts' => (int) $issue->attempts,
                'firstFailed' => $this->sessions->formatDisplayTime($issue->first_failed_at),
                'lastFailed' => $this->sessions->formatDisplayTime($issue->last_failed_at),
                'cashier' => (string) ($issue->cashier_name ?? ''),
                'device' => $device ? ['id' => $device->id, 'name' => (string) ($device->hostname ?: $device->device_id)] : null,
                'version' => (string) ($issue->app_version ?? ''),
                'resolvedAt' => $this->sessions->formatDisplayTime($issue->resolved_at),
                'resolution' => $issue->isOpen() ? '' : (string) ($issue->resolution === 'skipped'
                    ? __('kaman-support.printer_issues.resolution_skipped')
                    : __('kaman-support.printer_issues.resolution_printed')),
                'took' => $minutes === null ? '' : (string) __('kaman-support.printer_issues.took', ['minutes' => $minutes]),
                'dismissed' => $issue->dismissed_count > 0 ? (string) __('kaman-support.printer_issues.dismissed', ['count' => $issue->dismissed_count]) : '',
                'reason' => (string) ($issue->reason ?? ''),
                'error' => (string) ($issue->error_detail ?? ''),
                'printedOn' => array_values(is_array($issue->printed_on) ? $issue->printed_on : []),
                'configuredPrinters' => array_values(is_array($issue->configured_printers) ? $issue->configured_printers : []),
                'meals' => array_values(array_map(static fn (array $meal): array => [
                    'qty' => (int) ($meal['qty'] ?? 1),
                    'name' => (string) ($meal['name'] ?? ($meal['line'] ?? '')),
                    'note' => (string) ($meal['note'] ?? ''),
                ], array_filter(is_array($issue->meals) ? $issue->meals : [], 'is_array'))),
                'timeline' => $timeline,
            ];
        }

        $labels = [];
        foreach ([
            'title', 'subtitle', 'status_open', 'status_resolved', 'status_all', 'all_faults', 'fault', 'empty',
            'open_count', 'uncertain_count', 'resolved_week', 'kitchen', 'printer', 'order', 'table', 'channel',
            'where', 'external', 'printed_on', 'configured_printers', 'no_kitchen_matched', 'device', 'cashier',
            'version', 'attempts', 'first_failed', 'last_failed', 'resolved_at', 'may_have_printed', 'meals',
            'timeline', 'reason', 'error',
        ] as $key) {
            $labels[$key] = (string) __('kaman-support.printer_issues.'.$key);
        }
        $labels['restaurant'] = (string) __('kaman-support.filter_restaurant');
        $labels['allRestaurants'] = (string) __('kaman-support.all_restaurants');
        $labels['search'] = (string) __('kaman-support.log_search');

        return $this->utf8Json([
            'ok' => true,
            'filters' => $data['filters'],
            'counts' => $data['counts'],
            'faults' => array_map(static fn (string $code): array => ['value' => $code, 'label' => $named('faults', $code)], SupportPrinterIssue::FAULTS),
            'choices' => $this->sessions->restaurantChoices(),
            'issues' => $issues,
            'labels' => $labels,
        ]);
    }

    /**
     * The filtered printer issues and the page counts, shared by the web page and the app feed.
     *
     * @return array{issues: \Illuminate\Support\Collection<int, SupportPrinterIssue>, filters: array<string, string>, counts: array<string, int>}
     */
    private function printerIssuesData(Request $request, int $limit): array
    {
        $status = $request->query('status');
        $status = in_array($status, ['open', 'resolved', 'all'], true) ? $status : 'open';
        $fault = $request->query('fault');
        $fault = in_array($fault, SupportPrinterIssue::FAULTS, true) ? $fault : '';
        $filters = $this->failedFilters($request) + ['status' => $status, 'fault' => $fault];
        $from = $this->sessions->parseDisplayTime($filters['from']);
        $to = $this->sessions->parseDisplayTime($filters['to'], true);

        $scoped = function () use ($request, $filters) {
            $query = SupportPrinterIssue::query();
            $user = $request->user();
            if ($user instanceof User && $user->supportProduct() !== null) {
                $query->whereHas('device', fn ($device) => $device->visibleTo($user));
            }
            $restaurant = trim($filters['restaurant']);
            if ($restaurant !== '') {
                $query->where(function ($inner) use ($restaurant): void {
                    $inner->where('restaurant', $restaurant)
                        ->orWhereHas('device', fn ($device) => $device->where('restaurant_name', $restaurant));
                });
            }

            return $query;
        };

        $query = $scoped()->with('device')->orderByDesc('last_failed_at')->orderByDesc('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($fault !== '') {
            $query->where('fault', $fault);
        }
        if ($from !== null) {
            $query->where('last_failed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('first_failed_at', '<=', $to);
        }
        $term = trim($filters['q']);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($inner) use ($term, $like): void {
                $inner->where('station', 'like', $like)
                    ->orWhere('printer_ip', 'like', $like)
                    ->orWhere('printer_name', 'like', $like)
                    ->orWhere('order_id', 'like', $like)
                    ->orWhere('external_order_id', 'like', $like)
                    ->orWhere('cashier_name', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('meals', 'like', $like);
                if (ctype_digit($term)) {
                    $inner->orWhere('order_number', (int) $term);
                }
            });
        }

        return [
            'issues' => $query->limit($limit)->get(),
            'filters' => $filters,
            'counts' => [
                'open' => $scoped()->where('status', SupportPrinterIssue::STATUS_OPEN)->count(),
                'uncertain' => $scoped()->where('status', SupportPrinterIssue::STATUS_OPEN)->where('may_have_printed', true)->count(),
                'resolvedWeek' => $scoped()->where('status', SupportPrinterIssue::STATUS_RESOLVED)
                    ->where('resolved_at', '>=', now()->subDays(7))->count(),
            ],
        ];
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
        if ($request->user()?->limitedSupportConsole()) {
            return redirect()->route('kaman-support.live');
        }

        return redirect()->route('kaman-support.index', ['open' => $device->restaurant]);
    }

    public function deviceFeed(Request $request, SupportDevice $device): JsonResponse
    {
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
                'product' => $device->productKey(),
                'hostname' => (string) $device->hostname,
                'online' => $online,
                'old' => $device->isOld(),
                'status' => $status,
                'appState' => (string) $device->app_state,
                'lastSeenAt' => $seen?->toIso8601String(),
                'lastSeen' => $seen?->diffForHumans() ?? '',
                'error' => $message !== '' ? $message : null,
                'href' => route('kaman-support.device', $device),
            ];
        }

        $keys = array_keys($grouped);
        // A board card lists its tickets only for people who can open Tickets.
        $tickets = $keys === [] || ! auth()->user()?->canOpenSubDoor(\App\Enums\Door::Support, 'tickets')
            ? collect()
            : SupportTicket::query()->visibleTo(auth()->user())
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
     * Online devices win. If nobody is online, the last known devices still label the restaurant.
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
            $keys[(string) ($row['product'] ?? '')] = true;
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
        foreach (is_array($issue['occurrences'] ?? null) ? $issue['occurrences'] : [] as $occurrence) {
            if (is_array($occurrence)) {
                $keys[(string) ($occurrence['product'] ?? '')] = true;
            }
        }

        return array_values(array_filter(
            ['mall', 'lite'],
            static fn (string $key): bool => isset($keys[$key]),
        ));
    }

    /**
     * The restaurant filter on the logs page: the real restaurants from kaman.rest, not every slug a POS
     * app has reported. Values keep the devices' spelling, since that is what the logs are matched on.
     * A restaurant already picked stays listed so it can be unticked. If kaman.rest is unreachable the
     * filter falls back to the reported restaurants.
     *
     * @param  list<string>  $picked
     * @return list<array{value: string, label: string, logo?: string}>
     */
    private function logRestaurantChoices(array $picked): array
    {
        $reported = [];
        foreach ($this->sessions->reportedRestaurantChoices() as $choice) {
            $reported[mb_strtolower($choice['value'])] ??= $choice;
        }
        $choices = [];
        foreach ($this->sessions->restaurantChoices() as $choice) {
            $choices[mb_strtolower($choice['value'])] = $choice;
        }
        foreach ($picked as $slug) {
            $key = mb_strtolower($slug);
            $choices[$key] ??= ['value' => $slug, 'label' => $reported[$key]['label'] ?? $slug, 'logo' => ''];
        }
        uasort($choices, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return array_values($choices);
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
                    'product' => $device->productKey(),
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
