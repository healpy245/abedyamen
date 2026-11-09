<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentAppType;
use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\StoreTaskRequest;
use App\Http\Requests\AppDevelopment\UpdateTaskRequest;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use App\Services\AppDevelopment\AppDevelopmentNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    use RendersAppDevelopmentModal;

    public function __construct(
        private readonly AppDevelopmentNotificationService $notifications,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AppDevelopmentTask::class);

        $user = $request->user();
        $scope = (string) $request->query('scope', $user->isAppDevelopmentAdmin() ? 'all' : 'mine');
        if (! in_array($scope, ['mine', 'all'], true)) {
            $scope = $user->isAppDevelopmentAdmin() ? 'all' : 'mine';
        }
        if (! $user->isAppDevelopmentAdmin()) {
            $scope = 'mine';
        }

        $view = (string) $request->query('view', 'board');
        if (! in_array($view, ['board', 'list'], true)) {
            $view = 'board';
        }

        $query = AppDevelopmentTask::query()
            ->with([
                'ticket' => function ($inner): void {
                    $inner->select('id', 'ticket_number', 'title', 'status')->withCount('comments');
                },
                'ticket.appTypeRows',
                'assignee:id,name',
                'creator:id,name',
            ]);

        if ($scope === 'mine') {
            $query->where('assignee_id', $user->id);
        }

        if ($request->filled('assignee')) {
            $query->where('assignee_id', (int) $request->query('assignee'));
        }

        if ($request->filled('ticket')) {
            $ticketKey = (string) $request->query('ticket');
            $query->whereHas('ticket', function ($inner) use ($ticketKey): void {
                if (ctype_digit($ticketKey)) {
                    $inner->where('id', (int) $ticketKey);
                } else {
                    $inner->where('ticket_number', $ticketKey);
                }
            });
        }

        if ($request->filled('priority')) {
            $priority = AppDevelopmentTicketPriority::tryFrom((string) $request->query('priority'));
            if ($priority !== null) {
                $query->where('priority', $priority);
            }
        }

        if ($request->filled('status')) {
            $status = AppDevelopmentTaskStatus::tryFrom((string) $request->query('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        $selectedAppTypes = collect((array) $request->query('app_type', []))
            ->map(static fn ($value): ?AppDevelopmentAppType => AppDevelopmentAppType::tryFrom((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($selectedAppTypes->isNotEmpty()) {
            $query->whereHas('ticket.appTypeRows', function ($inner) use ($selectedAppTypes): void {
                $inner->whereIn(
                    'app_type',
                    $selectedAppTypes->map(static fn (AppDevelopmentAppType $type): string => $type->value)->all(),
                );
            });
        }

        if ($request->filled('due')) {
            try {
                $due = Carbon::parse((string) $request->query('due'))->toDateString();
                $query->whereDate('due_at', $due);
            } catch (\Throwable) {
                // Ignore an unreadable due-date filter.
            }
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('ticket', function ($ticket) use ($like): void {
                        $ticket->where('ticket_number', 'like', $like)
                            ->orWhere('title', 'like', $like);
                    });
            });
        }

        $columns = [];
        $tasks = null;
        $sort = (string) $request->query('sort', 'updated');
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        if ($view === 'board') {
            foreach (AppDevelopmentTaskStatus::cases() as $status) {
                $columnQuery = $query->clone()->where('status', $status);
                $total = $columnQuery->clone()->count();
                $columns[] = [
                    'status' => $status,
                    'total' => $total,
                    'tasks' => $columnQuery->latest('updated_at')->limit(80)->get(),
                ];
            }
        } else {
            $sorted = $query->clone();
            match ($sort) {
                'title' => $sorted->orderBy('title', $dir),
                'status' => $sorted->orderBy('status', $dir),
                'priority' => $sorted->orderBy('priority', $dir),
                'assignee' => $sorted->orderBy('assignee_id', $dir),
                'due' => $sorted->orderBy('due_at', $dir),
                default => $sorted->latest('updated_at'),
            };
            $tasks = $sorted->paginate(40)->withQueryString();
        }

        return view('app-development.tasks.index', [
            'tasks' => $tasks,
            'columns' => $columns,
            'viewMode' => $view,
            'scope' => $scope,
            'sort' => $sort,
            'dir' => $dir,
            'search' => $search,
            'developers' => $this->developers(),
            'selectedAppTypes' => $selectedAppTypes->map(static fn (AppDevelopmentAppType $type): string => $type->value)->all(),
            'notifyTeam' => $user->isAppDevelopmentAdmin()
                ? AppDevelopmentMember::selectableTeam()->get()->sortBy(fn (AppDevelopmentMember $member): string => mb_strtolower((string) $member->user?->name))->values()
                : collect(),
            'notifyCounts' => $user->isAppDevelopmentAdmin()
                ? [
                    'open' => AppDevelopmentTicket::query()->where('status', AppDevelopmentTicketStatus::Open)->count(),
                    'qa' => AppDevelopmentTicket::query()->where('status', AppDevelopmentTicketStatus::Qa)->count(),
                ]
                : ['open' => 0, 'qa' => 0],
            'filters' => [
                'assignee' => $request->query('assignee'),
                'ticket' => $request->query('ticket'),
                'priority' => $request->query('priority'),
                'status' => $request->query('status'),
                'due' => $request->query('due'),
                'app_type' => $selectedAppTypes->map(static fn (AppDevelopmentAppType $type): string => $type->value)->all(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', AppDevelopmentTask::class);

        return $this->appDevelopmentModal(
            $request,
            'app-development.tasks.form-blank',
            ['developers' => $this->developers()],
            __('app-development.nav.new_task'),
            'form',
            route('app-development.tasks.index'),
        );
    }

    public function createForTicket(Request $request, AppDevelopmentTicket $ticket): View
    {
        $this->authorize('create', AppDevelopmentTask::class);
        $this->authorize('view', $ticket);

        return $this->appDevelopmentModal(
            $request,
            'app-development.tasks.form-create',
            [
                'ticket' => $ticket,
                'developers' => $this->developers(),
            ],
            __('app-development.tasks.escalate').' · '.$ticket->ticket_number,
            'form',
            route('app-development.tickets.show', $ticket),
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', AppDevelopmentTask::class);

        $developerIds = AppDevelopmentMember::assignableDeveloperUserIds();
        $restaurantNames = array_column(
            app(\App\Services\KamanSupport\SupportDeviceSessionService::class)->restaurantChoices(),
            'label',
        );
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'restaurant_name' => ['nullable', 'string', 'max:191', Rule::in($restaurantNames === [] ? ['__none__'] : $restaurantNames)],
            'assignee_id' => ['nullable', 'integer', Rule::in($developerIds === [] ? [0] : $developerIds)],
            'priority' => ['nullable', Rule::enum(AppDevelopmentTicketPriority::class)],
        ]);

        $task = AppDevelopmentTask::query()->create([
            'ticket_id' => null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'restaurant_name' => $data['restaurant_name'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'priority' => $data['priority'] ?? AppDevelopmentTicketPriority::Normal,
            'status' => AppDevelopmentTaskStatus::Todo,
            'created_by' => $request->user()->id,
        ]);

        if ($task->assignee_id) {
            $this->notifications->notifyTaskAssigned($task, $request->user());
        }

        return redirect()
            ->route('app-development.tasks.index')
            ->with('success', __('app-development.flash.task_escalated'));
    }

    public function storeForTicket(StoreTaskRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        return $this->escalateFromTicket($request, $ticket);
    }

    public function show(Request $request, AppDevelopmentTask $task): View|RedirectResponse
    {
        $this->authorize('view', $task);
        $task->load([
            'assignee:id,name',
            'creator:id,name',
            'timeEntries.user:id,name',
            'ticket.appTypeRows',
            'ticket.comments.user:id,name',
            'ticket.comments.attachment',
            'ticket.attachments',
            'ticket.activities.user:id,name',
        ]);

        if ($this->wantsAppDevelopmentModal($request)) {
            return view('app-development.tasks.panel', [
                'task' => $task,
                'developers' => $this->developers(),
            ]);
        }

        if ($task->ticket === null) {
            return redirect()->route('app-development.tasks.index');
        }

        return redirect()
            ->route('app-development.tickets.show', [
                'ticket' => $task->ticket,
                'task' => $task->id,
            ]);
    }

    public function updateStatus(Request $request, AppDevelopmentTask $task): JsonResponse
    {
        $this->authorize('update', $task);

        $status = AppDevelopmentTaskStatus::from((string) $request->validate([
            'status' => ['required', Rule::enum(AppDevelopmentTaskStatus::class)],
        ])['status']);

        $task->forceFill([
            'status' => $status,
            'completed_at' => $status === AppDevelopmentTaskStatus::Completed
                ? ($task->completed_at ?? Carbon::now())
                : null,
            'completion_note' => $status === AppDevelopmentTaskStatus::Completed ? $task->completion_note : null,
        ])->save();

        return response()->json([
            'ok' => true,
            'value' => $task->status->value,
            'label' => $task->status->label(),
            'message' => __('app-development.board.moved'),
        ]);
    }

    public function update(UpdateTaskRequest $request, AppDevelopmentTask $task): RedirectResponse
    {
        $previousAssignee = (int) ($task->assignee_id ?? 0);
        $data = $request->safe()->only(['title', 'description', 'assignee_id', 'priority']);

        if ($request->filled('status')) {
            $status = AppDevelopmentTaskStatus::from((string) $request->validated('status'));
            $data['status'] = $status;
            if ($status === AppDevelopmentTaskStatus::Completed) {
                $data['completed_at'] = $task->completed_at ?? Carbon::now();
            } else {
                $data['completed_at'] = null;
                $data['completion_note'] = null;
            }
        }

        if ($request->filled('due_at')) {
            $data['due_at'] = Carbon::parse((string) $request->validated('due_at'));
        }

        $task->forceFill($data)->save();
        $task->loadMissing('ticket');

        $newAssignee = (int) ($task->assignee_id ?? 0);
        if ($newAssignee > 0 && $newAssignee !== $previousAssignee) {
            $this->notifications->notifyTaskAssigned($task, $request->user());
        }

        if ($task->ticket === null) {
            return redirect()
                ->route('app-development.tasks.show', $task)
                ->with('success', __('app-development.flash.task_updated'));
        }

        $redirect = redirect()
            ->route('app-development.tickets.show', [
                'ticket' => $task->ticket,
                'task' => $task->id,
            ])
            ->with('success', __('app-development.flash.task_updated'));

        if ($task->ticket && $task->ticket->allTasksCompleted() && ! $task->ticket->isCompleted()) {
            $redirect->with('info', __('app-development.flash.suggest_complete_ticket', [
                'ticket' => $task->ticket->ticket_number,
            ]));
        }

        return $redirect;
    }

    private function escalateFromTicket(StoreTaskRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $task = AppDevelopmentTask::query()->create([
            'ticket_id' => $ticket->id,
            'title' => $ticket->title,
            'description' => $request->exists('description')
                ? $request->validated('description')
                : $ticket->description,
            'restaurant_name' => $ticket->restaurant_name,
            'assignee_id' => (int) $request->validated('assignee_id'),
            'priority' => $ticket->priority,
            'status' => AppDevelopmentTaskStatus::Todo,
            'due_at' => null,
            'created_by' => $request->user()->id,
        ]);

        $this->notifications->notifyTaskAssigned($task->load('ticket'), $request->user());

        return redirect()
            ->route('app-development.tickets.show', [
                'ticket' => $ticket,
                'task' => $task->id,
            ])
            ->with('success', __('app-development.flash.task_escalated'));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function developers()
    {
        $ids = AppDevelopmentMember::assignableDeveloperUserIds();

        return User::query()->select('id', 'name')->whereIn('id', $ids)->orderBy('name')->get();
    }
}
