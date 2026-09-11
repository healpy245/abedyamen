<?php

declare(strict_types=1);

namespace App\Services\Haat;

final class HaatMenuParser
{
    private const DEFAULT_GROUP_TITLE = 'إضافات';

    /**
     * @var array<string, string>
     */
    private const TITLE_ENGLISH = [
        'أختيار نوع الخبز' => 'Bread',
        'اختيار نوع الخبز' => 'Bread',
        'اختيار الخبز' => 'Bread',
        'إضافات' => 'Addons',
        'اضافات' => 'Addons',
        'إضافات مميزة' => 'Special addons',
        'اضافات مميزة' => 'Special addons',
        'مكونات السلطة' => 'Salad ingredients',
        'اختيار' => 'Choice',
        'الوجبة تشمل' => 'Included',
    ];

    /**
     * @return array{
     *     categories: list<array{haat_id: string, name_ar: string, name_en: string, name_he: string, order_index: int}>,
     *     meals: list<array<string, mixed>>,
     *     unique_ingredient_categories: list<array{title: string, name_ar: string, name_en: string, name_he: string}>,
     *     unique_ingredients: list<array<string, mixed>>
     * }
     */
    public function parse(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('HAAT menu JSON is invalid.');
        }

        return $this->parseArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     categories: list<array{haat_id: string, name_ar: string, name_en: string, name_he: string, order_index: int}>,
     *     meals: list<array<string, mixed>>,
     *     unique_ingredient_categories: list<array{title: string, name_ar: string, name_en: string, name_he: string}>,
     *     unique_ingredients: list<array<string, mixed>>
     * }
     */
    public function parseArray(array $payload): array
    {
        $sections = $payload['sections'] ?? null;
        if (! is_array($sections)) {
            throw new \InvalidArgumentException('HAAT menu JSON must contain a sections array.');
        }

        $categories = [];
        $meals = [];
        $ingredientCategories = [];
        $ingredients = [];
        $order = 0;

        foreach ($sections as $section) {
            if (! is_array($section) || ($section['type'] ?? '') !== 'Categories') {
                continue;
            }

            $order++;
            $categoryHaatId = $this->stringifyId($section['id'] ?? $order);
            $nameAr = trim((string) ($section['name'] ?? ''));
            $nameEn = trim((string) ($section['nameEnglish'] ?? ''));
            if ($nameEn === '') {
                $nameEn = $nameAr;
            }

            $categories[] = [
                'haat_id' => $categoryHaatId,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'name_he' => $nameEn,
                'order_index' => $order,
            ];

            foreach ($section['restaurantItems'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $parsedMeal = $this->parseMeal($item, $categoryHaatId);
                $meals[] = $parsedMeal;

                foreach ($parsedMeal['groups'] as $group) {
                    $titleKey = $this->normalizeTitle((string) $group['title']);
                    if (! isset($ingredientCategories[$titleKey])) {
                        $english = $this->englishForTitle((string) $group['title']);
                        $cashier = $this->cashierCategoryFromGroup($group);
                        $ingredientCategories[$titleKey] = [
                            'title' => $titleKey,
                            'name_ar' => $titleKey,
                            'name_en' => $english,
                            'name_he' => $english,
                            'type' => $cashier['type'],
                            'min_options' => $cashier['min_options'],
                            'max_options' => $cashier['max_options'],
                            'must_pick' => $cashier['must_pick'],
                            'order_index' => count($ingredientCategories) + 1,
                        ];
                    }

                    foreach ($group['contents'] as $content) {
                        $haatId = (string) $content['haat_id'];
                        if ($haatId === '') {
                            continue;
                        }

                        $name = (string) $content['name'];
                        $uniqueKey = $this->nameKey($name).'|'.$titleKey.'|'.(string) $content['price'];
                        if (isset($ingredients[$uniqueKey])) {
                            $ingredients[$uniqueKey]['haat_ids'][] = $haatId;
                            if (($ingredients[$uniqueKey]['image_url'] ?? null) === null && ($content['image_url'] ?? null) !== null) {
                                $ingredients[$uniqueKey]['image_url'] = $content['image_url'];
                            }
                            continue;
                        }

                        $ingredients[$uniqueKey] = [
                            'haat_id' => $haatId,
                            'haat_ids' => [$haatId],
                            'name' => $name,
                            'name_ar' => $name,
                            'name_en' => $name,
                            'name_he' => $name,
                            'price' => (string) $content['price'],
                            'price_number' => (float) $content['price'],
                            'category_title' => $titleKey,
                            'view_type' => (string) $content['view_type'],
                            'allow_multiple' => $this->allowMultiple($content),
                            'order_index' => count($ingredients) + 1,
                            'image_url' => $content['image_url'],
                        ];
                    }
                }
            }
        }

        return [
            'categories' => $categories,
            'meals' => $meals,
            'unique_ingredient_categories' => array_values($ingredientCategories),
            'unique_ingredients' => array_values($ingredients),
        ];
    }

    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException('HAAT menu JSON file was not found: '.$path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException('Unable to read HAAT menu JSON file: '.$path);
        }

        return $this->parse($contents);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function parseMeal(array $item, string $categoryHaatId): array
    {
        $menuItemType = (string) ($item['menuItemType'] ?? 'Item');
        $nameAr = trim((string) ($item['name'] ?? ''));
        $nameEn = trim((string) ($item['nameEnglish'] ?? ''));
        if ($nameEn === '') {
            $nameEn = $nameAr;
        }
        $description = trim((string) ($item['description'] ?? ''));
        $price = $item['price']['finalPrice'] ?? 0;
        $available = (bool) ($item['availability']['isAvailable'] ?? true);
        $imageUrl = $item['image']['serverImageUrl'] ?? null;

        $groups = [];
        if ($menuItemType === 'Meal') {
            foreach ($item['addonGroups'] ?? [] as $group) {
                if (! is_array($group)) {
                    continue;
                }
                $groups[] = $this->parseGroup($group);
            }
        }

        return [
            'haat_id' => $this->stringifyId($item['id'] ?? ''),
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'name_he' => $nameEn,
            'description_ar' => $description,
            'description_en' => $description,
            'description_he' => $description,
            'price' => $this->formatPrice($price),
            'image_url' => is_string($imageUrl) && $imageUrl !== '' ? $imageUrl : null,
            'is_available' => $available,
            'category_haat_id' => $categoryHaatId,
            'menu_item_type' => $menuItemType,
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function parseGroup(array $group): array
    {
        $title = $this->normalizeTitle((string) ($group['title'] ?? ''));
        $contents = [];
        foreach ($group['mealContents'] ?? [] as $content) {
            if (! is_array($content)) {
                continue;
            }
            $contents[] = $this->parseContent($content);
        }

        return [
            'haat_id' => $this->stringifyId($group['id'] ?? ''),
            'title' => $title,
            'min' => (int) ($group['min'] ?? 0),
            'max' => (int) ($group['max'] ?? 0),
            'is_limited' => (bool) ($group['isLimited'] ?? false),
            'show_images' => (bool) ($group['showImages'] ?? false),
            'free' => $group['free'] ?? null,
            'contents' => $contents,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function parseContent(array $content): array
    {
        $imageUrl = $content['image']['serverImageUrl'] ?? null;

        return [
            'haat_id' => $this->stringifyId($content['id'] ?? ''),
            'name' => trim((string) ($content['name'] ?? '')),
            'price' => $this->formatPrice($content['price'] ?? 0),
            'view_type' => (string) ($content['viewType'] ?? ''),
            'selected_by_default' => (bool) ($content['selectedByDefault'] ?? false),
            'non_modifiable' => (bool) ($content['nonModifiable'] ?? false),
            'max_count' => (int) ($content['maxCount'] ?? 0),
            'image_url' => is_string($imageUrl) && $imageUrl !== '' ? $imageUrl : null,
        ];
    }

    public function normalizeTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return $title !== '' ? $title : self::DEFAULT_GROUP_TITLE;
    }

    public function englishForTitle(string $title): string
    {
        $normalized = $this->normalizeTitle($title);

        return self::TITLE_ENGLISH[$normalized] ?? $normalized;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{type: string, min_options: int, max_options: int, must_pick: bool}
     */
    public function cashierCategoryFromGroup(array $group): array
    {
        $min = max(0, (int) ($group['min'] ?? 0));
        $max = max(0, (int) ($group['max'] ?? 0));
        $type = $this->cashierType($group, $min, $max);

        return [
            'type' => $type,
            'min_options' => $min,
            'max_options' => $max > 0 ? $max : ($type === 'one_option' ? 1 : 99),
            'must_pick' => $min >= 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public function cashierType(array $group, ?int $min = null, ?int $max = null): string
    {
        $min ??= (int) ($group['min'] ?? 0);
        $max ??= (int) ($group['max'] ?? 0);
        $viewTypes = [];
        foreach ($group['contents'] ?? [] as $content) {
            if (is_array($content)) {
                $viewTypes[] = (string) ($content['view_type'] ?? '');
            }
        }
        $unique = array_values(array_unique($viewTypes));
        if ($min === 1 && $max === 1) {
            return 'one_option';
        }
        if ($unique === ['Radio'] || (in_array('Radio', $unique, true) && ! in_array('Checkbox', $unique, true))) {
            return 'one_option';
        }

        return 'multi_option';
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function allowMultiple(array $content): bool
    {
        $viewType = (string) ($content['view_type'] ?? '');
        $maxCount = (int) ($content['max_count'] ?? 0);

        return $viewType === 'Checkbox' || $maxCount > 1;
    }

    private function formatPrice(mixed $price): string
    {
        return number_format((float) $price, 2, '.', '');
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    private function stringifyId(mixed $id): string
    {
        if (is_int($id) || is_float($id)) {
            return (string) $id;
        }

        return trim((string) $id);
    }
}
