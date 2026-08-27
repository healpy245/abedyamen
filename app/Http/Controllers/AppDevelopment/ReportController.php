<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentAppType;
use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Http\Controllers\Controller;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
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
        abort_unless($request->user()?->isAppDevelopmentAdmin(), 403);

        [$from, $to, $preset] = $this->range($request);

        return view('app-development.reports.index', [
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'ticketStats' => $this->ticketStats($from, $to),
            'taskStats' => $this->taskStats($from, $to),
            'timeStats' => $this->timeStats($from, $to),
        ]);
    }

    public function exportTickets(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isAppDevelopmentAdmin(), 403);
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
        abort_unless($request->user()?->isAppDevelopmentAdmin(), 403);
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
        abort_unless($request->user()?->isAppDevelopmentAdmin(), 403);
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
    private function range(Request $request): array
    {
        $preset = (string) $request->query('preset', 'last_30');
        $now = Carbon::now()->endOfDay();

        [$from, $to] = match ($preset) {
            'today' => [Carbon::today(), $now],
            'last_7' => [Carbon::now()->subDays(6)->startOfDay(), $now],
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
