<?php

declare(strict_types=1);

namespace App\Enums\Sales;

enum LeadStage: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Meeting = 'meeting';
    case Demo = 'demo';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return __('sales.lead_stage.'.$this->value);
    }
}
