<?php

declare(strict_types=1);

namespace App\Services\Haat;

use Illuminate\Support\Facades\Http;

final class KamanPublicMenuScraper
{
    /**
     * @return array<string, mixed>
     */
    public function scrape(string $menuUrl): array
    {
        $http = Http::timeout(60)->withHeaders([
            'Accept' => 'text/html',
            'User-Agent' => 'Mozilla/5.0 (compatible; KamanMenuClone/1.0)',
        ]);
        if (! config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }

        $response = $http->get($menuUrl);
        if (! $response->successful()) {
            throw new \RuntimeException('Could not load public menu (HTTP '.$response->status().').');
        }

        return $this->parseHtml($response->body());
    }

    /**
     * @return array<string, mixed>
     */
    public function parseHtml(string $html): array
    {
        $categories = $this->parseCategories($html);
        $items = $this->parseItems($html);
        $links = $this->parseItemsIngredients($html);

        $ingCats = [];
        $ingredients = [];
        $meals = [];

        foreach ($items as $item) {
            $categoryId = (string) $item['category_id'];
            if ($categoryId === '' || ! isset($categories[$categoryId])) {
                continue;
            }

            $groups = [];
            $grouped = $links[$item['id']] ?? [];
            foreach ($grouped as $groupName => $rows) {
                if (! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $ingId = (string) ($row['id'] ?? '');
                    if ($ingId === '') {
                        continue;
                    }
                    $title = $this->ingredientCategoryTitle($row, (string) $groupName);
                    if (! isset($ingCats[$title])) {
                        $type = (string) ($row['category_type'] ?? 'multi_option');
                        $min = (int) ($row['min_options'] ?? 0);
                        $max = (int) ($row['max_options'] ?? 0);
                        $ingCats[$title] = [
                            'title' => $title,
                            'name_ar' => $title,
                            'name_en' => $title,
                            'name_he' => $title,
                            'type' => $type !== '' ? $type : 'multi_option',
                            'min_options' => $min,
                            'max_options' => $max > 0 ? $max : ($type === 'one_option' ? 1 : 99),
                            'must_pick' => (bool) ($row['must_pick'] ?? false),
                            'order_index' => count($ingCats) + 1,
                        ];
                    }
                    if (! isset($ingredients[$ingId])) {
                        $name = trim((string) ($row['name'] ?? ''));
                        $price = (float) ($row['extra_fee_cost'] ?? $row['price'] ?? 0);
                        $image = $row['image'] ?? null;
                        $ingredients[$ingId] = [
                            'haat_id' => $ingId,
                            'name' => $name,
                            'name_ar' => $name,
                            'name_en' => $name,
                            'name_he' => $name,
                            'price' => number_format($price, 2, '.', ''),
                            'price_number' => $price,
                            'category_title' => $title,
                            'image_url' => is_string($image) && $image !== '' ? $image : null,
                            'allow_multiple' => (bool) ($row['allow_multiple'] ?? false),
                            'order_index' => count($ingredients) + 1,
                        ];
                    }
                    $groups[$title] ??= [
                        'haat_id' => $title,
                        'title' => $title,
                        'min' => (int) ($ingCats[$title]['min_options'] ?? 0),
                        'max' => (int) ($ingCats[$title]['max_options'] ?? 0),
                        'is_limited' => ((int) ($ingCats[$title]['max_options'] ?? 0)) > 0,
                        'show_images' => true,
                        'free' => null,
                        'contents' => [],
                    ];
                    $price = (float) ($row['extra_fee_cost'] ?? $row['price'] ?? 0);
                    $groups[$title]['contents'][] = [
                        'haat_id' => $ingId,
                        'name' => (string) ($row['name'] ?? ''),
                        'price' => number_format($price, 2, '.', ''),
                        'view_type' => ! empty($row['allow_multiple']) ? 'Checkbox' : ((string) ($row['category_type'] ?? '') === 'one_option' ? 'Radio' : 'Checkbox'),
                        'selected_by_default' => (bool) ($row['default_ingredient'] ?? false),
                        'non_modifiable' => false,
                        'max_count' => ! empty($row['allow_multiple']) ? 99 : 1,
                        'image_url' => is_string($row['image'] ?? null) ? $row['image'] : null,
                    ];
                }
            }

            $name = $item['name'];
            $description = $item['description'] !== '' ? $item['description'] : $name;
            $meals[] = [
                'haat_id' => $item['id'],
                'name_ar' => $name,
                'name_en' => $name,
                'name_he' => $name,
                'description_ar' => $description,
                'description_en' => $description,
                'description_he' => $description,
                'price' => number_format((float) $item['price'], 2, '.', ''),
                'image_url' => $item['image'] !== '' ? $item['image'] : null,
                'is_available' => true,
                'category_haat_id' => $categoryId,
                'menu_item_type' => $groups !== [] ? 'Meal' : 'Item',
                'groups' => array_values($groups),
            ];
        }

        return [
            'categories' => array_values($categories),
            'meals' => $meals,
            'unique_ingredient_categories' => array_values($ingCats),
            'unique_ingredients' => array_values($ingredients),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseCategories(string $html): array
    {
        $categories = [];
        $order = 0;
        if (preg_match_all(
            '/class="z-chip menu-chip"[^>]*data-target="section-([^"]+)"[^>]*>\s*([^<]+)/u',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $id = trim($match[1]);
                $name = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($id === '' || $name === '') {
                    continue;
                }
                $order++;
                $categories[$id] = [
                    'haat_id' => $id,
                    'name_ar' => $name,
                    'name_en' => $name,
                    'name_he' => $name,
                    'order_index' => $order,
                ];
            }
        }

        return $categories;
    }

    /**
     * @return list<array{id: string, name: string, description: string, price: string, image: string, category_id: string}>
     */
    private function parseItems(string $html): array
    {
        $items = [];
        if (! preg_match_all(
            '/<li class="menu-item-row"[^>]*data-cat="([^"]+)"[^>]*>.*?<div class="z-item showItem[^"]*"[^>]*data-id="([^"]+)"[^>]*data-name="([^"]*)"[^>]*data-description="([^"]*)"[^>]*data-price="([^"]*)"[^>]*data-type="([^"]*)"[^>]*data-image="([^"]*)"/su',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        foreach ($matches as $match) {
            $items[] = [
                'category_id' => html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'id' => html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'name' => html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => html_entity_decode($match[4], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'price' => html_entity_decode($match[5], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'type' => html_entity_decode($match[6], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'image' => html_entity_decode($match[7], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function parseItemsIngredients(string $html): array
    {
        $start = strpos($html, 'itemsIngredients =');
        if ($start === false) {
            $start = strpos($html, 'itemsIngredients=');
        }
        if ($start === false) {
            return [];
        }
        $eq = strpos($html, '{', $start);
        if ($eq === false) {
            return [];
        }

        $length = strlen($html);
        $depth = 0;
        $end = null;
        $inString = false;
        $escape = false;
        for ($i = $eq; $i < $length; $i++) {
            $ch = $html[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($end === null) {
            return [];
        }

        $decoded = json_decode(substr($html, $eq, $end - $eq + 1), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ingredientCategoryTitle(array $row, string $fallback): string
    {
        $name = trim((string) ($row['category_name'] ?? $fallback));
        $type = (string) ($row['category_type'] ?? '');
        if ($name === '' || $name === '.') {
            $name = $type === 'one_option' ? 'خيارات' : 'إضافات';
        }
        if ($type === 'one_option') {
            return $name === 'خيارات' ? $name : $name.' (واحد)';
        }

        return $name;
    }
}
