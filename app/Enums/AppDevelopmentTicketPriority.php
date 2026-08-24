<?php

declare(strict_types=1);

namespace App\Enums;

enum AppDevelopmentTicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return __('app-development.priority.'.$this->value);
    }

    /**
     * @return array{bg: string, text: string, border: string}
     */
    public function tone(): array
    {
        return match ($this) {
            self::Low => [
                'bg' => 'bg-slate-50',
                'text' => 'text-slate-600',
                'border' => 'border-slate-200',
            ],
            self::Normal => [
                'bg' => 'bg-sky-50',
                'text' => 'text-sky-700',
                'border' => 'border-sky-200',
            ],
            self::High => [
                'bg' => 'bg-orange-50',
                'text' => 'text-orange-800',
                'border' => 'border-orange-200',
            ],
            self::Critical => [
                'bg' => 'bg-red-50',
                'text' => 'text-red-800',
                'border' => 'border-red-300',
            ],
        };
    }
}
