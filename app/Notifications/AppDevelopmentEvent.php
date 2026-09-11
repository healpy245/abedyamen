<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AppDevelopmentTicketActivityType;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use Illuminate\Notifications\Notification;

class AppDevelopmentEvent extends Notification
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public AppDevelopmentTicketActivityType $event,
        public AppDevelopmentTicket $ticket,
        public ?User $actor = null,
        public array $metadata = [],
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
        $excerpt = $this->metadata['note'] ?? $this->metadata['excerpt'] ?? null;

        return [
            'event' => $this->event->value,
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'title' => $this->ticket->title,
            'actor' => $this->actor?->name,
            'excerpt' => is_string($excerpt) ? mb_substr($excerpt, 0, 140) : null,
            'url' => route('app-development.tickets.show', $this->ticket),
            'version_name' => $this->metadata['version_name'] ?? null,
        ];
    }
}
