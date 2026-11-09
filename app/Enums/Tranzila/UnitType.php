<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum UnitType: int
{
    case Unit = 1;
    case Gram = 2;
    case Kilogram = 3;
    case MetricTon = 4;
    case Day = 5;
    case Week = 6;
    case Month = 7;
    case Year = 8;
    case Centimeter = 9;
    case Meter = 10;
    case Kilometer = 11;
    case Megabyte = 12;
    case Gigabyte = 13;
    case Terabyte = 14;

    public function label(): string
    {
        return __('tranzila.unit_type.'.$this->value);
    }
}
