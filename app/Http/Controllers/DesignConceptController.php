<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AppDevelopmentTaskStatus;
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

        $catalog = app(AgentsWorkspaceController::class)->catalog();
        $companies = $catalog['companies'];
        $openTickets = SupportTicket::query()
            ->whereIn('status', [SupportTicketStatus::Open, SupportTicketStatus::InProgress])
            ->count();
        $openTasks = AppDevelopmentTask::query()
            ->where('status', '!=', AppDevelopmentTaskStatus::Completed)
            ->count();

        $activity = [];
        SupportTicket::query()->latest('updated_at')->limit(3)->get(['summary', 'restaurant_name', 'restaurant', 'updated_at'])
            ->each(function (SupportTicket $ticket) use (&$activity): void {
                $activity[] = [
                    'at' => optional($ticket->updated_at)->timestamp ?? 0,
                    'tone' => 'blue',
                    'title' => (string) $ticket->summary,
                    'meta' => (string) ($ticket->restaurant_name ?: $ticket->restaurant ?: __('app.hub_support')),
                    'time' => optional($ticket->updated_at)->diffForHumans() ?? '',
                ];
            });
        AppDevelopmentTask::query()->latest('updated_at')->limit(3)->get(['title', 'status', 'updated_at'])
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
        $activity = array_slice($activity, 0, 4);

        return view('workspace.overview.index', [
            'page' => $page,
            'companies' => $companies,
            'cities' => $catalog['cities'],
            'stats' => [
                'restaurants' => count($companies),
                'tickets' => $openTickets,
                'tasks' => $openTasks,
            ],
            'activity' => $activity,
            'supportHref' => route('kaman-support.index'),
            'devHref' => $request->user()?->is_admin
                ? route('app-development.reports.index')
                : route('app-development.tickets.index'),
            'agentsHref' => route('workspace.agents'),
            'financeHref' => route('workspace.placeholder', 'finance'),
        ]);
    }

    public function placeholder(Request $request, string $door): View|RedirectResponse
    {
        abort_unless($door === 'finance', 404);

        if ($redirect = $this->awayFromChatbots($request)) {
            return $redirect;
        }

        return view('workspace.placeholder', [
            'title' => __('app.hub_finance'),
        ]);
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
