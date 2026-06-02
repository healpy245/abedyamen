<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Resolves a user category label to a Kaman category id.
 */
final class MenuCategoryResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    public static function resolveId(string $label, array $categories): ?string
    {
        $normalizedInput = MenuCategoryNameMatcher::normalize($label);
        if ($normalizedInput === '') {
            return null;
        }

        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            foreach (['name', 'name_en', 'name_ar', 'name_he'] as $field) {
                if (!isset($cat[$field])) {
                    continue;
                }
                if (MenuCategoryNameMatcher::normalize((string) $cat[$field]) === $normalizedInput) {
                    return self::categoryId($cat);
                }
            }
        }

        // Fuzzy: folder label is a substring of a category name (or the reverse).
        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            foreach (['name', 'name_en', 'name_ar', 'name_he'] as $field) {
                if (!isset($cat[$field])) {
                    continue;
                }
                $candidate = MenuCategoryNameMatcher::normalize((string) $cat[$field]);
                if ($candidate === '') {
                    continue;
                }
                if (str_contains($candidate, $normalizedInput) || str_contains($normalizedInput, $candidate)) {
                    return self::categoryId($cat);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $cat
     */
    private static function categoryId(array $cat): ?string
    {
        $id = $cat['id'] ?? $cat['category_id'] ?? null;

        return $id !== null ? (string) $id : null;
    }
}
