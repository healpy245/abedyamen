<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum StorageCategory: string
{
    case Hardware = 'hardware';
    case Service = 'service';
    case Subscription = 'subscription';
    case Other = 'other';

    public function label(): string
    {
        return __('finance.category.'.$this->value);
    }

    /**
     * Colour family used by the finance pills (ok, warn, bad, info, muted).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Hardware => 'info',
            self::Service => 'ok',
            self::Subscription => 'warn',
            self::Other => 'muted',
        };
    }
}
