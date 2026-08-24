<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\User;

class AppDevelopmentReleasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessProject(Project::AppDevelopment);
    }

    public function view(User $user, AppDevelopmentRelease $release): bool
    {
        return $this->viewAny($user);
    }

    public function uploadRelease(User $user): bool
    {
        return $this->viewAny($user) && $user->isDeveloper();
    }

    public function create(User $user): bool
    {
        return $this->uploadRelease($user);
    }

    public function downloadRelease(User $user, AppDevelopmentRelease $release): bool
    {
        return $this->view($user, $release);
    }
}
