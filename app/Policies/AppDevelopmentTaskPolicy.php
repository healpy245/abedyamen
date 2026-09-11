<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use App\Models\User;
use App\Enums\Project;

class AppDevelopmentTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessProject(Project::AppDevelopment);
    }

    public function view(User $user, AppDevelopmentTask $task): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && ($user->isQa() || $user->isDeveloper());
    }

    public function update(User $user, AppDevelopmentTask $task): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->isAppDevelopmentAdmin()) {
            return true;
        }

        return (int) $task->created_by === (int) $user->id
            || (int) $task->assignee_id === (int) $user->id;
    }

    public function startTimer(User $user, AppDevelopmentTask $task): bool
    {
        return $this->timerAction($user, $task);
    }

    public function pauseTimer(User $user, AppDevelopmentTask $task): bool
    {
        return $this->timerAction($user, $task);
    }

    public function complete(User $user, AppDevelopmentTask $task): bool
    {
        return $this->timerAction($user, $task);
    }

    public function editTimeEntry(User $user, AppDevelopmentTimeEntry $entry): bool
    {
        return $this->viewAny($user) && $user->isAppDevelopmentAdmin();
    }

    private function timerAction(User $user, AppDevelopmentTask $task): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        // Only the assigned worker can start/pause/complete their own timer.
        return (int) $task->assignee_id === (int) $user->id;
    }
}
