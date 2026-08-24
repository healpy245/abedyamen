<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Exceptions\AppDevelopment\TicketWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\AssignTicketRequest;
use App\Http\Requests\AppDevelopment\CompleteTicketRequest;
use App\Http\Requests\AppDevelopment\RejectTicketRequest;
use App\Http\Requests\AppDevelopment\SubmitForQaRequest;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use App\Services\AppDevelopment\TicketWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TicketWorkflowController extends Controller
{
    public function __construct(
        private readonly TicketWorkflowService $workflow,
    ) {}

    public function startWork(Request $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $this->authorize('startWork', $ticket);

        try {
            $updated = $this->workflow->startWork($ticket, $request->user());
        } catch (TicketWorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('app-development.tickets.show', $updated)
            ->with('success', __('app-development.flash.started_work', ['ticket' => $updated->ticket_number]));
    }

    public function sendToQa(SubmitForQaRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        try {
            $updated = $this->workflow->submitForQa(
                $ticket,
                $request->user(),
                $request->validated('note'),
            );
        } catch (TicketWorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('app-development.tickets.show', $updated)
            ->with('success', __('app-development.flash.sent_to_qa'));
    }

    public function returnToDevelopment(RejectTicketRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        try {
            $updated = $this->workflow->returnToDevelopment(
                $ticket,
                $request->user(),
                $request->validated('note'),
            );
        } catch (TicketWorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('app-development.tickets.show', $updated)
            ->with('success', __('app-development.flash.returned_to_development'));
    }

    public function complete(CompleteTicketRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        try {
            $updated = $this->workflow->complete(
                $ticket,
                $request->user(),
                $request->validated('note'),
            );
        } catch (TicketWorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('app-development.tickets.show', $updated)
            ->with('success', __('app-development.flash.ticket_completed'));
    }

    public function assign(AssignTicketRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $developer = User::query()->findOrFail((int) $request->validated('assigned_to'));

        try {
            $updated = $this->workflow->assignDeveloper($ticket, $request->user(), $developer);
        } catch (TicketWorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('app-development.tickets.show', $updated)
            ->with('success', __('app-development.flash.ticket_assigned'));
    }
}
