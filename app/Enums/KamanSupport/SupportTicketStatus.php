<?php

declare(strict_types=1);

namespace App\Enums\KamanSupport;

enum SupportTicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingRestaurant = 'waiting_restaurant';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return __('kaman-support.status.'.$this->value);
    }

    /**
     * @return array{bg: string, text: string, border: string}
     */
    public function tone(): array
    {
        return match ($this) {
            self::Open => [
                'bg' => 'bg-sky-50',
                'text' => 'text-sky-800',
                'border' => 'border-sky-200',
            ],
            self::InProgress => [
                'bg' => 'bg-amber-50',
                'text' => 'text-amber-800',
                'border' => 'border-amber-200',
            ],
            self::WaitingRestaurant => [
                'bg' => 'bg-violet-50',
                'text' => 'text-violet-800',
                'border' => 'border-violet-200',
            ],
            self::Resolved => [
                'bg' => 'bg-emerald-50',
                'text' => 'text-emerald-800',
                'border' => 'border-emerald-200',
            ],
            self::Closed => [
                'bg' => 'bg-slate-50',
                'text' => 'text-slate-700',
                'border' => 'border-slate-200',
            ],
        };
    }
}
