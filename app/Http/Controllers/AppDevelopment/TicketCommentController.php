<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentTicketActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\StoreCommentRequest;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketComment;
use App\Services\AppDevelopment\TicketWorkflowService;
use Illuminate\Http\RedirectResponse;

class TicketCommentController extends Controller
{
    public function __construct(
        private readonly TicketWorkflowService $workflow,
    ) {}

    public function store(StoreCommentRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $comment = AppDevelopmentTicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        $this->workflow->record(
            $ticket,
            $request->user(),
            AppDevelopmentTicketActivityType::Commented,
            $ticket->status,
            $ticket->status,
            ['comment_id' => $comment->id],
        );

        return redirect()
            ->route('app-development.tickets.show', $ticket)
            ->with('success', __('app-development.flash.comment_added'));
    }
}
