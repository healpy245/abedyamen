<?php

declare(strict_types=1);

namespace App\Enums;

enum AppDevelopmentTaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';

    public function label(): string
    {
        return __('app-development.task_status.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Todo => 'inbox',
            self::InProgress => 'wrench',
            self::Paused => 'minus',
            self::Completed => 'check-circle',
        };
    }

    /**
     * @return array{bg: string, text: string, border: string}
     */
    public function tone(): array
    {
        return match ($this) {
            self::Todo => [
                'bg' => 'bg-slate-50',
                'text' => 'text-slate-700',
                'border' => 'border-slate-200',
            ],
            self::InProgress => [
                'bg' => 'bg-amber-50',
                'text' => 'text-amber-800',
                'border' => 'border-amber-200',
            ],
            self::Paused => [
                'bg' => 'bg-sky-50',
                'text' => 'text-sky-800',
                'border' => 'border-sky-200',
            ],
            self::Completed => [
                'bg' => 'bg-emerald-50',
                'text' => 'text-emerald-800',
                'border' => 'border-emerald-200',
            ],
        };
    }
}
