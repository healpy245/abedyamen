<?php

declare(strict_types=1);

namespace App\Enums;

enum AppDevelopmentTicketType: string
{
    case Bug = 'bug';
    case Feature = 'feature';
    case Improvement = 'improvement';
    case Optimization = 'optimization';
    case UiUx = 'ui_ux';
    case Other = 'other';

    public function label(): string
    {
        return __('app-development.types.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Bug => 'bug',
            self::Feature => 'sparkles',
            self::Improvement => 'trending',
            self::Optimization => 'zap',
            self::UiUx => 'layout',
            self::Other => 'dots',
        };
    }
}
