<?php

declare(strict_types=1);

namespace App\Services\Haat;

use App\Support\KamanUrl;

final class KamanMenuSnapshotParser
{
    /**
     * Turn a live Kaman snapshot into the same shape HAAT Menu Copy writes.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function parse(array $snapshot, ?string $managerBaseUrl = null): array
    {
        $origin = $managerBaseUrl ? KamanUrl::originFromManagerApi($managerBaseUrl) : '';
        $categories = [];
        $categoryIds = [];
        $order = 0;
        foreach ($snapshot['categories'] ?? [] as $category) {
            if (! is_array($category)) {
                continue;
            }
            $id = $this->id($category);
            if ($id === '') {
                continue;
            }
            $order++;
            $names = $this->names($category);
            $categories[] = [
                'haat_id' => $id,
                'name_ar' => $names['ar'],
                'name_en' => $names['en'],
                'name_he' => $names['he'],
                'order_index' => (int) ($category['order_index'] ?? $category['position'] ?? $order),
                'image_url' => $this->imageUrl($category, $origin),
            ];
            $categoryIds[$id] = true;
        }

        $ingCatsById = [];
        $uniqueIngCats = [];
        foreach ($snapshot['ingredient_categories'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $this->id($row);
            if ($id === '') {
                continue;
            }
            $names = $this->names($row);
            $title = $names['ar'] !== '' ? $names['ar'] : $names['en'];
            $min = (int) ($row['min_options'] ?? $row['min'] ?? 0);
            $max = (int) ($row['max_options'] ?? $row['max'] ?? 0);
            $type = (string) ($row['type'] ?? ($min === 1 && $max === 1 ? 'one_option' : 'multi_option'));
            $parsed = [
                'title' => $title,
                'name_ar' => $names['ar'] ?: $title,
                'name_en' => $names['en'] ?: $title,
                'name_he' => $names['he'] ?: ($names['en'] ?: $title),
                'type' => $type,
                'min_options' => $min,
                'max_options' => $max > 0 ? $max : ($type === 'one_option' ? 1 : 99),
                'must_pick' => (bool) ($row['must_pick'] ?? ($min >= 1)),
                'order_index' => (int) ($row['order_index'] ?? $row['position'] ?? count($uniqueIngCats) + 1),
            ];
            $ingCatsById[$id] = $parsed;
            $uniqueIngCats[$title] = $parsed;
        }

        $ingredientsById = [];
        $uniqueIngredients = [];
        foreach ($snapshot['ingredients'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $this->id($row);
            if ($id === '') {
                continue;
            }
            $names = $this->names($row);
            $name = $names['ar'] !== '' ? $names['ar'] : $names['en'];
            $catId = (string) ($row['category_id'] ?? $row['ingredient_category_id'] ?? '');
            $cat = $ingCatsById[$catId] ?? null;
            $title = is_array($cat) ? (string) $cat['title'] : 'إضافات';
            if (! isset($uniqueIngCats[$title])) {
                $uniqueIngCats[$title] = [
                    'title' => $title,
                    'name_ar' => $title,
                    'name_en' => $title,
                    'name_he' => $title,
                    'type' => 'multi_option',
                    'min_options' => 0,
                    'max_options' => 99,
                    'must_pick' => false,
                    'order_index' => count($uniqueIngCats) + 1,
                ];
            }
            $price = $this->price($row['price'] ?? $row['extra_fee'] ?? $row['custom_fee'] ?? 0);
            $ingredient = [
                'haat_id' => $id,
                'name' => $name,
                'name_ar' => $names['ar'] ?: $name,
                'name_en' => $names['en'] ?: $name,
                'name_he' => $names['he'] ?: ($names['en'] ?: $name),
                'price' => $price,
                'price_number' => (float) $price,
                'category_title' => $title,
                'image_url' => $this->imageUrl($row, $origin),
                'allow_multiple' => (bool) ($row['allow_multiple'] ?? false),
                'order_index' => (int) ($row['order_index'] ?? $row['position'] ?? 0),
            ];
            $ingredientsById[$id] = $ingredient;
            $uniqueIngredients[] = $ingredient;
        }

        $meals = [];
        foreach ($snapshot['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $this->id($item);
            if ($id === '') {
                continue;
            }
            $names = $this->names($item);
            $categoryId = (string) ($item['category_id'] ?? '');
            if ($categoryId === '' || ! isset($categoryIds[$categoryId])) {
                continue;
            }
            $description = $this->descriptions($item, $names);
            $groups = $this->groupsForItem(
                $snapshot['item_links'][$id] ?? [],
                $ingredientsById,
                $ingCatsById,
            );

            $meals[] = [
                'haat_id' => $id,
                'name_ar' => $names['ar'],
                'name_en' => $names['en'],
                'name_he' => $names['he'],
                'description_ar' => $description['ar'],
                'description_en' => $description['en'],
                'description_he' => $description['he'],
                'price' => $this->price($item['price'] ?? 0),
                'image_url' => $this->imageUrl($item, $origin),
                'is_available' => $this->isAvailable($item),
                'category_haat_id' => $categoryId,
                'menu_item_type' => $groups !== [] ? 'Meal' : 'Item',
                'groups' => $groups,
            ];
        }

        return [
            'categories' => $categories,
            'meals' => $meals,
            'unique_ingredient_categories' => array_values($uniqueIngCats),
            'unique_ingredients' => $uniqueIngredients,
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $links
     * @param  array<string, array<string, mixed>>  $ingredientsById
     * @param  array<string, array<string, mixed>>  $ingCatsById
     * @return list<array<string, mixed>>
     */
    private function groupsForItem(array $links, array $ingredientsById, array $ingCatsById): array
    {
        $byTitle = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            $ingredientId = (string) ($link['ingredient_id'] ?? $link['id'] ?? '');
            $ingredient = $ingredientsById[$ingredientId] ?? null;
            if ($ingredient === null) {
                continue;
            }
            $title = (string) $ingredient['category_title'];
            $cat = null;
            foreach ($ingCatsById as $row) {
                if (($row['title'] ?? '') === $title) {
                    $cat = $row;
                    break;
                }
            }
            if (! isset($byTitle[$title])) {
                $min = (int) ($cat['min_options'] ?? 0);
                $max = (int) ($cat['max_options'] ?? 0);
                $byTitle[$title] = [
                    'haat_id' => $title,
                    'title' => $title,
                    'min' => $min,
                    'max' => $max,
                    'is_limited' => $max > 0,
                    'show_images' => true,
                    'free' => null,
                    'contents' => [],
                ];
            }
            $price = (float) ($link['custom_fee'] ?? $ingredient['price_number'] ?? 0);
            if (! ($link['extra_fee'] ?? false)) {
                $price = (float) ($link['custom_fee'] ?? $ingredient['price_number'] ?? 0);
            }
            $byTitle[$title]['contents'][] = [
                'haat_id' => $ingredientId,
                'name' => (string) $ingredient['name'],
                'price' => number_format($price, 2, '.', ''),
                'view_type' => ! empty($ingredient['allow_multiple']) ? 'Checkbox' : 'Radio',
                'selected_by_default' => (bool) ($link['default_ingredient'] ?? false),
                'non_modifiable' => false,
                'max_count' => ! empty($ingredient['allow_multiple']) ? 99 : 1,
                'image_url' => $ingredient['image_url'] ?? null,
            ];
        }

