<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Models\AppDevelopment\AppDevelopmentTicket;

class TicketNumberService
{
    public function assign(AppDevelopmentTicket $ticket): string
    {
        $number = sprintf('KAM-%04d', $ticket->id);

        $ticket->forceFill(['ticket_number' => $number])->save();

        return $number;
    }
}
