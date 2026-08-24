<?php

declare(strict_types=1);

namespace App\Support\AppDevelopment;

final class FileSize
{
    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 'B';

        foreach ($units as $candidate) {
            $value /= 1024;
            $unit = $candidate;
            if ($value < 1024) {
                break;
            }
        }

        $formatted = $value >= 100
            ? number_format($value, 0)
            : number_format($value, 1);

        return $formatted.' '.$unit;
    }
}
