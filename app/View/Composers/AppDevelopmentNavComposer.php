<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Enums\AppDevelopmentTicketStatus;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use Illuminate\View\View;

class AppDevelopmentNavComposer
{
    public function compose(View $view): void
    {
        $user = auth()->user();
        $waitingForQa = 0;
        $returnedFromQa = 0;

        if ($user?->isQa()) {
            $waitingForQa = AppDevelopmentTicket::query()
                ->where('status', AppDevelopmentTicketStatus::Qa)
                ->count();
        }

        if ($user?->isDeveloper()) {
            $returnedQuery = AppDevelopmentTicket::query()
                ->where('status', AppDevelopmentTicketStatus::Working)
                ->where('qa_rejection_count', '>', 0);

            if (! $user->isAppDevelopmentAdmin()) {
                $returnedQuery->where('assigned_to', $user->id);
            }

            $returnedFromQa = $returnedQuery->count();
        }

        $view->with([
            'navWaitingForQa' => $waitingForQa,
            'navReturnedFromQa' => $returnedFromQa,
        ]);
    }
}
