<?php

declare(strict_types=1);

namespace App\Enums;

enum AppDevelopmentTicketStatus: string
{
    case Open = 'open';
    case Working = 'working';
    case Qa = 'qa';
    case Completed = 'completed';

    public function label(): string
    {
        return __('app-development.status.'.$this->value);
    }

    /**
     * @return array{bg: string, text: string, border: string}
     */
    public function tone(): array
    {
        return match ($this) {
            self::Open => [
                'bg' => 'bg-slate-50',
                'text' => 'text-slate-700',
                'border' => 'border-slate-200',
            ],
            self::Working => [
                'bg' => 'bg-amber-50',
                'text' => 'text-amber-800',
                'border' => 'border-amber-200',
            ],
            self::Qa => [
                'bg' => 'bg-violet-50',
                'text' => 'text-violet-800',
                'border' => 'border-violet-200',
            ],
            self::Completed => [
                'bg' => 'bg-emerald-50',
                'text' => 'text-emerald-800',
                'border' => 'border-emerald-200',
            ],
        };
    }
}
