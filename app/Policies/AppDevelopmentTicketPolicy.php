<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;

class AppDevelopmentTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessProject(Project::AppDevelopment);
    }

    public function view(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->isQa();
    }

    public function delete(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->create($user);
    }

    public function update(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->create($user)
            && (int) $ticket->created_by === (int) $user->id
            && $ticket->isOpen();
    }

    public function changePriority(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->viewAny($user) && ($user->isQa() || $user->isDeveloper());
    }

    public function changeStatus(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->changePriority($user, $ticket);
    }

    public function changeAppTypes(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->changePriority($user, $ticket);
    }

    public function startWork(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->viewAny($user) && $user->isDeveloper();
    }

    public function assign(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->viewAny($user) && $user->isDeveloper() && ! $ticket->isCompleted();
    }

    public function submitForQa(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->viewAny($user) && $user->isDeveloper() && $ticket->isWorking();
    }

    public function qaApprove(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->viewAny($user) && $user->isQa() && $ticket->isWaitingForQa();
    }

    public function qaReject(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->qaApprove($user, $ticket);
    }

    public function comment(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->viewAny($user) && ($user->isQa() || $user->isDeveloper());
    }

    public function uploadAttachment(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->comment($user, $ticket);
    }

    public function downloadAttachment(User $user, AppDevelopmentTicket $ticket): bool
    {
        return $this->view($user, $ticket);
    }
}
