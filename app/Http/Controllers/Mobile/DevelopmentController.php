<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Enums\AppDevelopmentAppType;
use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\AppDevelopmentTicketType;
use App\Enums\Door;
use App\Http\Controllers\AppDevelopment\ReportController;
use App\Http\Controllers\Controller;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketActivity;
use App\Models\AppDevelopment\AppDevelopmentTicketAttachment;
use App\Models\AppDevelopment\AppDevelopmentTicketComment;
use App\Models\User;
use App\Services\KamanSupport\SupportDeviceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevelopmentController extends Controller
{
    use QuotePayload;

    /**
     * The choices the ticket and task forms offer.
     */
    public function options(SupportDeviceSessionService $sessions): JsonResponse
    {
        $this->authorize('viewAny', AppDevelopmentTicket::class);

        return response()->json([
            'priorities' => array_map(fn ($case): array => self::state($case), AppDevelopmentTicketPriority::cases()),
            'types' => array_map(fn ($case): array => self::state($case), AppDevelopmentTicketType::cases()),
            'app_types' => array_map(fn ($case): array => self::state($case), AppDevelopmentAppType::cases()),
            'task_statuses' => array_map(fn ($case): array => self::state($case), AppDevelopmentTaskStatus::cases()),
            'developers' => User::query()
                ->select('id', 'name')
                ->whereIn('id', AppDevelopmentMember::assignableDeveloperUserIds())
                ->orderBy('name')
                ->get(),
            'restaurants' => array_column($sessions->restaurantChoices(), 'label'),
        ]);
    }

    public function tickets(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AppDevelopmentTicket::class);

        $user = $request->user();
        $tab = (string) $request->query('tab', 'all');
        $search = trim((string) $request->query('q', ''));

        $query = AppDevelopmentTicket::query()
            ->with(['creator:id,name', 'assignedDeveloper:id,name', 'appTypeRows'])
            ->withCount('comments');

        match ($tab) {
            'mine' => $query->where('assigned_to', $user->id),
            'open' => $query->where('status', AppDevelopmentTicketStatus::Open),
            'working' => $query->where('status', AppDevelopmentTicketStatus::Working),
            'qa' => $query->where('status', AppDevelopmentTicketStatus::Qa),
            'returned' => $query->where('status', AppDevelopmentTicketStatus::Working)->where('qa_rejection_count', '>', 0),
            'completed' => $query->where('status', AppDevelopmentTicketStatus::Completed),
            default => null,
        };

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('ticket_number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        $tickets = $query->latest('updated_at')->paginate(30);

        $counts = AppDevelopmentTicket::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as closed', [AppDevelopmentTicketStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as review', [AppDevelopmentTicketStatus::Qa->value])
            ->first();
        $total = (int) ($counts->total ?? 0);
        $closed = (int) ($counts->closed ?? 0);
        $review = (int) ($counts->review ?? 0);

        return response()->json([
            'tickets' => collect($tickets->items())->map(fn (AppDevelopmentTicket $ticket): array => $this->ticketRow($ticket))->values()->all(),
            'page' => $tickets->currentPage(),
            'last_page' => $tickets->lastPage(),
            'stats' => [
                'total' => $total,
                'open' => max(0, $total - $closed - $review),
                'review' => $review,
                'closed' => $closed,
            ],
            'can_create' => $user->can('create', AppDevelopmentTicket::class),
        ]);
    }

    public function ticket(Request $request, AppDevelopmentTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $user = $request->user();
        $ticket->load([
            'creator:id,name',
            'assignedDeveloper:id,name',
            'completedBy:id,name',
            'comments.user:id,name',
            'comments.attachment',
            'attachments',
            'activities.user:id,name',
            'latestQaRejection.user:id,name',
            'appTypeRows',
            'tasks.assignee:id,name',
            'tasks.timeEntries',
        ]);
        $ticket->loadCount('comments');

        return response()->json($this->ticketRow($ticket) + [
            'description' => $ticket->description,
            'completed_by' => $ticket->completedBy?->name,
            'qa_rejection' => $ticket->latestQaRejection === null ? null : [
                'user' => $ticket->latestQaRejection->user?->name,
                'message' => $ticket->latestQaRejection->message(),
            ],
            'comments' => $ticket->comments->map(fn (AppDevelopmentTicketComment $comment): array => [
                'id' => $comment->id,
                'user' => $comment->user?->name,
                'body' => $comment->body,
                'at' => $comment->created_at?->toIso8601String(),
                'attachment' => $comment->attachment === null ? null : $this->attachmentRow($ticket, $comment->attachment),
            ])->values()->all(),
            'attachments' => $ticket->attachments->map(fn (AppDevelopmentTicketAttachment $file): array => $this->attachmentRow($ticket, $file))->values()->all(),
            'activities' => $ticket->activities->map(fn (AppDevelopmentTicketActivity $activity): array => [
                'id' => $activity->id,
                'message' => $activity->message(),
                'user' => $activity->user?->name,
                'at' => $activity->created_at?->toIso8601String(),
            ])->values()->all(),
            'tasks' => $ticket->tasks->map(fn (AppDevelopmentTask $task): array => $this->taskRow($task, $user))->values()->all(),
            'can' => [
                'update' => $user->can('update', $ticket),
                'start_work' => $user->can('startWork', $ticket),
                'send_to_qa' => $user->can('submitForQa', $ticket),
                'return' => $user->can('qaReject', $ticket),
                'complete' => $user->can('qaApprove', $ticket),
                'assign' => $user->can('assign', $ticket),
                'priority' => $user->can('changePriority', $ticket),
                'comment' => $user->can('comment', $ticket),
                'attach' => $user->can('uploadAttachment', $ticket),
                'delete' => $user->can('delete', $ticket),
            ],
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AppDevelopmentTask::class);

        $user = $request->user();
        $admin = $user->isAppDevelopmentAdmin();
        $scope = $admin && $request->query('scope', 'all') === 'mine' ? 'mine' : ($admin ? 'all' : 'mine');

        $tasks = AppDevelopmentTask::query()
            ->with(['ticket:id,ticket_number,title,status', 'assignee:id,name', 'timeEntries'])
            ->when($scope === 'mine', fn ($query) => $query->where('assignee_id', $user->id))
            ->latest('updated_at')
            ->limit(200)
            ->get();

        return response()->json([
            'scope' => $scope,
            'can_see_all' => $admin,
            'can_create' => $user->can('create', AppDevelopmentTask::class),
            'statuses' => array_map(fn ($case): array => self::state($case), AppDevelopmentTaskStatus::cases()),
            'tasks' => $tasks->map(fn (AppDevelopmentTask $task): array => $this->taskRow($task, $user))->values()->all(),
        ]);
    }

    public function task(Request $request, AppDevelopmentTask $task): JsonResponse
    {
        $this->authorize('view', $task);

        $user = $request->user();
        $task->load(['ticket:id,ticket_number,title,status', 'assignee:id,name', 'creator:id,name', 'timeEntries.user:id,name']);

        return response()->json($this->taskRow($task, $user) + [
            'description' => $task->description,
            'creator' => $task->creator?->name,
            'completion_note' => $task->completion_note,
            'entries' => $task->timeEntries->map(fn ($entry): array => [
                'id' => $entry->id,
                'user' => $entry->user?->name,
                'started_at' => $entry->started_at?->toIso8601String(),
                'seconds' => $entry->elapsedSeconds(),
                'active' => $entry->isActive(),
            ])->values()->all(),
            'can' => [
                'update' => $user->can('update', $task),
                'start_timer' => $user->can('startTimer', $task),
                'pause_timer' => $user->can('pauseTimer', $task),
            ],
        ]);
    }

    public function releases(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AppDevelopmentRelease::class);

        $releases = AppDevelopmentRelease::query()
            ->with('uploader:id,name')
            ->withCount('tickets')
            ->latest()
            ->paginate(15);

        return response()->json([
            'page' => $releases->currentPage(),
            'last_page' => $releases->lastPage(),
            'can_upload' => $request->user()->can('uploadRelease', AppDevelopmentRelease::class),
            'releases' => collect($releases->items())->map(fn (AppDevelopmentRelease $release): array => [
                'id' => $release->id,
                'version_name' => $release->version_name,
                'version_code' => $release->version_code,
                'title' => $release->title,
                'notes' => $release->release_notes,
                'size' => $release->humanSize(),
                'latest' => (bool) $release->is_latest,
                'uploader' => $release->uploader?->name,
                'tickets' => $release->tickets_count,
                'at' => $release->created_at?->toIso8601String(),
                'download_url' => route('app-development.releases.download', $release, false),
            ])->values()->all(),
        ]);
    }

    public function reports(Request $request, ReportController $reports): JsonResponse
    {
        abort_unless($request->user()?->canOpenSubDoor(Door::Development, 'reports'), 403);

        [$from, $to, $preset] = $reports->range($request);
        $dash = $reports->dashboard($from, $to);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'preset' => $preset,
            'available' => $dash['available'],
            'completed' => $dash['completed'],
            'open' => $dash['open'],
            'cycle' => $dash['cycle'],
            'releases' => $dash['releases'],
            'trend' => $dash['trend'],
            'trend_max' => $dash['trend_max'],
            'statuses' => $dash['statuses'],
            'status_total' => $dash['status_total'],
            'apps' => $dash['apps'],
            'activity' => $dash['activity'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketRow(AppDevelopmentTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->ticket_number,
            'title' => $ticket->title,
            'restaurant' => $ticket->restaurant_name,
            'type' => self::state($ticket->type),
            'priority' => self::state($ticket->priority),
            'status' => self::state($ticket->status),
            'creator' => $ticket->creator?->name,
            'assignee' => $ticket->assignedDeveloper?->name,
            'assignee_id' => $ticket->assigned_to,
            'returned' => (int) $ticket->qa_rejection_count > 0,
            'comments' => (int) ($ticket->comments_count ?? 0),
            'app_types' => array_map(fn (AppDevelopmentAppType $type): array => self::state($type), $ticket->appTypes()),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(AppDevelopmentTask $task, User $user): array
    {
        $running = $task->timeEntries->first(fn ($entry): bool => $entry->isActive() && (int) $entry->user_id === (int) $user->id);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'restaurant' => $task->restaurant_name,
            'priority' => self::state($task->priority),
            'status' => self::state($task->status),
            'assignee' => $task->assignee?->name,
            'assignee_id' => $task->assignee_id,
            'due_at' => $task->due_at?->toIso8601String(),
            'overdue' => $task->isOverdue(),
            'ticket' => $task->ticket === null ? null : ['number' => $task->ticket->ticket_number, 'title' => $task->ticket->title],
            'total_seconds' => $task->totalDurationSeconds(),
            'running' => $running !== null,
            'running_seconds' => $running?->elapsedSeconds(),
            'can_update' => $user->can('update', $task),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentRow(AppDevelopmentTicket $ticket, AppDevelopmentTicketAttachment $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'mime' => $file->mime_type,
            'url' => route('app-development.tickets.attachments.download', [$ticket, $file], false),
        ];
    }
}
