<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AppDevelopment\AppDevelopmentTimeEntry;
use App\Models\User;
use App\Enums\Project;

class AppDevelopmentTimeEntryPolicy
{
    public function editTimeEntry(User $user, AppDevelopmentTimeEntry $entry): bool
    {
        return $user->canAccessProject(Project::AppDevelopment)
            && $user->isAppDevelopmentAdmin();
    }

    public function update(User $user, AppDevelopmentTimeEntry $entry): bool
    {
        return $this->editTimeEntry($user, $entry);
    }

    public function delete(User $user, AppDevelopmentTimeEntry $entry): bool
    {
        return $this->editTimeEntry($user, $entry);
    }
}
