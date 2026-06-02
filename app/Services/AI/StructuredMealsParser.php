<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Parses structured category blocks into meal payloads without calling OpenAI.
 */
final class StructuredMealsParser
{
    /**
     * @param  list<array{label: string, body: string}>  $blocks
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>
     */
    public static function parseBlocks(array $blocks, array $categories): array
    {
        $meals = [];
        $index = 0;

        foreach ($blocks as $block) {
            $label = trim($block['label']);
            $body = trim($block['body']);
            if ($body === '' || $label === '') {
                continue;
            }

            $categoryId = MenuCategoryResolver::resolveId($label, $categories);
            if ($categoryId === null || $categoryId === '') {
                throw new \RuntimeException(
                    'Could not resolve category "' . $label . '" after creation attempt.'
                );
            }

            foreach (self::parseItemLines($body) as $item) {
                $index++;
                $name = $item['name'];
                $meals['meal' . $index] = [
                    'name_ar' => $name,
                    'name_en' => $name,
                    'name_he' => $name,
                    'price' => self::normalizePrice($item['price']),
                    'category_id' => $categoryId,
                    'description_ar' => '',
                    'description_en' => '',
                    'description_he' => '',
                ];
            }
        }

        return $meals;
    }

    /**
     * @return list<array{name: string, price: string}>
     */
    private static function parseItemLines(string $body): array
    {
        $items = [];

        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(.+?)\s*:\s*(.*)$/u', $line, $m)) {
                continue;
            }
            $name = trim($m[1]);
            if ($name === '') {
                continue;
            }
            $items[] = ['name' => $name, 'price' => trim($m[2])];
        }

        return $items;
    }

    /** Blank, missing, or non-numeric prices become "0.00". */
    public static function normalizePrice(string $price): string
    {
        $price = trim($price);
        if ($price === '' || $price === ':' || $price === '-') {
            return '0.00';
        }

        $clean = preg_replace('/[^\d.,]/', '', $price) ?? $price;
        $clean = str_replace(',', '.', $clean);

        if ($clean === '' || !is_numeric($clean)) {
            return '0.00';
        }

        return number_format((float) $clean, 2, '.', '');
    }
}
