<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Detects whether a user-supplied category label matches an existing API category
 * (menu categories or ingredients categories), using normalized equality on all name fields.
 */
final class MenuCategoryNameMatcher
{
    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    public static function exists(string $label, array $categories): bool
    {
        $normalizedInput = self::normalize($label);
        if ($normalizedInput === '') {
            return false;
        }

        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            foreach (['name', 'name_en', 'name_ar', 'name_he'] as $field) {
                if (!isset($cat[$field])) {
                    continue;
                }
                $candidate = self::normalize((string) $cat[$field]);
                if ($candidate !== '' && $candidate === $normalizedInput) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }
}
