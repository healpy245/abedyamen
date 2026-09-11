<?php

declare(strict_types=1);

namespace App\Enums;

enum AppDevelopmentRole: string
{
    case Qa = 'qa';
    case Developer = 'developer';
    case Admin = 'admin';

    public function label(): string
    {
        return __('app-development.roles.'.$this->value);
    }
}
