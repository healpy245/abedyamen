<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Issued = 'issued';
    case Failed = 'failed';
    // No usable answer came back, so the document may already exist in Tranzila.
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('tranzila.document_status.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Issued => 'green',
            self::Failed => 'red',
            self::Pending, self::Unknown => 'amber',
        };
    }
}
