<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\Door;
use App\Enums\KamanSupport\SupportTicketStatus;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\KamanSupport\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DesignConceptController extends Controller
{
    /** @var list<string> */
    public const CONCEPTS = ['command', 'pro', 'bento'];

    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->awayFromChatbots($request)) {
            return $redirect;
        }

        return view('design.index', [
            'concepts' => self::CONCEPTS,
        ]);
    }

    public function show(Request $request, string $concept, \App\Services\TodayService $today): View|RedirectResponse
    {
        abort_unless(in_array($concept, self::CONCEPTS, true), 404);

        if ($redirect = $this->awayFromChatbots($request)) {
            return $redirect;
        }

        return view('design.'.$concept, $today->for($request->user()));
    }

    public function modernLight(Request $request): View|RedirectResponse
    {
        return $this->overview($request, 'dashboard');
    }

    public function overview(Request $request, ?string $page = null): View|RedirectResponse
    {
        if ($redirect = $this->awayFromChatbots($request)) {
            return $redirect;
        }

        $page = $page ?: 'dashboard';
        abort_unless(in_array($page, ['dashboard', 'restaurants'], true), 404);

        $user = $request->user();
        // The dashboard is the doors only; the restaurant list stays out of the page when Restaurants is locked.
        $restaurants = $user?->canOpenSubDoor(Door::Overview, 'restaurants') ?? false;
        $catalog = app(AgentsWorkspaceController::class)->catalog();
        $companies = $restaurants ? $catalog['companies'] : [];
        $summary = $this->summary($user, $companies);

        return view('workspace.overview.index', [
            'page' => $page,
            'companies' => $companies,
            'cities' => $catalog['cities'],
            'finance' => $restaurants ? app(\App\Services\Finance\RestaurantFinance::class)->forUser($user) : [],
            'supportBoot' => app(\App\Services\KamanSupport\RestaurantSupport::class)->boot($restaurants ? $user : null),
            'stats' => $summary['stats'],
            'activity' => $summary['activity'],
            'supportHref' => $user->doorEntryUrl(Door::Support),
            'devHref' => $user->doorEntryUrl(Door::Development),
            'agentsHref' => $user->doorEntryUrl(Door::Agents),
            'financeHref' => $user->doorEntryUrl(Door::Finance),
        ]);
    }

    /**
     * The dashboard's numbers and latest activity, limited to what this user's doors show.
     *
     * @param  array<int, mixed>  $companies
     * @return array{stats: array{restaurants: int, tickets: int, tasks: int}, activity: list<array{at: int, tone: string, title: string, meta: string, time: string}>}
     */
    public function summary(?\App\Models\User $user, array $companies): array
    {
        $openTickets = SupportTicket::query()
            ->whereIn('status', [SupportTicketStatus::Open, SupportTicketStatus::InProgress])
            ->count();
        $openTasks = AppDevelopmentTask::query()
            ->where('status', '!=', AppDevelopmentTaskStatus::Completed)
            ->count();

        $activity = [];
        $seesTickets = $user?->canOpenSubDoor(Door::Support, 'tickets') ?? false;
        $seesTasks = $user?->canOpenSubDoor(Door::Development, 'tasks') ?? false;
        SupportTicket::query()->latest('updated_at')->limit($seesTickets ? 3 : 0)->get(['summary', 'restaurant_name', 'restaurant', 'updated_at'])
            ->each(function (SupportTicket $ticket) use (&$activity): void {
                $activity[] = [
                    'at' => optional($ticket->updated_at)->timestamp ?? 0,
                    'tone' => 'blue',
                    'title' => (string) $ticket->summary,
                    'meta' => (string) ($ticket->restaurant_name ?: $ticket->restaurant ?: __('app.hub_support')),
                    'time' => optional($ticket->updated_at)->diffForHumans() ?? '',
                ];
            });
        AppDevelopmentTask::query()->latest('updated_at')->limit($seesTasks ? 3 : 0)->get(['title', 'status', 'updated_at'])
            ->each(function (AppDevelopmentTask $task) use (&$activity): void {
                $activity[] = [
                    'at' => optional($task->updated_at)->timestamp ?? 0,
                    'tone' => $task->status === AppDevelopmentTaskStatus::Completed ? 'green' : 'orange',
                    'title' => (string) $task->title,
                    'meta' => __('app.hub_dev'),
                    'time' => optional($task->updated_at)->diffForHumans() ?? '',
                ];
            });
        usort($activity, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return [
            'stats' => [
                'restaurants' => count($companies),
                'tickets' => $seesTickets ? $openTickets : 0,
                'tasks' => $seesTasks ? $openTasks : 0,
            ],
            'activity' => array_slice($activity, 0, 4),
        ];
    }

    private function awayFromChatbots(Request $request): ?RedirectResponse
    {
        $user = $request->user();
        if ($user !== null && $user->isChatbotOnly()) {
            return redirect($user->afterLoginUrl());
        }

        return null;
    }
}
