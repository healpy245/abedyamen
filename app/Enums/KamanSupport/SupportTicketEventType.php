<?php

declare(strict_types=1);

namespace App\Enums\KamanSupport;

enum SupportTicketEventType: string
{
    case Created = 'created';
    case Note = 'note';
    case Diagnosis = 'diagnosis';
    case CommandResult = 'command_result';
    case StatusChanged = 'status_changed';
    case AssigneeChanged = 'assignee_changed';

    public function label(): string
    {
        return __('kaman-support.event.'.$this->value);
    }
}
