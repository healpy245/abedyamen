<?php

declare(strict_types=1);

namespace App\Services\Form;

use Illuminate\Support\Facades\Http;

final class WoltMenuSource
{
    public function isWoltUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_contains($host, 'wolt.com') && str_contains($path, '/restaurant/');
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $url): array
    {
        $http = Http::timeout(60)->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Accept' => 'text/html',
            'Accept-Language' => 'en,he,ar',
        ]);
        if (! config('services.kaman.ssl_verify', true)) {
            $http = $http->withoutVerifying();
        }

        $response = $http->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('Could not open the Wolt page (HTTP '.$response->status().').');
        }

        return $this->parseHtml($response->body());
    }

    /**
     * @return array<string, mixed>
     */
    public function parseHtml(string $html): array
    {
        $assortment = $this->assortment($html);
        if ($assortment === null) {
            throw new \RuntimeException('The Wolt page did not include a menu.');
        }

        $language = (string) ($assortment['primary_language'] ?? $assortment['selected_language'] ?? 'he');
        $categories = [];
        $itemCategory = [];
        $order = 0;
        foreach ($assortment['categories'] ?? [] as $category) {
            if (! is_array($category)) {
                continue;
            }
            $id = trim((string) ($category['id'] ?? ''));
            $name = $this->clean((string) ($category['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $order++;
            $categories[] = $this->namedRow($id, $name, $language, [
                'haat_id' => $id,
                'order_index' => $order,
                'description_ar' => '',
                'description_en' => '',
                'description_he' => '',
            ], (string) ($category['description'] ?? ''));
            foreach ($category['item_ids'] ?? [] as $itemId) {
                $itemId = trim((string) $itemId);
                if ($itemId !== '' && ! isset($itemCategory[$itemId])) {
                    $itemCategory[$itemId] = $id;
                }
            }
        }

        $options = [];
        foreach ($assortment['options'] ?? [] as $option) {
            if (! is_array($option)) {
                continue;
            }
            $id = trim((string) ($option['id'] ?? ''));
            if ($id !== '') {
                $options[$id] = $option;
            }
        }

        $meals = [];
        $groups = [];
        $ingredients = [];
        foreach ($assortment['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $name = $this->clean((string) ($item['name'] ?? ''));
            $categoryId = $itemCategory[$id] ?? '';
            if ($id === '' || $name === '' || $categoryId === '') {
                continue;
            }

            $mealGroups = [];
            foreach ($item['options'] ?? [] as $binding) {
                if (! is_array($binding)) {
                    continue;
                }
                $optionId = trim((string) ($binding['option_id'] ?? ''));
                $option = $options[$optionId] ?? null;
                if ($optionId === '' || ! is_array($option)) {
                    continue;
                }
                $range = is_array($binding['multi_choice_config']['total_range'] ?? null)
                    ? $binding['multi_choice_config']['total_range']
                    : [];
                $min = max(0, (int) ($range['min'] ?? 0));
                $max = max(0, (int) ($range['max'] ?? 0));
                $title = $this->clean((string) ($binding['name'] ?? $option['name'] ?? 'Options'));
                $key = $optionId.':'.$min.':'.$max;
                $contents = [];
                $default = (string) ($option['default_value'] ?? '');
                foreach ($option['values'] ?? [] as $value) {
                    if (! is_array($value)) {
                        continue;
                    }
                    $valueId = trim((string) ($value['id'] ?? ''));
                    $valueName = $this->clean((string) ($value['name'] ?? ''));
                    if ($valueId === '' || $valueName === '') {
                        continue;
                    }
                    $price = $this->money($value['price'] ?? 0);
                    $contents[] = [
                        'haat_id' => $valueId,
                        'name' => $valueName,
                        'price' => $price,
                        'view_type' => $max <= 1 ? 'Radio' : 'Checkbox',
                        'selected_by_default' => $default !== '' && $default === $valueId,
                        'image_url' => $this->imageUrl($value['images'] ?? null),
                    ];
                    $ingredientKey = $valueId.'|'.$key;
                    if (! isset($ingredients[$ingredientKey])) {
                        $ingredients[$ingredientKey] = $this->namedRow($valueId, $valueName, $language, [
                            'haat_id' => $valueId,
                            'haat_ids' => [$valueId],
                            'name' => $valueName,
                            'price' => $price,
                            'price_number' => (float) $price,
                            'category_title' => $key,
                            'view_type' => $max <= 1 ? 'Radio' : 'Checkbox',
                            'allow_multiple' => false,
                            'order_index' => count($ingredients) + 1,
                            'image_url' => $this->imageUrl($value['images'] ?? null),
                        ]);
                    }
                }
                if ($contents === []) {
                    continue;
                }
                if (! isset($groups[$key])) {
                    $groups[$key] = $this->namedRow($key, $title, $language, [
                        'title' => $key,
                        'type' => $max <= 1 ? 'one_option' : 'multi_option',
                        'min_options' => max(1, $min),
                        'max_options' => max(2, $max),
                        'must_pick' => $min >= 1,
                        'order_index' => count($groups) + 1,
                    ]);
                }
                $mealGroups[] = [
                    'haat_id' => $key,
                    'title' => $key,
                    'min' => $min,
                    'max' => $max,
                    'contents' => $contents,
                ];
            }

            $meals[] = $this->namedRow($id, $name, $language, [
                'haat_id' => $id,
                'price' => $this->money($item['price'] ?? 0),
                'image_url' => $this->imageUrl($item['images'] ?? null),
                'is_available' => ($item['disabled_info'] ?? null) === null,
                'category_haat_id' => $categoryId,
                'groups' => $mealGroups,
            ], (string) ($item['description'] ?? ''));
        }

        if ($categories === [] || $meals === []) {
            throw new \RuntimeException('The Wolt menu had no meals to copy.');
        }

        return [
            'categories' => $this->disambiguateNames(array_values($categories)),
            'meals' => $meals,
            'unique_ingredient_categories' => $this->disambiguateNames(array_values($groups)),
            'unique_ingredients' => array_values($ingredients),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function disambiguateNames(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name_en'] ?: $row['name_ar'] ?: $row['name_he']);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        $seen = [];
        foreach ($rows as &$row) {
            $name = (string) ($row['name_en'] ?: $row['name_ar'] ?: $row['name_he']);
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            if (($counts[$name] ?? 0) < 2) {
                continue;
            }
            $suffix = ' '.$seen[$name];
            foreach (['name_ar', 'name_en', 'name_he'] as $field) {
                if (trim((string) ($row[$field] ?? '')) !== '') {
                    $row[$field] .= $suffix;
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function namedRow(string $id, string $name, string $language, array $extra, string $description = ''): array
    {
        $language = in_array($language, ['ar', 'en', 'he'], true) ? $language : $this->scriptOf($name);
        $description = $this->clean($description);
        $row = $extra;
        $row['source_name'] = $name;
        foreach (['ar', 'en', 'he'] as $code) {
            $row['name_'.$code] = $code === $language ? $name : '';
            $row['description_'.$code] = $code === $language ? $description : '';
        }

        return $row;
    }

    private function scriptOf(string $text): string
    {
        if (preg_match('/\p{Hebrew}/u', $text) === 1) {
            return 'he';
        }
        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'ar';
        }

        return 'en';
    }

    private function money(mixed $value): string
    {
        if (is_string($value) && str_contains($value, '.')) {
            return number_format((float) $value, 2, '.', '');
        }

        return number_format(((float) $value) / 100, 2, '.', '');
    }

    private function imageUrl(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }
        $first = $images[0] ?? null;
        if (is_string($first) && str_starts_with($first, 'http')) {
            return $first;
        }
        $url = is_array($first) ? ($first['url'] ?? null) : null;

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/[\x{200e}\x{200f}\x{202a}-\x{202e}]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assortment(string $html): ?array
    {
        if (preg_match_all('/<script[^>]*>\s*(\{.*?\})\s*<\/script>/su', $html, $matches) !== 1 && ($matches[1] ?? []) === []) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded['queries'] ?? [] as $query) {
                if (! is_array($query)) {
                    continue;
                }
                $key = $query['queryKey'] ?? null;
                if (! is_array($key) || ($key[0] ?? '') !== 'venue-assortment' || ($key[1] ?? '') !== 'category-listing') {
                    continue;
                }
                $data = $query['state']['data'] ?? null;
                if (is_array($data) && is_array($data['items'] ?? null) && $data['items'] !== []) {
                    return $data;
                }
            }
        }

        return null;
    }
}
