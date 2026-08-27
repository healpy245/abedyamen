<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Exceptions\AppDevelopment\TicketWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\AssignTicketRequest;
use App\Http\Requests\AppDevelopment\CompleteTicketRequest;
use App\Http\Requests\AppDevelopment\RejectTicketRequest;
use App\Http\Requests\AppDevelopment\SubmitForQaRequest;
use App\Http\Requests\AppDevelopment\UpdateTicketAppTypesRequest;
use App\Http\Requests\AppDevelopment\UpdateTicketPriorityRequest;
use App\Http\Requests\AppDevelopment\UpdateTicketStatusRequest;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Services\AppDevelopment\TicketWorkflowService;
use Illuminate\Http\JsonResponse;
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

    public function updatePriority(UpdateTicketPriorityRequest $request, AppDevelopmentTicket $ticket): JsonResponse|RedirectResponse
    {
        $priority = AppDevelopmentTicketPriority::from($request->validated('priority'));

        try {
            $updated = $this->workflow->setPriority($ticket, $request->user(), $priority);
        } catch (TicketWorkflowException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            $updated->loadMissing('priorityChangedBy:id,name');

            return response()->json([
                'ok' => true,
                'value' => $updated->priority->value,
                'label' => $updated->priority->label(),
                'badge_html' => view('app-development.partials.priority-badge', [
                    'priority' => $updated->priority,
                ])->render(),
                'changed_by_name' => $updated->priorityChangedBy?->name,
                'critical' => $updated->priority === AppDevelopmentTicketPriority::Critical,
                'message' => __('app-development.flash.priority_updated'),
            ]);
        }

        return back()->with('success', __('app-development.flash.priority_updated'));
    }

    public function updateStatus(UpdateTicketStatusRequest $request, AppDevelopmentTicket $ticket): JsonResponse|RedirectResponse
    {
        $status = AppDevelopmentTicketStatus::from($request->validated('status'));

        try {
            $updated = $this->workflow->setStatus($ticket, $request->user(), $status);
        } catch (TicketWorkflowException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            $updated->loadMissing('statusChangedBy:id,name');

            return response()->json([
                'ok' => true,
                'value' => $updated->status->value,
                'label' => $updated->status->label(),
                'badge_html' => view('app-development.partials.status-badge', [
                    'status' => $updated->status,
                ])->render(),
                'changed_by_name' => $updated->statusChangedBy?->name,
                'status_counts' => AppDevelopmentTicket::statusCountsMap(),
                'message' => __('app-development.flash.status_updated'),
            ]);
        }

        return back()->with('success', __('app-development.flash.status_updated'));
    }

    public function updateAppTypes(UpdateTicketAppTypesRequest $request, AppDevelopmentTicket $ticket): JsonResponse|RedirectResponse
    {
        $ticket->syncAppTypes((array) $request->validated('app_types'));
        $ticket->load('appTypeRows');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'value' => collect($ticket->appTypes())->map(static fn ($type) => $type->value)->values()->all(),
                'badge_html' => view('app-development.partials.app-type-badges', [
                    'types' => $ticket->appTypes(),
                    'compact' => true,
                ])->render(),
                'app_type_counts' => AppDevelopmentTicket::appTypeCountsMap(),
                'message' => __('app-development.flash.app_types_updated'),
            ]);
        }

        return back()->with('success', __('app-development.flash.app_types_updated'));
    }
}
