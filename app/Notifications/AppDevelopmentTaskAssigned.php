<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\User;
use Illuminate\Notifications\Notification;

class AppDevelopmentTaskAssigned extends Notification
{
    public function __construct(
        public AppDevelopmentTask $task,
        public ?User $actor = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $ticket = $this->task->ticket;

        return [
            'event' => 'task_assigned',
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'ticket_id' => $ticket?->id,
            'ticket_number' => $ticket?->ticket_number,
            'title' => $this->task->title,
            'actor' => $this->actor?->name,
            'excerpt' => $ticket?->ticket_number,
            'url' => route('app-development.tasks.show', $this->task),
        ];
    }
}
