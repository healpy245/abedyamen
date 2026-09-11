<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Exceptions\AppDevelopment\TaskTimerException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\CompleteTaskRequest;
use App\Http\Requests\AppDevelopment\UpdateTimeEntryRequest;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use App\Services\AppDevelopment\TaskTimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskTimerController extends Controller
{
    public function __construct(
        private readonly TaskTimerService $timers,
    ) {}

    public function start(Request $request, AppDevelopmentTask $task): JsonResponse|RedirectResponse
    {
        $this->authorize('startTimer', $task);

        try {
            $entry = $this->timers->start($task, $request->user());
        } catch (TaskTimerException $e) {
            return $this->respond($request, false, $e->getMessage(), $task, $e->getCode() ?: 422);
        }

        return $this->respond(
            $request,
            true,
            __('app-development.flash.timer_started'),
            $task,
            200,
            [
                'entry_id' => $entry->id,
                'started_at' => $entry->started_at?->toIso8601String(),
                'elapsed_seconds' => $entry->elapsedSeconds(),
            ],
        );
    }

    public function pause(Request $request, AppDevelopmentTask $task): JsonResponse|RedirectResponse
    {
        $this->authorize('pauseTimer', $task);

        try {
            $entry = $this->timers->pause($task, $request->user());
        } catch (TaskTimerException $e) {
            return $this->respond($request, false, $e->getMessage(), $task, $e->getCode() ?: 422);
        }

        return $this->respond(
            $request,
            true,
            __('app-development.flash.timer_paused'),
            $task,
            200,
            [
                'entry_id' => $entry->id,
                'duration_seconds' => $entry->duration_seconds,
            ],
        );
    }

    public function complete(CompleteTaskRequest $request, AppDevelopmentTask $task): JsonResponse|RedirectResponse
    {
        try {
            $this->timers->complete(
                $task,
                $request->user(),
                $request->validated('completion_note'),
            );
        } catch (TaskTimerException $e) {
            return $this->respond($request, false, $e->getMessage(), $task, $e->getCode() ?: 422);
        }

        $task->loadMissing('ticket');
        $message = __('app-development.flash.task_completed');
        $extra = [];

        if ($task->ticket && $task->ticket->allTasksCompleted() && ! $task->ticket->isCompleted()) {
            $extra['suggest_complete_ticket'] = true;
            $extra['ticket_number'] = $task->ticket->ticket_number;
            $message = __('app-development.flash.suggest_complete_ticket', [
                'ticket' => $task->ticket->ticket_number,
            ]);
        }

        return $this->respond($request, true, $message, $task, 200, $extra);
    }

    public function updateEntry(UpdateTimeEntryRequest $request, AppDevelopmentTimeEntry $timeEntry): RedirectResponse
    {
        try {
            $this->timers->updateEntry($timeEntry, $request->user(), $request->validated());
        } catch (TaskTimerException $e) {
            return back()->with('error', $e->getMessage());
        }

        $timeEntry->loadMissing('task.ticket');

        return redirect()
            ->route('app-development.tickets.show', [
                'ticket' => $timeEntry->task?->ticket ?? $timeEntry->task?->ticket_id,
                'task' => $timeEntry->task_id,
            ])
            ->with('success', __('app-development.flash.time_entry_updated'));
    }

    public function destroyEntry(Request $request, AppDevelopmentTimeEntry $timeEntry): RedirectResponse
    {
        $this->authorize('editTimeEntry', $timeEntry);
        $timeEntry->loadMissing('task.ticket');
        $taskId = $timeEntry->task_id;
        $ticket = $timeEntry->task?->ticket ?? $timeEntry->task?->ticket_id;

        try {
            $this->timers->deleteEntry($timeEntry);
        } catch (TaskTimerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('app-development.tickets.show', [
                'ticket' => $ticket ?? $taskId,
                'task' => $taskId,
            ])
            ->with('success', __('app-development.flash.time_entry_deleted'));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(
        Request $request,
        bool $ok,
        string $message,
        AppDevelopmentTask $task,
        int $status = 200,
        array $extra = [],
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(array_merge([
                'ok' => $ok,
                'message' => $message,
                'task_id' => $task->id,
                'status' => $task->fresh()?->status?->value,
            ], $extra), $ok ? $status : ($status >= 400 ? $status : 422));
        }

        $task->loadMissing('ticket');

        $redirect = redirect()->route('app-development.tickets.show', [
            'ticket' => $task->ticket ?? $task->ticket_id,
            'task' => $task->id,
        ]);

        if (! $ok) {
            return $redirect->with('error', $message);
        }

        if (! empty($extra['suggest_complete_ticket'])) {
            return $redirect
                ->with('success', __('app-development.flash.task_completed'))
                ->with('info', $message);
        }

        return $redirect->with('success', $message);
    }
}
