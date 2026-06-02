<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Builds structured meal descriptions from image filenames and matches meals to image files by name.
 */
final class MealImageFilenameHelper
{
    /**
     * @param  list<string>  $filenames  Basenames or paths; only the filename segment is used for meal names.
     */
    public static function buildDescriptionFromFilenames(array $filenames, string $categoryLabel = 'Meals'): string
    {
        $categoryLabel = trim($categoryLabel) !== '' ? trim($categoryLabel) : 'Meals';
        $lines = [];

        foreach ($filenames as $path) {
            $name = self::mealNameFromPath((string) $path);
            if ($name !== '') {
                $lines[] = $name . ' : ';
            }
        }

        if ($lines === []) {
            return '';
        }

        return $categoryLabel . ' : {' . "\n" . implode("\n", $lines) . "\n" . '}';
    }

    public static function mealNameFromPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $basename = basename($path);
        $name = pathinfo($basename, PATHINFO_FILENAME);

        return trim($name);
    }

    public static function normalizeForMatch(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[\s\-_]+/u', '', $value) ?? $value;

        return $value;
    }

    /**
     * Match meals to images by comparing meal name_en (or name_ar) to file basename.
     *
     * @param  array<string, array<string, mixed>>  $meals
     * @param  array<int, string>  $imagePaths  Absolute filesystem paths keyed by index or list
     * @return array<string, array<string, mixed>>
     */
    public static function attachImagesByFilename(array $meals, array $imagePaths): array
    {
        $imageMap = [];

        foreach ($imagePaths as $path) {
            $path = (string) $path;
            if ($path === '') {
                continue;
            }
            $keyNorm = self::normalizeForMatch(self::mealNameFromPath($path));
            if ($keyNorm !== '') {
                $imageMap[$keyNorm] = $path;
            }
        }

        $result = [];

        foreach ($meals as $mealKey => $meal) {
            $mealCopy = $meal;
            $mealCopy['image_path'] = null;

            $candidates = array_filter([
                trim((string) ($meal['name_en'] ?? '')),
                trim((string) ($meal['name_ar'] ?? '')),
                trim((string) ($meal['name_he'] ?? '')),
            ]);

            foreach ($candidates as $candidate) {
                $norm = self::normalizeForMatch($candidate);
                if ($norm !== '' && isset($imageMap[$norm])) {
                    $mealCopy['image_path'] = $imageMap[$norm];
                    unset($imageMap[$norm]);
                    break;
                }
            }

            $result[$mealKey] = $mealCopy;
        }

        return $result;
    }

    /**
     * Resolve public-relative paths to absolute paths and match.
     *
     * @param  array<string, array<string, mixed>>  $meals
     * @param  list<string>  $relativePaths  e.g. "folderName/burger.jpg"
     */
    public static function attachImagesByRelativePaths(array $meals, array $relativePaths): array
    {
        $absolute = [];
        foreach ($relativePaths as $rel) {
            $rel = str_replace('\\', '/', trim((string) $rel));
            if ($rel === '') {
                continue;
            }
            $full = public_path($rel);
            if (is_file($full)) {
                $absolute[] = $full;
            }
        }

        return self::attachImagesByFilename($meals, $absolute);
    }
}