        return array_values($byTitle);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function id(array $row): string
    {
        $id = $row['id'] ?? '';

        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{ar: string, en: string, he: string}
     */
    private function names(array $row): array
    {
        $ar = trim((string) ($row['name_ar'] ?? $row['name'] ?? ''));
        $en = trim((string) ($row['name_en'] ?? ''));
        $he = trim((string) ($row['name_he'] ?? ''));
        if ($en === '') {
            $en = $ar;
        }
        if ($ar === '') {
            $ar = $en;
        }
        if ($he === '') {
            $he = $en !== '' ? $en : $ar;
        }

        return ['ar' => $ar, 'en' => $en, 'he' => $he];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{ar: string, en: string, he: string}  $names
     * @return array{ar: string, en: string, he: string}
     */
    private function descriptions(array $row, array $names): array
    {
        $ar = trim((string) ($row['description_ar'] ?? $row['description'] ?? ''));
        $en = trim((string) ($row['description_en'] ?? ''));
        $he = trim((string) ($row['description_he'] ?? ''));

        return [
            'ar' => $ar !== '' ? $ar : $names['ar'],
            'en' => $en !== '' ? $en : $names['en'],
            'he' => $he !== '' ? $he : $names['he'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function imageUrl(array $row, string $origin): ?string
    {
        $candidates = [
            $row['image_url'] ?? null,
            $row['image'] ?? null,
            $row['photo'] ?? null,
            $row['thumbnail'] ?? null,
            $row['picture'] ?? null,
            is_array($row['media'] ?? null) ? ($row['media']['url'] ?? ($row['media']['image'] ?? null)) : null,
        ];
        foreach ($candidates as $value) {
            if (is_array($value)) {
                $value = $value['url'] ?? $value['src'] ?? $value['path'] ?? $value['serverImageUrl'] ?? null;
            }
            $value = trim((string) $value);
            if ($value === '' || str_starts_with($value, 'data:')) {
                continue;
            }
            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                return $value;
            }
            if ($origin !== '') {
                return rtrim($origin, '/').'/'.ltrim($value, '/');
            }

            return $value;
        }

        return null;
    }

    private function price(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['finalPrice'] ?? $value['price'] ?? $value['amount'] ?? 0;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isAvailable(array $item): bool
    {
        if (array_key_exists('status', $item)) {
            return (string) $item['status'] !== '0' && $item['status'] !== 0 && $item['status'] !== false;
        }
        if (array_key_exists('is_available', $item)) {
            return (bool) $item['is_available'];
        }

        return true;
    }
}
