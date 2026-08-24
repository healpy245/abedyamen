<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketActivityType;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\AppDevelopmentTicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\StoreTicketRequest;
use App\Http\Requests\AppDevelopment\UpdateTicketRequest;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use App\Services\AppDevelopment\TicketAttachmentService;
use App\Services\AppDevelopment\TicketNumberService;
use App\Services\AppDevelopment\TicketWorkflowService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function __construct(
        private readonly TicketNumberService $ticketNumbers,
        private readonly TicketWorkflowService $workflow,
        private readonly TicketAttachmentService $attachments,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AppDevelopmentTicket::class);

        $user = $request->user();
        $tab = (string) $request->query('tab', 'all');

        $query = AppDevelopmentTicket::query()
            ->with(['creator:id,name', 'assignedDeveloper:id,name']);

        match ($tab) {
            'open' => $query->where('status', AppDevelopmentTicketStatus::Open),
            'working' => $query->where('status', AppDevelopmentTicketStatus::Working),
            'qa' => $query->where('status', AppDevelopmentTicketStatus::Qa),
            'completed' => $query->where('status', AppDevelopmentTicketStatus::Completed),
            'mine' => $query->where('assigned_to', $user->id),
            'waiting-qa' => $query->where('status', AppDevelopmentTicketStatus::Qa),
            'returned' => $query->where('status', AppDevelopmentTicketStatus::Working)->where('qa_rejection_count', '>', 0),
            default => null,
        };

        if ($request->filled('status')) {
            $status = AppDevelopmentTicketStatus::tryFrom((string) $request->query('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('type')) {
            $type = AppDevelopmentTicketType::tryFrom((string) $request->query('type'));
            if ($type !== null) {
                $query->where('type', $type);
            }
        }

        if ($request->filled('priority')) {
            $priority = AppDevelopmentTicketPriority::tryFrom((string) $request->query('priority'));
            if ($priority !== null) {
                $query->where('priority', $priority);
            }
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', (int) $request->query('created_by'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', (int) $request->query('assigned_to'));
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('ticket_number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        $tickets = $query->latest('updated_at')->paginate(20)->withQueryString();

        $creators = User::query()
            ->select('id', 'name')
            ->whereIn('id', AppDevelopmentTicket::query()->select('created_by')->distinct())
            ->orderBy('name')
            ->get();

        $developers = $this->developers();

        return view('app-development.tickets.index', [
            'tickets' => $tickets,
            'tab' => $tab,
            'search' => $search,
            'creators' => $creators,
            'developers' => $developers,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', AppDevelopmentTicket::class);

        return view('app-development.tickets.create', [
            'developers' => $this->developers(),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $user = $request->user();

        $ticket = DB::transaction(function () use ($request, $user) {
            $ticket = AppDevelopmentTicket::query()->create([
                'ticket_number' => 'TMP-'.Str::ulid(),
                'title' => $request->validated('title'),
                'description' => $request->validated('description'),
                'type' => $request->validated('type'),
                'priority' => $request->validated('priority'),
                'status' => AppDevelopmentTicketStatus::Open,
                'created_by' => $user->id,
                'assigned_to' => $request->validated('assigned_to'),
            ]);

            $this->ticketNumbers->assign($ticket);

            $this->workflow->record(
                $ticket,
                $user,
                AppDevelopmentTicketActivityType::Created,
                null,
                AppDevelopmentTicketStatus::Open,
            );

            if ($ticket->assigned_to) {
                $this->workflow->record(
                    $ticket,
                    $user,
                    AppDevelopmentTicketActivityType::Assigned,
                    AppDevelopmentTicketStatus::Open,
                    AppDevelopmentTicketStatus::Open,
                    [
                        'assigned_from' => null,
                        'assigned_to' => $ticket->assigned_to,
                    ],
                );
            }

            foreach ($request->file('attachments', []) ?: [] as $file) {
                if ($file !== null) {
                    $this->attachments->store($ticket, $user, $file);
                }
            }

            return $ticket;
        });

        return redirect()
            ->route('app-development.tickets.show', $ticket)
            ->with('success', __('app-development.flash.ticket_created'));
    }

    public function show(AppDevelopmentTicket $ticket): View
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'creator:id,name',
            'assignedDeveloper:id,name',
            'completedBy:id,name',
            'comments.user.appDevelopmentMembership',
            'attachments.uploader:id,name',
            'activities.user:id,name',
            'releases' => fn ($q) => $q->latest(),
            'latestQaRejection.user:id,name',
        ]);

        return view('app-development.tickets.show', [
            'ticket' => $ticket,
            'developers' => $this->developers(),
        ]);
    }

    public function edit(AppDevelopmentTicket $ticket): View
    {
        $this->authorize('update', $ticket);

        return view('app-development.tickets.edit', [
            'ticket' => $ticket,
        ]);
    }

    public function update(UpdateTicketRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $original = $ticket->only(['title', 'description', 'type', 'priority']);

        $ticket->forceFill($request->safe()->only(['title', 'description', 'type', 'priority']))->save();

        $this->workflow->record(
            $ticket,
            $request->user(),
            AppDevelopmentTicketActivityType::Updated,
            $ticket->status,
            $ticket->status,
            ['changed' => array_keys(array_diff_assoc(
                $request->safe()->only(['title', 'description', 'type', 'priority']),
                [
                    'title' => $original['title'],
                    'description' => $original['description'],
                    'type' => $original['type'] instanceof AppDevelopmentTicketType ? $original['type']->value : $original['type'],
                    'priority' => $original['priority'] instanceof AppDevelopmentTicketPriority ? $original['priority']->value : $original['priority'],
                ],
            ))],
        );

        return redirect()
            ->route('app-development.tickets.show', $ticket)
            ->with('success', __('app-development.flash.ticket_updated'));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function developers()
    {
        $ids = AppDevelopmentMember::query()
            ->where('role', AppDevelopmentRole::Developer)
            ->pluck('user_id');

        return User::query()->select('id', 'name')->whereIn('id', $ids)->orderBy('name')->get();
    }
}
