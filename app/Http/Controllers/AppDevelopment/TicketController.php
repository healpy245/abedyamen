<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentAppType;
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
use App\Services\KamanSupport\RestaurantLogoResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    use RendersAppDevelopmentModal;

    public function __construct(
        private readonly TicketNumberService $ticketNumbers,
        private readonly TicketWorkflowService $workflow,
        private readonly TicketAttachmentService $attachments,
        private readonly \App\Services\AppDevelopment\TicketStagingUploadService $staging,
    ) {}

    public function restaurantLogos(Request $request, RestaurantLogoResolver $logos): JsonResponse
    {
        $this->authorize('viewAny', AppDevelopmentTicket::class);

        $slugs = $request->query('restaurants', []);
        if (! is_array($slugs)) {
            $slugs = [];
        }

        return response()->json([
            'logos' => $logos->resolve(array_slice(array_values(array_map(strval(...), $slugs)), 0, 12)),
        ]);
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AppDevelopmentTicket::class);

        $user = $request->user();
        $tab = (string) $request->query('tab', 'all');
        $state = (string) $request->query('state', '');
        if (! in_array($state, ['', 'open', 'closed'], true)) {
            $state = '';
        }

        $query = AppDevelopmentTicket::query()
            ->with([
                'creator:id,name',
                'assignedDeveloper:id,name',
                'appTypeRows',
            ])
            ->withCount('comments');

        if ($state === 'closed') {
            $query->where('status', AppDevelopmentTicketStatus::Completed);
        } elseif ($state === 'open') {
            $query->where('status', '!=', AppDevelopmentTicketStatus::Completed->value);
        } elseif ($tab === 'mine') {
            $query->where('assigned_to', $user->id);
        } elseif ($tab === 'waiting-qa' || $tab === 'qa') {
            $query->where('status', AppDevelopmentTicketStatus::Qa);
        } elseif ($tab === 'returned') {
            $query->where('status', AppDevelopmentTicketStatus::Working)
                ->where('qa_rejection_count', '>', 0);
        } elseif ($tab === 'completed') {
            $query->where('status', AppDevelopmentTicketStatus::Completed);
        } elseif ($tab === 'open') {
            $query->where('status', AppDevelopmentTicketStatus::Open);
        } elseif ($tab === 'working') {
            $query->where('status', AppDevelopmentTicketStatus::Working);
        }

        $selectedAppTypes = collect((array) $request->query('app_type', []))
            ->map(static fn ($value): ?AppDevelopmentAppType => AppDevelopmentAppType::tryFrom((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($selectedAppTypes->isNotEmpty()) {
            $query->whereHas('appTypeRows', function ($inner) use ($selectedAppTypes): void {
                $inner->whereIn(
                    'app_type',
                    $selectedAppTypes->map(static fn (AppDevelopmentAppType $type): string => $type->value)->all(),
                );
            });
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

        $tickets = $query->latest('updated_at')->paginate(30)->withQueryString();

        $completed = AppDevelopmentTicketStatus::Completed->value;
        $review = AppDevelopmentTicketStatus::Qa->value;
        $counts = AppDevelopmentTicket::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as closed', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as review', [$review])
            ->first();
        $total = (int) ($counts->total ?? 0);
        $closed = (int) ($counts->closed ?? 0);
        $inReview = (int) ($counts->review ?? 0);

        $notifyDevelopers = AppDevelopmentMember::selectableDevelopers()
            ->get()
            ->sortBy(fn (AppDevelopmentMember $m): string => mb_strtolower((string) $m->user?->name))
            ->values();
        $notifyTesters = AppDevelopmentMember::selectableTesters()
            ->get()
            ->sortBy(fn (AppDevelopmentMember $m): string => mb_strtolower((string) $m->user?->name))
            ->values();

        return view('app-development.tickets.index', [
            'tickets' => $tickets,
            'tab' => $tab,
            'state' => $state,
            'search' => $search,
            'notifyDevelopers' => $notifyDevelopers,
            'notifyTesters' => $notifyTesters,
            'stats' => [
                'total' => $total,
                'open' => max(0, $total - $closed - $inReview),
                'review' => $inReview,
                'closed' => $closed,
            ],
        ]);
    }

    public function updateRestaurant(Request $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);
        $data = $request->validate([
            'restaurant_name' => ['nullable', 'string', 'max:191'],
        ]);
        $ticket->forceFill([
            'restaurant_name' => filled($data['restaurant_name'] ?? null) ? $data['restaurant_name'] : null,
        ])->save();

        return redirect()
            ->route('app-development.tickets.show', $ticket)
            ->with('success', __('app-development.flash.ticket_updated'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', AppDevelopmentTicket::class);

        return $this->appDevelopmentModal(
            $request,
            'app-development.tickets.form-create',
            [],
            __('app-development.tickets.create'),
            'form',
        );
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $user = $request->user();

        $ticket = DB::transaction(function () use ($request, $user) {
            $description = trim((string) ($request->validated('description') ?? ''));
            if ($description === '') {
                $description = __('app-development.tickets.voice_note');
            }

            $ticket = AppDevelopmentTicket::query()->create([
                'ticket_number' => 'TMP-'.Str::ulid(),
                'title' => $request->validated('title'),
                'description' => $description,
                'restaurant_name' => $request->validated('restaurant_name'),
                'type' => $request->validated('type') ?? \App\Enums\AppDevelopmentTicketType::Bug,
                'priority' => $request->validated('priority'),
                'status' => AppDevelopmentTicketStatus::Open,
                'created_by' => $user->id,
                'assigned_to' => null,
                'priority_changed_by' => $user->id,
                'status_changed_by' => $user->id,
            ]);

            $this->ticketNumbers->assign($ticket);

            $ticket->syncAppTypes((array) $request->validated('app_types'));

            $this->workflow->record(
                $ticket,
                $user,
                AppDevelopmentTicketActivityType::Created,
                null,
                AppDevelopmentTicketStatus::Open,
            );

            $voiceFiles = array_values(array_filter(
                $request->file('voices', []) ?: [],
                static fn ($file): bool => $file !== null,
            ));

            foreach ($voiceFiles as $file) {
                $this->attachments->store($ticket, $user, $file);
            }

            foreach ($request->file('attachments', []) ?: [] as $file) {
                if ($file !== null) {
                    $this->attachments->store($ticket, $user, $file);
                }
            }

            $stagedAttachments = array_values(array_filter(
                (array) ($request->validated('staged_attachments') ?? []),
                static fn ($uuid): bool => is_string($uuid) && $uuid !== '',
            ));
            $stagedVoices = array_values(array_filter(
                (array) ($request->validated('staged_voices') ?? []),
                static fn ($uuid): bool => is_string($uuid) && $uuid !== '',
            ));

            if ($stagedAttachments !== [] || $stagedVoices !== []) {
                $this->staging->claimMany($ticket, $user, array_merge($stagedVoices, $stagedAttachments));
            }

            return $ticket;
        });

        return redirect()
            ->route('app-development.tickets.show', $ticket)
            ->with('success', __('app-development.flash.ticket_created'));
    }

    public function show(Request $request, AppDevelopmentTicket $ticket): View
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'creator:id,name',
            'assignedDeveloper:id,name',
            'completedBy:id,name',
            'comments.user.appDevelopmentMembership',
            'comments.attachment',
            'attachments.uploader:id,name',
            'activities.user:id,name',
            'releases' => fn ($q) => $q->latest(),
            'latestQaRejection.user:id,name',
            'appTypeRows',
            'tasks.assignee:id,name',
            'tasks.timeEntries',
        ]);

        return $this->appDevelopmentModal(
            $request,
            'app-development.tickets.panel',
            [
                'ticket' => $ticket,
                'developers' => $this->developers(),
                'notifyMembers' => $this->teamMembers($request->user()),
                'suggestCompleteTicket' => $ticket->allTasksCompleted() && ! $ticket->isCompleted(),
            ],
            $ticket->ticket_number.' — '.$ticket->title,
            'view',
        );
    }

    public function edit(Request $request, AppDevelopmentTicket $ticket): View
    {
        $this->authorize('update', $ticket);

        return $this->appDevelopmentModal(
            $request,
            'app-development.tickets.form-edit',
            ['ticket' => $ticket->loadMissing('appTypeRows')],
            __('app-development.tickets.edit').' · '.$ticket->ticket_number,
            'form',
        );
    }

    public function update(UpdateTicketRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $original = $ticket->only(['title', 'description', 'type', 'priority']);
        $priorityChanged = (string) $request->validated('priority') !== (
            $original['priority'] instanceof AppDevelopmentTicketPriority
                ? $original['priority']->value
                : (string) $original['priority']
        );

        $ticket->forceFill($request->safe()->only(['title', 'description', 'type', 'priority']))->save();
        if ($priorityChanged) {
            $ticket->forceFill(['priority_changed_by' => $request->user()->id])->save();
        }

        $ticket->syncAppTypes((array) $request->validated('app_types'));

        foreach ($request->file('attachments', []) ?: [] as $file) {
            if ($file !== null) {
                $this->attachments->store($ticket, $request->user(), $file);
            }
        }

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

    public function destroy(Request $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $this->authorize('delete', $ticket);

        DB::transaction(function () use ($ticket): void {
            $this->attachments->deleteForTicket($ticket);
            $ticket->releases()->detach();
            $ticket->delete();
        });

        return redirect()
            ->route('app-development.index')
            ->with('success', __('app-development.flash.ticket_deleted'));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function developers()
    {
        $ids = AppDevelopmentMember::assignableDeveloperUserIds();

        return User::query()->select('id', 'name')->whereIn('id', $ids)->orderBy('name')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function teamMembers(?User $except = null)
    {
        $ids = AppDevelopmentMember::query()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $query = User::query()->select('id', 'name')->whereIn('id', $ids)->orderBy('name');
        if ($except !== null) {
            $query->where('id', '!=', $except->id);
        }

        return $query->get();
    }
}
