<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentTicketStatus;
use App\Http\Controllers\Controller;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use Illuminate\Contracts\View\View;

class QaQueueController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('viewAny', AppDevelopmentTicket::class);

        abort_unless(request()->user()?->isQa(), 403);

        $tickets = AppDevelopmentTicket::query()
            ->with(['creator:id,name', 'assignedDeveloper:id,name'])
            ->where('status', AppDevelopmentTicketStatus::Qa)
            ->latest('submitted_for_qa_at')
            ->paginate(20);

        return view('app-development.qa.index', [
            'tickets' => $tickets,
        ]);
    }
}
