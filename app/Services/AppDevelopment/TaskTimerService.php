<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Enums\AppDevelopmentTaskStatus;
use App\Exceptions\AppDevelopment\TaskTimerException;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TaskTimerService
{
    public function activeEntryForUser(User $user): ?AppDevelopmentTimeEntry
    {
        return AppDevelopmentTimeEntry::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();
    }

    public function start(AppDevelopmentTask $task, User $user): AppDevelopmentTimeEntry
    {
        if ($task->isCompleted()) {
            throw TaskTimerException::invalid(__('app-development.errors.task_already_completed'));
        }

        $open = $this->activeEntryForUser($user);
        if ($open !== null) {
            throw TaskTimerException::conflict(__('app-development.errors.timer_already_running'));
        }

        return DB::transaction(function () use ($task, $user): AppDevelopmentTimeEntry {
            $entry = AppDevelopmentTimeEntry::query()->create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'started_at' => Carbon::now(),
            ]);

            $task->forceFill([
                'status' => AppDevelopmentTaskStatus::InProgress,
            ])->save();

            return $entry;
        });
    }

    public function pause(AppDevelopmentTask $task, User $user): AppDevelopmentTimeEntry
    {
        return DB::transaction(function () use ($task, $user): AppDevelopmentTimeEntry {
            $entry = $this->openEntryFor($task, $user);
            $this->closeEntry($entry);

            $task->forceFill([
                'status' => AppDevelopmentTaskStatus::Paused,
            ])->save();

            return $entry->fresh() ?? $entry;
        });
    }

    public function complete(AppDevelopmentTask $task, User $user, ?string $note = null): AppDevelopmentTask
    {
        return DB::transaction(function () use ($task, $user, $note): AppDevelopmentTask {
            $entry = $task->activeEntryFor($user);
            if ($entry !== null) {
                $this->closeEntry($entry);
            }

            $task->forceFill([
                'status' => AppDevelopmentTaskStatus::Completed,
                'completed_at' => Carbon::now(),
                'completion_note' => filled($note) ? trim($note) : null,
            ])->save();

            return $task->fresh() ?? $task;
        });
    }

    /**
     * @param  array{duration_seconds?: int, note?: string|null, edit_reason?: string|null}  $data
     */
    public function updateEntry(AppDevelopmentTimeEntry $entry, User $admin, array $data): AppDevelopmentTimeEntry
    {
        if ($entry->isActive()) {
            throw TaskTimerException::invalid(__('app-development.errors.cannot_edit_active_timer'));
        }

        $duration = (int) ($data['duration_seconds'] ?? $entry->duration_seconds);
        if ($duration < 0) {
            throw TaskTimerException::invalid(__('app-development.errors.invalid_duration'));
        }

        $original = $entry->original_duration_seconds ?? $entry->duration_seconds;

        $entry->forceFill([
            'duration_seconds' => $duration,
            'note' => array_key_exists('note', $data) ? $data['note'] : $entry->note,
            'edited_by' => $admin->id,
            'edit_reason' => filled($data['edit_reason'] ?? null)
                ? trim((string) $data['edit_reason'])
                : $entry->edit_reason,
            'original_duration_seconds' => $original,
            'ended_at' => $entry->started_at?->copy()->addSeconds($duration),
        ])->save();

        return $entry->fresh() ?? $entry;
    }

    public function deleteEntry(AppDevelopmentTimeEntry $entry): void
    {
        if ($entry->isActive()) {
            throw TaskTimerException::invalid(__('app-development.errors.cannot_edit_active_timer'));
        }

        $entry->delete();
    }

    private function openEntryFor(AppDevelopmentTask $task, User $user): AppDevelopmentTimeEntry
    {
        $entry = $task->activeEntryFor($user);
        if ($entry === null) {
            throw TaskTimerException::invalid(__('app-development.errors.no_open_timer'));
        }

        return $entry;
    }

    private function closeEntry(AppDevelopmentTimeEntry $entry): void
    {
        $ended = Carbon::now();
        $started = $entry->started_at instanceof Carbon
            ? $entry->started_at
            : Carbon::parse((string) $entry->started_at);

        $entry->forceFill([
            'ended_at' => $ended,
            'duration_seconds' => max(0, $started->diffInSeconds($ended)),
        ])->save();
    }
}
