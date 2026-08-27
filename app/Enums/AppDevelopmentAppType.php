<?php

declare(strict_types=1);

namespace App\Enums;

enum AppDevelopmentAppType: string
{
    case Printing = 'printing';
    case Cashier = 'cashier';
    case Web = 'web';
    case KamanClient = 'kaman_client';

    public function label(): string
    {
        return __('app-development.app_types.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Printing => 'printer',
            self::Cashier => 'pos',
            self::Web => 'globe',
            self::KamanClient => 'smartphone',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
