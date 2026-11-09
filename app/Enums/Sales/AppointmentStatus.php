<?php

declare(strict_types=1);

namespace App\Enums\Sales;

enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Done = 'done';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('sales.appointment_status.'.$this->value);
    }
}
