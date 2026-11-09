<?php

declare(strict_types=1);

namespace App\Enums\Sales;

enum AppointmentType: string
{
    case Visit = 'visit';
    case Demo = 'demo';
    case Call = 'call';
    case Followup = 'followup';
    case Onboarding = 'onboarding';

    public function label(): string
    {
        return __('sales.appointment_type.'.$this->value);
    }
}
