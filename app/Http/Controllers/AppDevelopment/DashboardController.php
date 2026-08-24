<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentTicketStatus;
use App\Http\Controllers\Controller;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('viewAny', AppDevelopmentTicket::class);

        $user = $request->user();

        $statusCounts = AppDevelopmentTicket::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];
        foreach (AppDevelopmentTicketStatus::cases() as $status) {
            $counts[$status->value] = (int) ($statusCounts[$status->value] ?? 0);
        }

        $recent = AppDevelopmentTicket::query()
            ->with(['creator:id,name', 'assignedDeveloper:id,name'])
            ->latest('updated_at')
            ->limit(8)
            ->get();

        $myTickets = collect();
        $availableTickets = collect();
        $returnedTickets = collect();
        $waitingForQa = collect();
        $recentlyCompleted = collect();

        if ($user->isDeveloper()) {
            $myTickets = AppDevelopmentTicket::query()
                ->with(['creator:id,name'])
                ->where('assigned_to', $user->id)
                ->where('status', AppDevelopmentTicketStatus::Working)
                ->latest('updated_at')
                ->limit(8)
                ->get();

            $availableTickets = AppDevelopmentTicket::query()
                ->with(['creator:id,name'])
                ->where('status', AppDevelopmentTicketStatus::Open)
                ->latest('created_at')
                ->limit(8)
                ->get();

            $returnedTickets = AppDevelopmentTicket::query()
                ->with(['creator:id,name', 'latestQaRejection.user:id,name'])
                ->where('assigned_to', $user->id)
                ->where('status', AppDevelopmentTicketStatus::Working)
                ->where('qa_rejection_count', '>', 0)
                ->latest('updated_at')
                ->limit(8)
                ->get();
        }

        if ($user->isQa()) {
            $waitingForQa = AppDevelopmentTicket::query()
                ->with(['creator:id,name', 'assignedDeveloper:id,name'])
                ->where('status', AppDevelopmentTicketStatus::Qa)
                ->latest('submitted_for_qa_at')
                ->limit(8)
                ->get();

            $recentlyCompleted = AppDevelopmentTicket::query()
                ->with(['creator:id,name', 'assignedDeveloper:id,name', 'completedBy:id,name'])
                ->where('status', AppDevelopmentTicketStatus::Completed)
                ->latest('completed_at')
                ->limit(8)
                ->get();
        }

        return view('app-development.dashboard', [
            'statusCounts' => $counts,
            'recent' => $recent,
            'myTickets' => $myTickets,
            'availableTickets' => $availableTickets,
            'returnedTickets' => $returnedTickets,
            'waitingForQa' => $waitingForQa,
            'recentlyCompleted' => $recentlyCompleted,
            'returnedCount' => $user->isDeveloper()
                ? AppDevelopmentTicket::query()
                    ->where('assigned_to', $user->id)
                    ->where('status', AppDevelopmentTicketStatus::Working)
                    ->where('qa_rejection_count', '>', 0)
                    ->count()
                : 0,
        ]);
    }
}
