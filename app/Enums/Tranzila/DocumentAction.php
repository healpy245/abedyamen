<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum DocumentAction: int
{
    case Debit = 1;
    case Credit = 3;

    public function label(): string
    {
        return __('tranzila.document_action.'.$this->value);
    }
}
