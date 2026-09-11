<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentAppType;
use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\AppDevelopmentTicketPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\StoreTaskRequest;
use App\Http\Requests\AppDevelopment\UpdateTaskRequest;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use App\Services\AppDevelopment\AppDevelopmentNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

        $query = AppDevelopmentTask::query()
            ->with([
                'ticket:id,ticket_number,title',
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

        $tasks = $query->latest('updated_at')->paginate(30)->withQueryString();

        $grouped = $tasks->getCollection()->groupBy(
            static fn (AppDevelopmentTask $task): string => $task->status?->value ?? 'todo',
        );

        return view('app-development.tasks.index', [
            'tasks' => $tasks,
            'grouped' => $grouped,
            'scope' => $scope,
            'developers' => $this->developers(),
            'selectedAppTypes' => $selectedAppTypes->map(static fn (AppDevelopmentAppType $type): string => $type->value)->all(),
            'filters' => [
                'assignee' => $request->query('assignee'),
                'ticket' => $request->query('ticket'),
                'priority' => $request->query('priority'),
                'status' => $request->query('status'),
            ],
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $this->authorize('create', AppDevelopmentTask::class);

        return redirect()
            ->route('app-development.tickets.index')
            ->with('info', __('app-development.tasks.escalate_from_ticket_only'));
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
        return redirect()
            ->route('app-development.tickets.index')
            ->with('info', __('app-development.tasks.escalate_from_ticket_only'));
    }

    public function storeForTicket(StoreTaskRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        return $this->escalateFromTicket($request, $ticket);
    }

    public function show(Request $request, AppDevelopmentTask $task): RedirectResponse
    {
        $this->authorize('view', $task);
        $task->loadMissing('ticket');

        if ($task->ticket === null) {
            return redirect()->route('app-development.tasks.index');
        }

        return redirect()
            ->route('app-development.tickets.show', [
                'ticket' => $task->ticket,
                'task' => $task->id,
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
        } else {
            $data['due_at'] = null;
        }

        $task->forceFill($data)->save();
        $task->loadMissing('ticket');

        $newAssignee = (int) ($task->assignee_id ?? 0);
        if ($newAssignee > 0 && $newAssignee !== $previousAssignee) {
            $this->notifications->notifyTaskAssigned($task, $request->user());
        }

        $redirect = redirect()
            ->route('app-development.tickets.show', [
                'ticket' => $task->ticket ?? $task->ticket_id,
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
            'description' => $ticket->description,
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
