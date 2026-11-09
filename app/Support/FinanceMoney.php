<?php

declare(strict_types=1);

namespace App\Support;

final class FinanceMoney
{
    /**
     * Shekel amount without trailing ".00" so the numbers stay easy to scan.
     */
    public static function format(float|int|string|null $amount): string
    {
        $value = (float) $amount;
        $negative = $value < 0;
        $value = abs($value);
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;

        return ($negative ? '-' : '').'₪'.number_format($value, $decimals);
    }
}
