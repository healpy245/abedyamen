<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentAppType;
use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\AppDevelopmentTicketActivityType;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Http\Controllers\Controller;
use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketActivity;
use App\Models\AppDevelopment\AppDevelopmentTicketAppType;
use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->canOpenSubDoor(\App\Enums\Door::Development, 'reports'), 403);

        [$from, $to, $preset] = $this->range($request);

        return view('app-development.reports.index', [
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'dash' => $this->dashboard($from, $to),
        ]);
    }

    public function exportSummary(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canOpenSubDoor(\App\Enums\Door::Development, 'reports'), 403);
        [$from, $to, $preset] = $this->range($request);
        $dash = $this->dashboard($from, $to);

        $rows = [
            ['summary', 'preset', $preset],
            ['summary', 'period_from', $from->toDateString()],
            ['summary', 'period_to', $to->toDateString()],
            ['summary', 'tasks_completed', $dash['completed']['value']],
            ['summary', 'open_tasks', $dash['open']['value']],
            ['summary', 'average_cycle_days', $dash['cycle']['days']],
            ['summary', 'apk_releases', $dash['releases']['value']],
        ];

        foreach ($dash['statuses'] as $status) {
            $rows[] = ['status', $status['label'], $status['count']];
        }

        foreach ($dash['apps'] as $app) {
            $rows[] = ['application', $app['label'], $app['count']];
        }

        foreach ($dash['trend'] as $point) {
            $rows[] = ['trend', $point['label'], $point['created'].' created / '.$point['completed'].' completed'];
        }

        foreach ($dash['activity'] as $event) {
            $rows[] = ['activity', $event['title'], trim($event['detail'].' '.$event['when'])];
        }

        return $this->csv('development-report.csv', ['section', 'label', 'value'], $rows);
    }

    public function exportTickets(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canOpenSubDoor(\App\Enums\Door::Development, 'reports'), 403);
        [$from, $to] = $this->range($request);

        $rows = AppDevelopmentTicket::query()
            ->with(['creator:id,name', 'appTypeRows'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get();

        return $this->csv('tickets-report.csv', [
            'ticket_number', 'title', 'status', 'priority', 'app_types', 'created_by', 'created_at', 'completed_at',
        ], $rows->map(static function (AppDevelopmentTicket $ticket): array {
            return [
                $ticket->ticket_number,
                $ticket->title,
                $ticket->status?->value,
                $ticket->priority?->value,
                collect($ticket->appTypes())->map->value->implode('|'),
                $ticket->creator?->name,
                $ticket->created_at?->toDateTimeString(),
                $ticket->completed_at?->toDateTimeString(),
            ];
        })->all());
    }

    public function exportTasks(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canOpenSubDoor(\App\Enums\Door::Development, 'reports'), 403);
        [$from, $to] = $this->range($request);

        if (! Schema::hasTable('app_development_tasks')) {
            return $this->csv('tasks-report.csv', ['message'], [['Tasks table missing']]);
        }

        $rows = AppDevelopmentTask::query()
            ->with(['ticket:id,ticket_number', 'assignee:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get();

        return $this->csv('tasks-report.csv', [
            'id', 'title', 'ticket', 'status', 'priority', 'assignee', 'due_at', 'completed_at', 'created_at',
        ], $rows->map(static function (AppDevelopmentTask $task): array {
            return [
                $task->id,
                $task->title,
                $task->ticket?->ticket_number,
                $task->status?->value,
                $task->priority?->value,
                $task->assignee?->name,
                $task->due_at?->toDateString(),
                $task->completed_at?->toDateTimeString(),
                $task->created_at?->toDateTimeString(),
            ];
        })->all());
    }

    public function exportTime(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canOpenSubDoor(\App\Enums\Door::Development, 'reports'), 403);
        [$from, $to] = $this->range($request);

        if (! Schema::hasTable('app_development_time_entries')) {
            return $this->csv('time-report.csv', ['message'], [['Time entries table missing']]);
        }

        $rows = AppDevelopmentTimeEntry::query()
            ->with(['user:id,name', 'task:id,title,ticket_id', 'task.ticket:id,ticket_number'])
            ->whereBetween('started_at', [$from, $to])
            ->orderBy('id')
            ->get();

        return $this->csv('time-report.csv', [
            'id', 'user', 'task', 'ticket', 'started_at', 'ended_at', 'duration_seconds', 'note',
        ], $rows->map(static function (AppDevelopmentTimeEntry $entry): array {
            return [
                $entry->id,
                $entry->user?->name,
                $entry->task?->title,
                $entry->task?->ticket?->ticket_number,
                $entry->started_at?->toDateTimeString(),
                $entry->ended_at?->toDateTimeString(),
                $entry->duration_seconds ?? $entry->elapsedSeconds(),
                $entry->note,
            ];
        })->all());
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public function range(Request $request): array
    {
        $preset = (string) $request->query('preset', 'last_30');
        $now = Carbon::now()->endOfDay();

        [$from, $to] = match ($preset) {
            'today' => [Carbon::today(), $now],
            'last_7' => [Carbon::now()->subDays(6)->startOfDay(), $now],
            'last_90' => [Carbon::now()->subDays(89)->startOfDay(), $now],
            'this_month' => [Carbon::now()->startOfMonth(), $now],
            'last_month' => [Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth()],
            'custom' => [
                Carbon::parse((string) $request->query('from', Carbon::now()->subDays(29)->toDateString()))->startOfDay(),
                Carbon::parse((string) $request->query('to', Carbon::now()->toDateString()))->endOfDay(),
            ],
            default => [Carbon::now()->subDays(29)->startOfDay(), $now],
        };

        return [$from, $to, $preset === '' ? 'last_30' : $preset];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketStats(Carbon $from, Carbon $to): array
    {
        $base = AppDevelopmentTicket::query()->whereBetween('created_at', [$from, $to]);

        $byStatus = [];
        foreach (AppDevelopmentTicketStatus::cases() as $status) {
            $byStatus[$status->value] = (clone $base)->where('status', $status)->count();
        }

        $byPriority = [];
        foreach (AppDevelopmentTicketPriority::cases() as $priority) {
            $byPriority[$priority->value] = (clone $base)->where('priority', $priority)->count();
        }

        $byAppType = [];
        foreach (AppDevelopmentAppType::cases() as $type) {
            $byAppType[$type->value] = AppDevelopmentTicketAppType::query()
                ->where('app_type', $type->value)
                ->whereHas('ticket', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
                ->count();
        }

        $completedTickets = AppDevelopmentTicket::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->get(['created_at', 'completed_at']);

        $avgCloseHours = null;
        if ($completedTickets->isNotEmpty()) {
            $avgCloseHours = round($completedTickets->avg(static function (AppDevelopmentTicket $ticket): float {
                return (float) $ticket->created_at->diffInSeconds($ticket->completed_at);
            }) / 3600, 1);
        }
        $openByCreator = AppDevelopmentTicket::query()
            ->select('created_by', DB::raw('count(*) as aggregate'))
            ->where('status', '!=', AppDevelopmentTicketStatus::Completed)
            ->groupBy('created_by')
            ->with('creator:id,name')
            ->get();

        $timeline = AppDevelopmentTicket::query()
            ->selectRaw('DATE(created_at) as day, count(*) as aggregate')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        return [
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'by_app_type' => $byAppType,
            'avg_close_hours' => $avgCloseHours,
            'open_by_creator' => $openByCreator,
            'timeline' => $timeline,
            'total' => (clone $base)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskStats(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('app_development_tasks')) {
            return ['available' => false];
        }

        $base = AppDevelopmentTask::query()->whereBetween('created_at', [$from, $to]);
        $byStatus = [];
        foreach (AppDevelopmentTaskStatus::cases() as $status) {
            $byStatus[$status->value] = (clone $base)->where('status', $status)->count();
        }

        $completed = (clone $base)->where('status', AppDevelopmentTaskStatus::Completed)->count();
        $overdue = AppDevelopmentTask::query()
            ->where('status', '!=', AppDevelopmentTaskStatus::Completed)
            ->whereNotNull('due_at')
            ->where('due_at', '<', Carbon::now())
            ->count();

        $byAssignee = AppDevelopmentTask::query()
            ->select('assignee_id', DB::raw('count(*) as aggregate'))
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('assignee_id')
            ->groupBy('assignee_id')
            ->with('assignee:id,name')
            ->get();

        $completedTasks = AppDevelopmentTask::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->get(['created_at', 'completed_at']);

        $avgCompleteHours = null;
        if ($completedTasks->isNotEmpty()) {
            $avgCompleteHours = round($completedTasks->avg(static function (AppDevelopmentTask $task): float {
                return (float) $task->created_at->diffInSeconds($task->completed_at);
            }) / 3600, 1);
        }

        return [
            'available' => true,
            'by_status' => $byStatus,
            'completed' => $completed,
            'overdue' => $overdue,
            'by_assignee' => $byAssignee,
            'avg_complete_hours' => $avgCompleteHours,
            'total' => (clone $base)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timeStats(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('app_development_time_entries')) {
            return ['available' => false];
        }

        $entries = AppDevelopmentTimeEntry::query()
            ->with(['user:id,name', 'task:id,title,ticket_id', 'task.ticket:id,ticket_number', 'task.ticket.appTypeRows'])
            ->whereBetween('started_at', [$from, $to])
            ->orderByDesc('started_at')
            ->limit(200)
            ->get();

        $byUser = [];
        foreach ($entries as $entry) {
            $uid = (int) $entry->user_id;
            $byUser[$uid] ??= ['user' => $entry->user, 'seconds' => 0];
            $byUser[$uid]['seconds'] += (int) ($entry->duration_seconds ?? $entry->elapsedSeconds());
        }

        usort($byUser, static fn ($a, $b): int => $b['seconds'] <=> $a['seconds']);

        return [
            'available' => true,
            'entries' => $entries,
            'leaderboard' => array_values($byUser),
            'total_seconds' => array_sum(array_column($byUser, 'seconds')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Carbon $from, Carbon $to): array
    {
        $empty = [
            'available' => false,
            'completed' => ['value' => null, 'delta' => null, 'spark' => null],
            'open' => ['value' => null, 'delta' => null, 'spark' => null],
            'cycle' => ['days' => null, 'delta' => null, 'spark' => null],
            'releases' => ['value' => null, 'delta' => null, 'spark' => null],
            'trend' => [],
            'trend_max' => 0,
            'statuses' => [],
            'status_total' => 0,
            'donut' => null,
            'apps' => [],
            'activity' => [],
        ];

        if (! Schema::hasTable('app_development_tasks')) {
            return $empty;
        }

        $span = max(1, (int) $from->diffInSeconds($to));
        $previousTo = $from->copy()->subSecond();
        $previousFrom = $previousTo->copy()->subSeconds($span);

        $completed = $this->completedTasks($from, $to);
        $previousCompleted = $this->completedTasks($previousFrom, $previousTo);
        $open = $this->openTasks($from, $to);
        $previousOpen = $this->openTasks($previousFrom, $previousTo);
        $releases = $this->releaseCount($from, $to);
        $previousReleases = $this->releaseCount($previousFrom, $previousTo);
        $trend = $this->trend($from, $to);
        $statuses = $this->statusBreakdown($from, $to);
        $statusTotal = array_sum(array_column($statuses, 'count'));

        return [
            'available' => true,
            'completed' => [
                'value' => $completed['count'],
                'delta' => $this->change($completed['count'], $previousCompleted['count']),
                'spark' => $this->spark(array_column($trend, 'completed')),
            ],
            'open' => [
                'value' => $open,
                'delta' => $this->change($open, $previousOpen),
                'spark' => null,
            ],
            'cycle' => [
                'days' => $completed['days'],
                'delta' => $this->change($completed['days'], $previousCompleted['days']),
                'spark' => $this->spark($completed['daily']),
            ],
            'releases' => [
                'value' => $releases['count'],
                'delta' => $this->change($releases['count'], $previousReleases['count']),
                'spark' => $this->spark($releases['daily']),
            ],
            'trend' => $trend,
            'trend_max' => max(1, ...array_merge([0], array_column($trend, 'created'), array_column($trend, 'completed'))),
            'statuses' => $statuses,
            'status_total' => $statusTotal,
            'donut' => $this->donut($statuses, $statusTotal),
            'apps' => $this->appWorkload($from, $to),
            'activity' => $this->activity($from, $to),
        ];
    }

    /**
     * @return array{count: int, days: float|null, daily: list<float>}
     */
    private function completedTasks(Carbon $from, Carbon $to): array
    {
        $rows = AppDevelopmentTask::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->get(['created_at', 'completed_at']);

        $daily = [];
        $seconds = [];
        foreach ($rows as $task) {
            if ($task->created_at === null || $task->completed_at === null) {
                continue;
            }
            $seconds[] = max(0, (float) $task->created_at->diffInSeconds($task->completed_at));
            $key = $task->completed_at->toDateString();
            $daily[$key] = ($daily[$key] ?? 0) + ($seconds[array_key_last($seconds)] / 86400);
        }

        return [
            'count' => $rows->count(),
            'days' => $seconds === [] ? null : round(array_sum($seconds) / count($seconds) / 86400, 1),
            'daily' => array_values($daily),
        ];
    }

    private function openTasks(Carbon $from, Carbon $to): int
    {
        return AppDevelopmentTask::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', AppDevelopmentTaskStatus::Completed)
            ->count();
    }

    /**
     * @return array{count: int, daily: list<int>}
     */
    private function releaseCount(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('app_development_releases')) {
            return ['count' => 0, 'daily' => []];
        }

        $counts = AppDevelopmentRelease::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        return [
            'count' => (int) $counts->sum(),
            'daily' => array_map('intval', $counts->values()->all()),
        ];
    }

    /**
     * @return list<array{label: string, created: int, completed: int}>
     */
    private function trend(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $days = max(1, $start->diffInDays($end->copy()->startOfDay()) + 1);
        $step = $days <= 16 ? 1 : ($days <= 45 ? 3 : 7);

        $created = AppDevelopmentTask::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('day')
            ->pluck('aggregate', 'day');
        $completed = AppDevelopmentTask::query()
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as aggregate')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end])
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        $points = [];
        $cursor = $start->copy();
        while ($cursor->lte($end) && count($points) < 40) {
            $bucketEnd = $cursor->copy()->addDays($step - 1)->endOfDay();
            if ($bucketEnd->gt($end)) {
                $bucketEnd = $end->copy();
            }

            $createdCount = 0;
            $completedCount = 0;
            $walk = $cursor->copy();
            while ($walk->lte($bucketEnd)) {
                $key = $walk->toDateString();
                $createdCount += (int) ($created[$key] ?? 0);
                $completedCount += (int) ($completed[$key] ?? 0);
                $walk->addDay();
            }

            $points[] = [
                'label' => $cursor->format('n/j'),
                'created' => $createdCount,
                'completed' => $completedCount,
            ];
            $cursor = $bucketEnd->copy()->addSecond()->startOfDay();
        }

        return $points;
    }

    /**
     * @return list<array{label: string, count: int, color: string}>
     */
    private function statusBreakdown(Carbon $from, Carbon $to): array
    {
        $counts = AppDevelopmentTask::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $colors = [
            AppDevelopmentTaskStatus::Todo->value => '#528ef0',
            AppDevelopmentTaskStatus::InProgress->value => '#f58220',
            AppDevelopmentTaskStatus::QaTesting->value => '#f3bd4e',
            AppDevelopmentTaskStatus::PushedToGit->value => '#8b7cf6',
            AppDevelopmentTaskStatus::Completed->value => '#21b58d',
        ];

        $rows = [];
        foreach (AppDevelopmentTaskStatus::cases() as $status) {
            $rows[] = [
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
                'color' => $colors[$status->value],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, count: int, color: string}>  $statuses
     */
    private function donut(array $statuses, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }

        $parts = [];
        $cursor = 0.0;
        foreach ($statuses as $status) {
            if ($status['count'] === 0) {
                continue;
            }
            $end = $cursor + ($status['count'] / $total * 100);
            $parts[] = $status['color'].' '.round($cursor, 2).'% '.round($end, 2).'%';
            $cursor = $end;
        }

        return 'conic-gradient('.implode(',', $parts).')';
    }

    /**
     * @return list<array{label: string, count: int, percent: float}>
     */
    private function appWorkload(Carbon $from, Carbon $to): array
    {
        $rows = [];
        foreach (AppDevelopmentAppType::cases() as $type) {
            $count = AppDevelopmentTask::query()
                ->whereBetween('created_at', [$from, $to])
                ->whereHas('ticket.appTypeRows', fn ($query) => $query->where('app_type', $type->value))
                ->count();
            if ($count > 0) {
                $rows[] = ['label' => $type->label(), 'count' => $count];
            }
        }

        $unassigned = AppDevelopmentTask::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereDoesntHave('ticket.appTypeRows')
            ->count();
        if ($unassigned > 0) {
            $rows[] = ['label' => __('app-development.reports.no_app_type'), 'count' => $unassigned];
        }

        $max = max(1, ...array_merge([0], array_column($rows, 'count')));

        return array_map(static function (array $row) use ($max): array {
            $row['percent'] = round($row['count'] / $max * 100, 1);

            return $row;
        }, $rows);
    }

    /**
     * @return list<array{title: string, detail: string, when: string, tone: string}>
     */
    private function activity(Carbon $from, Carbon $to): array
    {
        $events = [];

        if (Schema::hasTable('app_development_ticket_activities')) {
            $activities = AppDevelopmentTicketActivity::query()
                ->with(['user:id,name', 'ticket:id,ticket_number'])
                ->whereBetween('created_at', [$from, $to])
                ->orderByDesc('created_at')
                ->limit(12)
                ->get();

            foreach ($activities as $activity) {
                if ($activity->created_at === null) {
                    continue;
                }
                $events[] = [
                    'at' => $activity->created_at,
                    'title' => $activity->message(),
                    'detail' => (string) ($activity->ticket?->ticket_number ?? ''),
                    'tone' => $this->activityTone($activity->event_type),
                ];
            }
        }

        if (Schema::hasTable('app_development_releases')) {
            $releases = AppDevelopmentRelease::query()
                ->whereBetween('created_at', [$from, $to])
                ->orderByDesc('created_at')
                ->limit(8)
                ->get(['version_name', 'title', 'created_at']);

            foreach ($releases as $release) {
                if ($release->created_at === null) {
                    continue;
                }
                $events[] = [
                    'at' => $release->created_at,
                    'title' => $release->title !== '' && $release->title !== null ? $release->title : (string) $release->version_name,
                    'detail' => (string) $release->version_name,
                    'tone' => 'orange',
                ];
            }
        }

        $tasks = AppDevelopmentTask::query()
            ->with('ticket:id,ticket_number')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->orderByDesc('completed_at')
            ->limit(8)
            ->get(['title', 'ticket_id', 'completed_at']);

        foreach ($tasks as $task) {
            if ($task->completed_at === null) {
                continue;
            }
            $events[] = [
                'at' => $task->completed_at,
                'title' => (string) $task->title,
                'detail' => (string) ($task->ticket?->ticket_number ?? ''),
                'tone' => 'green',
            ];
        }

        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_map(static function (array $event): array {
            return [
                'title' => $event['title'],
                'detail' => $event['detail'],
                'when' => $event['at']->timezone(config('app.timezone'))->translatedFormat('M j, H:i'),
                'tone' => $event['tone'],
            ];
        }, array_slice($events, 0, 8));
    }

    private function activityTone(AppDevelopmentTicketActivityType|string|null $type): string
    {
        $value = $type instanceof AppDevelopmentTicketActivityType ? $type->value : (string) $type;

        return match ($value) {
            'completed', 'apk_linked' => 'green',
            'commented', 'attachment_added' => 'blue',
            'assigned', 'started_work', 'sent_to_qa' => 'orange',
            default => 'purple',
        };
    }

    private function change(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
    }

    /**
     * @param  list<int|float>  $values
     */
    private function spark(array $values): ?string
    {
        if (count($values) < 2 || array_sum($values) <= 0) {
            return null;
        }

        $max = max($values) ?: 1;
        $last = count($values) - 1;
        $commands = [];
        foreach (array_values($values) as $index => $value) {
            $x = round($index * 160 / $last, 1);
            $y = round(36 - ((float) $value / $max) * 30, 1);
            $commands[] = ($index === 0 ? 'M' : 'L').$x.' '.$y;
        }

        return implode(' ', $commands);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
