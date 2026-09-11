<?php

declare(strict_types=1);

namespace App\Services\Haat;

use Illuminate\Support\Facades\Log;

final class KamanMenuSyncPlanner
{
    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $snapshot
     * @param  callable(string, string): string|null  $chat
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, mixed>
     */
    public function plan(array $parsed, array $snapshot, ?callable $chat = null, ?callable $progress = null): array
    {
        $progress && $progress('plan', 'Analyzing existing Kaman menu against HAAT...', [
            'categories' => count($snapshot['categories'] ?? []),
            'items' => count($snapshot['items'] ?? []),
            'ingredient_categories' => count($snapshot['ingredient_categories'] ?? []),
            'ingredients' => count($snapshot['ingredients'] ?? []),
        ]);

        $categoryById = $this->byId($snapshot['categories'] ?? []);
        $itemById = $this->byId($snapshot['items'] ?? []);
        $ingCatById = $this->byId($snapshot['ingredient_categories'] ?? []);
        $ingById = $this->byId($snapshot['ingredients'] ?? []);

        $categoryMatches = $this->matchByName($parsed['categories'] ?? [], $snapshot['categories'] ?? [], 'haat_id');
        $ingCatMatches = $this->matchIngredientCategories($parsed['unique_ingredient_categories'] ?? [], $snapshot['ingredient_categories'] ?? []);
        $ingredientMatches = $this->matchIngredients($parsed['unique_ingredients'] ?? [], $snapshot['ingredients'] ?? [], $ingCatMatches);

        $aiUsed = false;
        $unmatched = $this->unmatchedForAi($parsed, $categoryMatches, $ingCatMatches, $ingredientMatches, $snapshot);
        if ($unmatched !== null && $chat !== null) {
            $progress && $progress('plan', 'Using AI to match names that are not exact...', [
                'unmatched_categories' => count($unmatched['haat_categories']),
                'unmatched_meals' => count($unmatched['haat_meals']),
                'unmatched_ingredient_categories' => count($unmatched['haat_ingredient_categories']),
                'unmatched_ingredients' => count($unmatched['haat_ingredients']),
            ]);
            $aiUsed = $this->applyAiMatches(
                $chat,
                $unmatched,
                $categoryMatches,
                $ingCatMatches,
                $ingredientMatches,
            );
        }

        $mealMatches = $this->matchMeals($parsed['meals'] ?? [], $snapshot['items'] ?? [], $categoryMatches);

        if ($unmatched !== null && $chat !== null) {
            $stillUnmatchedMeals = [];
            foreach ($parsed['meals'] ?? [] as $meal) {
                $haatId = (string) ($meal['haat_id'] ?? '');
                if ($haatId !== '' && empty($mealMatches[$haatId]['kaman_id'])) {
                    $stillUnmatchedMeals[] = $meal;
                }
            }
            if ($stillUnmatchedMeals !== []) {
                $this->applyAiMealMatches($chat, $stillUnmatchedMeals, $snapshot['items'] ?? [], $categoryMatches, $mealMatches);
            }
        }

        $categories = [];
        foreach ($parsed['categories'] ?? [] as $category) {
            $haatId = (string) ($category['haat_id'] ?? '');
            if ($haatId === '') {
                continue;
            }
            $kamanId = $categoryMatches[$haatId] ?? null;
            $existing = $kamanId !== null ? ($categoryById[$kamanId] ?? null) : null;
            $categories[$haatId] = $this->categoryAction($category, $kamanId, $existing);
        }

        $meals = [];
        foreach ($parsed['meals'] ?? [] as $meal) {
            $haatId = (string) ($meal['haat_id'] ?? '');
            if ($haatId === '') {
                continue;
            }
            $kamanId = $mealMatches[$haatId]['kaman_id'] ?? null;
            $existing = is_string($kamanId) ? ($itemById[$kamanId] ?? null) : null;
            $targetCategoryId = $categoryMatches[(string) ($meal['category_haat_id'] ?? '')] ?? null;
            $meals[$haatId] = $this->mealAction($meal, $kamanId, $existing, $targetCategoryId);
        }

        $ingredientCategories = [];
        foreach ($parsed['unique_ingredient_categories'] ?? [] as $category) {
            $title = (string) ($category['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $kamanId = $ingCatMatches[$title] ?? null;
            $existing = $kamanId !== null ? ($ingCatById[$kamanId] ?? null) : null;
            $ingredientCategories[$title] = $this->ingredientCategoryAction($category, $kamanId, $existing);
        }

        $ingredients = [];
        foreach ($parsed['unique_ingredients'] ?? [] as $ingredient) {
            $haatId = (string) ($ingredient['haat_id'] ?? '');
            if ($haatId === '') {
                continue;
            }
            $kamanId = $ingredientMatches[$haatId] ?? null;
            $existing = $kamanId !== null ? ($ingById[$kamanId] ?? null) : null;
            $targetCategoryId = $ingCatMatches[(string) ($ingredient['category_title'] ?? '')] ?? null;
            $action = $this->ingredientAction($ingredient, $kamanId, $existing, $targetCategoryId);
            foreach ($this->ingredientHaatIds($ingredient) as $alias) {
                $ingredients[$alias] = $action;
            }
        }

        $links = $this->linkActions($parsed['meals'] ?? [], $meals, $ingredients, $snapshot['item_links'] ?? []);

        $summary = [
            'categories' => $this->countOps($categories),
            'meals' => $this->countOps($meals),
            'ingredient_categories' => $this->countOps($ingredientCategories),
            'ingredients' => $this->countOps($ingredients),
            'links' => $this->countOps($links),
        ];

        $progress && $progress('plan', $this->summaryMessage($summary, $aiUsed), [
            'status' => 'ok',
            'summary' => $summary,
            'ai_used' => $aiUsed,
        ]);

        return [
            'cashier_available' => (bool) ($snapshot['cashier_available'] ?? false),
            'ai_used' => $aiUsed,
            'summary' => $summary,
            'categories' => $categories,
            'meals' => $meals,
            'ingredient_categories' => $ingredientCategories,
            'ingredients' => $ingredients,
            'links' => $links,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $haatRows
     * @param  list<array<string, mixed>>  $kamanRows
     * @return array<string, string>
     */
    private function matchByName(array $haatRows, array $kamanRows, string $idKey): array
    {
        $index = $this->nameIndex($kamanRows);
        $used = [];
        $matches = [];
        foreach ($haatRows as $row) {
            $haatId = (string) ($row[$idKey] ?? '');
            if ($haatId === '') {
                continue;
            }
            $found = $this->lookupName($index, $row, $used);
            if ($found !== null) {
                $matches[$haatId] = $found;
                $used[$found] = true;
            }
        }

        return $matches;
    }

    /**
     * @param  list<array<string, mixed>>  $haatRows
     * @param  list<array<string, mixed>>  $kamanRows
     * @return array<string, string>
     */
    private function matchIngredientCategories(array $haatRows, array $kamanRows): array
    {
        $index = $this->nameIndex($kamanRows);
        $used = [];
        $matches = [];
        foreach ($haatRows as $row) {
            $title = (string) ($row['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $found = $this->lookupName($index, $row, $used);
            if ($found !== null) {
                $matches[$title] = $found;
                $used[$found] = true;
            }
        }

        return $matches;
    }

    /**
     * @param  list<array<string, mixed>>  $haatRows
     * @param  list<array<string, mixed>>  $kamanRows
     * @param  array<string, string>  $ingCatMatches
     * @return array<string, string>
     */
    private function matchIngredients(array $haatRows, array $kamanRows, array $ingCatMatches): array
    {
        $byName = [];
        foreach ($kamanRows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $categoryId = (string) ($row['category_id'] ?? '');
            foreach (['name_ar', 'name_en', 'name_he', 'name'] as $field) {
                $key = $this->nameKey((string) ($row[$field] ?? ''));
                if ($key !== '') {
                    $byName[$key][] = ['id' => $id, 'category_id' => $categoryId];
                }
            }
        }

        $used = [];
        $matches = [];
        foreach ($haatRows as $row) {
            $haatId = (string) ($row['haat_id'] ?? '');
            if ($haatId === '') {
                continue;
            }
            $wantedCategory = $ingCatMatches[(string) ($row['category_title'] ?? '')] ?? null;
            $found = null;
            if ($wantedCategory !== null) {
                foreach ([(string) ($row['name_ar'] ?? ''), (string) ($row['name_en'] ?? ''), (string) ($row['name'] ?? '')] as $name) {
                    $key = $this->nameKey($name);
                    if ($key === '' || ! isset($byName[$key])) {
                        continue;
                    }
                    foreach ($byName[$key] as $candidate) {
                        if (isset($used[$candidate['id']])) {
                            continue;
                        }
                        if ($candidate['category_id'] === $wantedCategory) {
                            $found = $candidate['id'];
                            break 2;
                        }
                    }
                }
            }
            if ($found !== null) {
                foreach ($this->ingredientHaatIds($row) as $alias) {
                    $matches[$alias] = $found;
                }
                $used[$found] = true;
            }
        }

        return $matches;
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>  $categoryMatches
     * @return array<string, array{kaman_id: ?string}>
     */
    private function matchMeals(array $meals, array $items, array $categoryMatches): array
    {
        $byName = [];
        foreach ($items as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $categoryId = (string) ($row['category_id'] ?? (is_array($row['category'] ?? null) ? ($row['category']['id'] ?? '') : ''));
            foreach (['name_ar', 'name_en', 'name_he', 'name'] as $field) {
                $key = $this->nameKey((string) ($row[$field] ?? ''));
                if ($key !== '') {
                    $byName[$key][] = ['id' => $id, 'category_id' => $categoryId];
                }
            }
        }

        $used = [];
        $matches = [];
        foreach ($meals as $meal) {
            $haatId = (string) ($meal['haat_id'] ?? '');
            if ($haatId === '') {
                continue;
            }
            $wantedCategory = $categoryMatches[(string) ($meal['category_haat_id'] ?? '')] ?? null;
            $found = null;
            foreach ([(string) ($meal['name_ar'] ?? ''), (string) ($meal['name_en'] ?? '')] as $name) {
                $key = $this->nameKey($name);
                if ($key === '' || ! isset($byName[$key])) {
                    continue;
                }
                foreach ($byName[$key] as $candidate) {
                    if (isset($used[$candidate['id']])) {
                        continue;
                    }
                    if ($wantedCategory === null || $candidate['category_id'] === $wantedCategory || $candidate['category_id'] === '') {
                        $found = $candidate['id'];
                        break 2;
                    }
                }
            }
            $matches[$haatId] = ['kaman_id' => $found];
            if ($found !== null) {
                $used[$found] = true;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, string>  $categoryMatches
     * @param  array<string, string>  $ingCatMatches
     * @param  array<string, string>  $ingredientMatches
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function unmatchedForAi(
        array $parsed,
        array $categoryMatches,
        array $ingCatMatches,
        array $ingredientMatches,
        array $snapshot,
    ): ?array {
        $haatCategories = [];
        foreach ($parsed['categories'] ?? [] as $row) {
            $id = (string) ($row['haat_id'] ?? '');
            if ($id !== '' && empty($categoryMatches[$id])) {
                $haatCategories[] = $this->compactName($id, $row);
            }
        }
        $haatMeals = [];
        foreach ($parsed['meals'] ?? [] as $row) {
            $id = (string) ($row['haat_id'] ?? '');
            if ($id !== '') {
                $haatMeals[] = $this->compactName($id, $row, ['category_haat_id' => (string) ($row['category_haat_id'] ?? '')]);
            }
        }
        $haatIngCats = [];
        foreach ($parsed['unique_ingredient_categories'] ?? [] as $row) {
            $title = (string) ($row['title'] ?? '');
            if ($title !== '' && empty($ingCatMatches[$title])) {
                $haatIngCats[] = $this->compactName($title, $row);
            }
        }
        $haatIngredients = [];
        foreach ($parsed['unique_ingredients'] ?? [] as $row) {
            $id = (string) ($row['haat_id'] ?? '');
            if ($id !== '' && empty($ingredientMatches[$id])) {
                $haatIngredients[] = $this->compactName($id, $row, ['category_title' => (string) ($row['category_title'] ?? '')]);
            }
        }

        $usedCategoryIds = array_flip(array_values($categoryMatches));
        $usedIngCatIds = array_flip(array_values($ingCatMatches));
        $usedIngIds = array_flip(array_values($ingredientMatches));

        $kamanCategories = [];
        foreach ($snapshot['categories'] ?? [] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && ! isset($usedCategoryIds[$id])) {
                $kamanCategories[] = $this->compactName($id, $row);
            }
        }
        $kamanItems = [];
        foreach ($snapshot['items'] ?? [] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $kamanItems[] = $this->compactName($id, $row, [
                    'category_id' => (string) ($row['category_id'] ?? ''),
                ]);
            }
        }
        $kamanIngCats = [];
        foreach ($snapshot['ingredient_categories'] ?? [] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && ! isset($usedIngCatIds[$id])) {
                $kamanIngCats[] = $this->compactName($id, $row);
            }
        }
        $kamanIngredients = [];
        foreach ($snapshot['ingredients'] ?? [] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && ! isset($usedIngIds[$id])) {
                $kamanIngredients[] = $this->compactName($id, $row, [
                    'category_id' => (string) ($row['category_id'] ?? ''),
                ]);
            }
        }

        $haatMealsUnmatched = [];
        // Meals matched later; AI meal matching is separate. Only continue if something else is unmatched
        // and there is something on the Kaman side to match against.
        $needsAi = ($haatCategories !== [] && $kamanCategories !== [])
            || ($haatIngCats !== [] && $kamanIngCats !== [])
            || ($haatIngredients !== [] && $kamanIngredients !== []);

        if (! $needsAi) {
            return null;
        }

        return [
            'haat_categories' => array_slice($haatCategories, 0, 80),
            'haat_meals' => array_slice($haatMealsUnmatched, 0, 80),
            'haat_ingredient_categories' => array_slice($haatIngCats, 0, 80),
            'haat_ingredients' => array_slice($haatIngredients, 0, 80),
            'kaman_categories' => array_slice($kamanCategories, 0, 80),
            'kaman_items' => array_slice($kamanItems, 0, 80),
            'kaman_ingredient_categories' => array_slice($kamanIngCats, 0, 80),
            'kaman_ingredients' => array_slice($kamanIngredients, 0, 80),
        ];
    }

    /**
     * @param  callable(string, string): string  $chat
     * @param  array<string, mixed>  $unmatched
     * @param  array<string, string>  $categoryMatches
     * @param  array<string, string>  $ingCatMatches
     * @param  array<string, string>  $ingredientMatches
     */
    private function applyAiMatches(
        callable $chat,
        array $unmatched,
        array &$categoryMatches,
        array &$ingCatMatches,
        array &$ingredientMatches,
    ): bool {
        $payload = [
            'haat_categories' => $unmatched['haat_categories'],
            'kaman_categories' => $unmatched['kaman_categories'],
            'haat_ingredient_categories' => $unmatched['haat_ingredient_categories'],
            'kaman_ingredient_categories' => $unmatched['kaman_ingredient_categories'],
            'haat_ingredients' => $unmatched['haat_ingredients'],
            'kaman_ingredients' => $unmatched['kaman_ingredients'],
        ];
        $decoded = $this->askJson($chat, <<<'SYS'
You match HAAT menu names to existing Kaman POS records. Names may differ by language, spelling, extra spaces, or translation.
Return JSON only:
{"categories":[{"haat_id":"...","kaman_id":"..."}],"ingredient_categories":[{"title":"...","kaman_id":"..."}],"ingredients":[{"haat_id":"...","kaman_id":"..."}]}
Use only IDs from the lists. Omit a pair if you are not confident it is the same record. Never invent IDs.
SYS, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
        if (! is_array($decoded)) {
            return false;
        }
        $this->mergeIdPairs($decoded['categories'] ?? [], $categoryMatches, 'haat_id');
        foreach ($decoded['ingredient_categories'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $kamanId = trim((string) ($row['kaman_id'] ?? ''));
            if ($title !== '' && $kamanId !== '' && empty($ingCatMatches[$title])) {
                $ingCatMatches[$title] = $kamanId;
            }
        }
        $this->mergeIdPairs($decoded['ingredients'] ?? [], $ingredientMatches, 'haat_id');

        return true;
    }

    /**
     * @param  callable(string, string): string  $chat
     * @param  list<array<string, mixed>>  $meals
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>  $categoryMatches
     * @param  array<string, array{kaman_id: ?string}>  $mealMatches
     */
    private function applyAiMealMatches(
        callable $chat,
        array $meals,
        array $items,
        array $categoryMatches,
        array &$mealMatches,
    ): void {
        $used = [];
        foreach ($mealMatches as $row) {
            if (! empty($row['kaman_id'])) {
                $used[(string) $row['kaman_id']] = true;
            }
        }
        $haat = [];
        foreach (array_slice($meals, 0, 80) as $meal) {
            $haat[] = $this->compactName((string) $meal['haat_id'], $meal, [
                'category_haat_id' => (string) ($meal['category_haat_id'] ?? ''),
                'price' => (string) ($meal['price'] ?? ''),
            ]);
        }
        $kaman = [];
        foreach (array_slice($items, 0, 80) as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id === '' || isset($used[$id])) {
                continue;
            }
            $kaman[] = $this->compactName($id, $item, [
                'category_id' => (string) ($item['category_id'] ?? ''),
                'price' => (string) ($item['price'] ?? ''),
            ]);
        }
        if ($haat === [] || $kaman === []) {
            return;
        }
        $decoded = $this->askJson($chat, <<<'SYS'
Match HAAT meals to existing Kaman items. Prefer same category and similar price. Return JSON only:
{"meals":[{"haat_id":"...","kaman_id":"..."}]}
Use only IDs from the lists. Omit if not confident. Never invent IDs.
SYS, json_encode(['haat_meals' => $haat, 'kaman_items' => $kaman, 'category_matches' => $categoryMatches], JSON_UNESCAPED_UNICODE) ?: '{}');
        foreach ($decoded['meals'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $haatId = trim((string) ($row['haat_id'] ?? ''));
            $kamanId = trim((string) ($row['kaman_id'] ?? ''));
            if ($haatId === '' || $kamanId === '' || isset($used[$kamanId])) {
                continue;
            }
            if (empty($mealMatches[$haatId]['kaman_id'])) {
                $mealMatches[$haatId] = ['kaman_id' => $kamanId];
                $used[$kamanId] = true;
            }
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @param  array<string, string>  $matches
     */
    private function mergeIdPairs(array $rows, array &$matches, string $haatKey): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $haatId = trim((string) ($row[$haatKey] ?? ''));
            $kamanId = trim((string) ($row['kaman_id'] ?? ''));
            if ($haatId !== '' && $kamanId !== '' && empty($matches[$haatId])) {
                $matches[$haatId] = $kamanId;
            }
        }
    }

    /**
     * @param  callable(string, string): string  $chat
     * @return array<string, mixed>|null
     */
    private function askJson(callable $chat, string $system, string $user): ?array
    {
        try {
            $raw = trim($chat($system, $user));
        } catch (\Throwable $e) {
            Log::warning('HAAT menu AI match failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m) === 1) {
            $raw = $m[1];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $category
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function categoryAction(array $category, ?string $kamanId, ?array $existing): array
    {
        if ($kamanId === null || $existing === null) {
            return ['op' => 'create', 'kaman_id' => null, 'changes' => ['create']];
        }
        $changes = [];
        foreach (['name_ar', 'name_en', 'name_he'] as $field) {
            if ($this->nameKey((string) ($existing[$field] ?? '')) !== $this->nameKey((string) ($category[$field] ?? ''))) {
                $changes[] = $field;
            }
        }
        $existingOrder = (int) ($existing['order_index'] ?? $existing['order'] ?? 0);
        if ((int) ($category['order_index'] ?? 0) !== $existingOrder && (int) ($category['order_index'] ?? 0) > 0) {
            $changes[] = 'order_index';
        }

        return [
            'op' => $changes === [] ? 'skip' : 'update',
            'kaman_id' => $kamanId,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $meal
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function mealAction(array $meal, ?string $kamanId, ?array $existing, ?string $targetCategoryId): array
    {
        if ($kamanId === null || $existing === null) {
            return ['op' => 'create', 'kaman_id' => null, 'changes' => ['create']];
        }
        $changes = [];
        foreach (['name_ar', 'name_en', 'name_he'] as $field) {
            $current = trim((string) ($existing[$field] ?? ''));
            $wanted = trim((string) ($meal[$field] ?? ''));
            if ($wanted !== '' && $this->nameKey($current) !== $this->nameKey($wanted) && $current !== '') {
                $changes[] = $field;
            }
        }
        foreach (['description_ar', 'description_en', 'description_he'] as $field) {
            $wanted = trim((string) ($meal[$field] ?? ''));
            if ($wanted === '') {
                continue;
            }
            $current = trim((string) ($existing[$field] ?? $existing['description'] ?? ''));
            if ($current !== '' && $this->nameKey($current) !== $this->nameKey($wanted)) {
                $changes[] = $field;
            } elseif ($current === '' && $wanted !== '') {
                $changes[] = $field;
            }
        }
        if ($this->priceDiffers($existing['price'] ?? null, $meal['price'] ?? null)) {
            $changes[] = 'price';
        }
        $existingCategory = (string) ($existing['category_id'] ?? (is_array($existing['category'] ?? null) ? ($existing['category']['id'] ?? '') : ''));
        if ($targetCategoryId !== null && $existingCategory !== '' && $existingCategory !== $targetCategoryId) {
            $changes[] = 'category_id';
        }
        $wantedAvailable = (bool) ($meal['is_available'] ?? true);
        if ($this->isAvailable($existing) !== $wantedAvailable) {
            $changes[] = 'status';
        }

        return [
            'op' => $changes === [] ? 'skip' : 'update',
            'kaman_id' => $kamanId,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $category
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function ingredientCategoryAction(array $category, ?string $kamanId, ?array $existing): array
    {
        if ($kamanId === null || $existing === null) {
            return ['op' => 'create', 'kaman_id' => null, 'changes' => ['create']];
        }
        $changes = [];
        foreach (['name_ar', 'name_en', 'name_he'] as $field) {
            $wanted = trim((string) ($category[$field] ?? ''));
            $current = trim((string) ($existing[$field] ?? ''));
            if ($wanted !== '' && $current !== '' && $this->nameKey($wanted) !== $this->nameKey($current)) {
                $changes[] = $field;
            }
        }
        $wantedType = (string) ($category['type'] ?? 'multi_option');
        $currentType = (string) ($existing['type'] ?? '');
        if ($currentType !== '' && $wantedType !== '' && $currentType !== $wantedType) {
            $changes[] = 'type';
        }
        if (array_key_exists('must_pick', $existing) && (bool) $existing['must_pick'] !== (bool) ($category['must_pick'] ?? false)) {
            $changes[] = 'must_pick';
        }
        if (isset($existing['min_options']) && (int) $existing['min_options'] !== (int) ($category['min_options'] ?? 0)) {
            $changes[] = 'min_options';
        }
        if (isset($existing['max_options']) && (int) $existing['max_options'] !== (int) ($category['max_options'] ?? 0)) {
            $changes[] = 'max_options';
        }

        return [
            'op' => $changes === [] ? 'skip' : 'update',
            'kaman_id' => $kamanId,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $ingredient
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function ingredientAction(array $ingredient, ?string $kamanId, ?array $existing, ?string $targetCategoryId): array
    {
        if ($kamanId === null || $existing === null) {
            return ['op' => 'create', 'kaman_id' => null, 'changes' => ['create']];
        }
        $changes = [];
        foreach (['name_ar', 'name_en', 'name_he'] as $field) {
            $wanted = trim((string) ($ingredient[$field] ?? ''));
            $current = trim((string) ($existing[$field] ?? ''));
            if ($wanted !== '' && $current !== '' && $this->nameKey($wanted) !== $this->nameKey($current)) {
                $changes[] = $field;
            }
        }
        if ($this->priceDiffers($existing['price'] ?? null, $ingredient['price'] ?? $ingredient['price_number'] ?? null)) {
            $changes[] = 'price';
        }
        $existingCategory = (string) ($existing['category_id'] ?? '');
        if ($targetCategoryId !== null && $existingCategory !== '' && $existingCategory !== $targetCategoryId) {
            $changes[] = 'category_id';
        }
        if (array_key_exists('allow_multiple', $existing) && (bool) $existing['allow_multiple'] !== (bool) ($ingredient['allow_multiple'] ?? false)) {
            $changes[] = 'allow_multiple';
        }

        return [
            'op' => $changes === [] ? 'skip' : 'update',
            'kaman_id' => $kamanId,
            'changes' => $changes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, array<string, mixed>>  $mealActions
     * @param  array<string, array<string, mixed>>  $ingredientActions
     * @param  array<string, array<string, array<string, mixed>>>  $itemLinks
     * @return list<array<string, mixed>>
     */
    private function linkActions(array $meals, array $mealActions, array $ingredientActions, array $itemLinks): array
    {
        $ops = [];
        foreach ($meals as $meal) {
            $mealHaatId = (string) ($meal['haat_id'] ?? '');
            $itemId = $mealActions[$mealHaatId]['kaman_id'] ?? null;
            foreach ($meal['groups'] ?? [] as $group) {
                foreach ($group['contents'] ?? [] as $content) {
                    if (! is_array($content) || ($content['view_type'] ?? '') === 'Unavailable') {
                        continue;
                    }
                    $contentId = (string) ($content['haat_id'] ?? '');
                    $ingredientId = $ingredientActions[$contentId]['kaman_id'] ?? null;
                    $price = (float) ($content['price'] ?? 0);
                    $wanted = [
                        'meal_haat_id' => $mealHaatId,
                        'content_haat_id' => $contentId,
                        'item_id' => is_string($itemId) ? $itemId : null,
                        'ingredient_id' => is_string($ingredientId) ? $ingredientId : null,
                        'default_ingredient' => ! empty($content['selected_by_default']),
                        'extra_fee' => $price > 0,
                        'custom_fee' => $price > 0 ? round($price, 2) : null,
                    ];
                    if (! is_string($itemId) || ! is_string($ingredientId)) {
                        $wanted['op'] = 'create';
                        $wanted['changes'] = ['create'];
                        $ops[] = $wanted;
                        continue;
                    }
                    $existing = $itemLinks[$itemId][$ingredientId] ?? null;
                    if (! is_array($existing)) {
                        $wanted['op'] = 'create';
                        $wanted['changes'] = ['create'];
                        $ops[] = $wanted;
                        continue;
                    }
                    $changes = [];
                    if ((bool) ($existing['default_ingredient'] ?? false) !== $wanted['default_ingredient']) {
                        $changes[] = 'default_ingredient';
                    }
                    if ((bool) ($existing['extra_fee'] ?? false) !== $wanted['extra_fee']) {
                        $changes[] = 'extra_fee';
                    }
                    $existingFee = $existing['custom_fee'] ?? null;
                    if ($wanted['extra_fee'] && $this->priceDiffers($existingFee, $wanted['custom_fee'])) {
                        $changes[] = 'custom_fee';
                    }
                    $wanted['op'] = $changes === [] ? 'skip' : 'update';
                    $wanted['changes'] = $changes;
                    $ops[] = $wanted;
                }
            }
        }

        return $ops;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $out[$id] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function nameIndex(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            foreach (['name_ar', 'name_en', 'name_he', 'name', 'title'] as $field) {
                $key = $this->nameKey((string) ($row[$field] ?? ''));
                if ($key !== '' && ! isset($index[$key])) {
                    $index[$key] = $id;
                }
            }
        }

        return $index;
    }

    /**
     * @param  array<string, string>  $index
     * @param  array<string, mixed>  $row
     * @param  array<string, bool>  $used
     */
    private function lookupName(array $index, array $row, array $used): ?string
    {
        foreach (['name_ar', 'name_en', 'name_he', 'name', 'title'] as $field) {
            $key = $this->nameKey((string) ($row[$field] ?? ''));
            if ($key === '' || ! isset($index[$key])) {
                continue;
            }
            $id = $index[$key];
            if (! isset($used[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function compactName(string $id, array $row, array $extra = []): array
    {
        $out = ['id' => $id];
        foreach (['name_ar', 'name_en', 'name_he', 'name', 'title'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        return $out + $extra;
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $actions
     * @return array{create: int, update: int, skip: int}
     */
    private function countOps(array $actions): array
    {
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0];
        foreach ($actions as $action) {
            $op = (string) ($action['op'] ?? 'create');
            if (! isset($counts[$op])) {
                $counts[$op] = 0;
            }
            $counts[$op]++;
        }

        return $counts;
    }

    /**
     * @param  array<string, array{create: int, update: int, skip: int}>  $summary
     */
    private function summaryMessage(array $summary, bool $aiUsed): string
    {
        $parts = [];
        foreach ($summary as $key => $counts) {
            $parts[] = sprintf(
                '%s: %d create, %d update, %d unchanged',
                str_replace('_', ' ', $key),
                $counts['create'],
                $counts['update'],
                $counts['skip'],
            );
        }

        return 'Plan ready'.($aiUsed ? ' (AI matching used)' : '').'. '.implode('; ', $parts);
    }

    private function priceDiffers(mixed $a, mixed $b): bool
    {
        if ($a === null || $a === '' || $b === null || $b === '') {
            return $a !== null && $a !== '' && $b !== null && $b !== '';
        }

        return abs((float) $a - (float) $b) > 0.009;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isAvailable(array $item): bool
    {
        $status = $item['status'] ?? $item['is_available'] ?? true;
        if (is_bool($status)) {
            return $status;
        }
        $value = strtolower(trim((string) $status));

        return ! in_array($value, ['0', 'false', 'inactive', 'unavailable', 'off'], true);
    }

    /**
     * @param  array<string, mixed>  $ingredient
     * @return list<string>
     */
    private function ingredientHaatIds(array $ingredient): array
    {
        $ids = [];
        foreach ($ingredient['haat_ids'] ?? [$ingredient['haat_id'] ?? ''] as $id) {
            $id = (string) $id;
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
