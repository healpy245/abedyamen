<?php

declare(strict_types=1);

namespace App\Services\Haat;

use App\Exceptions\FormWorkflowPausedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final class KamanMenuWriter
{
    private const PROBE_PATHS = [
        'post_item_ingredients' => '/items/{itemId}/ingredients',
        'post_item_item_ingredients' => '/items/{itemId}/item-ingredients',
        'post_item_ingredients_global' => '/item-ingredients',
        'post_item_ingredient_categories' => '/items/{itemId}/ingredient-categories',
        'post_item_ingredient_categories_global' => '/item-ingredient-categories',
    ];

    private ?KamanLinkContract $linkContract = null;

    private ?string $linkError = null;

    /** @var list<array{url: string, method: string, status: int}> */
    private array $probeLog = [];

    /** @var list<array{url: string, fields: list<string>, message: string}> */
    private array $rejectedDetails = [];

    /** @var array<string, mixed> */
    private array $plan = [];

    public function __construct(
        private readonly HaatImageDownloader $images,
        private readonly KamanCashierClient $cashier,
        private readonly KamanMenuSyncPlanner $planner,
    ) {
    }

    public function login(string $baseUrl, string $email, string $password): string
    {
        $url = rtrim($baseUrl, '/').'/login';
        $credentials = [
            'email' => $email,
            'password' => $password,
        ];

        $response = $this->send(fn () => $this->http()->asForm()->post($url, $credentials));
        if (! $response->successful()) {
            $response = $this->send(fn () => $this->http()->asJson()->post($url, $credentials));
        }

        if (! $response->successful()) {
            Log::warning('HAAT Kaman login failed', [
                'url' => $url,
                'email' => $email,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 400),
            ]);
            throw new \RuntimeException('Login failed: '.$this->responseMessage($response));
        }

        $data = $response->json();
        $token = is_array($data)
            ? ($data['token'] ?? $data['access_token'] ?? $data['data']['token'] ?? $data['data']['access_token'] ?? null)
            : null;

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Login response did not contain a token.');
        }

        return $token;
    }

    public function tokenIsUsable(string $baseUrl, string $token): bool
    {
        try {
            $response = $this->send(fn () => $this->http()->withToken($token)->get(rtrim($baseUrl, '/').'/categories'));

            return $response->successful();
        } catch (\Throwable $e) {
            if ($e instanceof FormWorkflowPausedException) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, mixed>
     */
    public function write(
        string $baseUrl,
        string $token,
        array $parsed,
        bool $withImages = true,
        ?callable $progress = null,
        ?callable $chat = null,
    ): array {
        $this->linkContract = null;
        $this->probeLog = [];
        $this->rejectedDetails = [];
        $this->plan = [];

        $report = [
            'categories' => ['created' => [], 'reused' => [], 'updated' => [], 'failed' => []],
            'meals' => ['created' => [], 'reused' => [], 'updated' => [], 'failed' => []],
            'ingredient_categories' => ['created' => [], 'reused' => [], 'updated' => [], 'failed' => []],
            'ingredients' => ['created' => [], 'reused' => [], 'updated' => [], 'failed' => []],
            'links' => [],
            'discovered_link' => null,
            'rejected_haat_details' => [],
            'probe_log' => [],
            'pipeline' => [],
            'plan' => [],
            'snapshot' => [],
        ];

        $snapshot = $this->snapshot($baseUrl, $token, $parsed, $progress);
        $this->plan = $this->planner->plan($parsed, $snapshot, $chat, $progress);
        $report['snapshot'] = [
            'cashier_available' => (bool) ($snapshot['cashier_available'] ?? false),
            'categories' => count($snapshot['categories'] ?? []),
            'items' => count($snapshot['items'] ?? []),
            'ingredient_categories' => count($snapshot['ingredient_categories'] ?? []),
            'ingredients' => count($snapshot['ingredients'] ?? []),
            'item_links' => count($snapshot['item_links'] ?? []),
        ];
        $report['plan'] = [
            'ai_used' => (bool) ($this->plan['ai_used'] ?? false),
            'summary' => $this->plan['summary'] ?? [],
        ];

        $categoryNames = [];
        foreach ($parsed['categories'] ?? [] as $category) {
            if (! is_array($category) || ($category['haat_id'] ?? '') === '') {
                continue;
            }
            $categoryNames[(string) $category['haat_id']] = (string) (($category['name_en'] ?? '') !== '' ? $category['name_en'] : ($category['name_ar'] ?? ''));
        }

        $categoryIds = $this->upsertCategories($baseUrl, $token, $parsed['categories'] ?? [], $report, $progress);
        $itemIds = $this->createMeals($baseUrl, $token, $parsed['meals'] ?? [], $categoryIds, $categoryNames, $withImages, $report, $progress);
        $ingCategoryIds = $this->upsertIngredientCategories($baseUrl, $token, $parsed['unique_ingredient_categories'] ?? [], $report, $progress);
        $ingredientIds = $this->upsertIngredients($baseUrl, $token, $parsed['unique_ingredients'] ?? [], $ingCategoryIds, $withImages, $report, $progress);

        $useCashierLinks = (bool) ($this->plan['cashier_available'] ?? false);
        if ($useCashierLinks) {
            $this->linkContract = new KamanLinkContract(
                endpoint: 'POST /api/cashier/items/{itemId}/ingredients',
                method: 'POST',
                path: '/api/cashier/items/{itemId}/ingredients',
                shape: 'cashier_link',
                fields: ['ingredient_id', 'default_ingredient', 'extra_fee', 'custom_fee'],
                asJson: true,
            );
            $progress && $progress('links', 'Applying meal extras via POST /api/cashier/items/{itemId}/ingredients (one ingredient per request)...', []);
            $this->linkMealsCashier($baseUrl, $token, $parsed['meals'] ?? [], $itemIds, $ingredientIds, $report, $progress);
        } else {
            $progress && $progress('links', 'POS cashier API is not on this restaurant. Trying manager link endpoints…', ['status' => 'run']);
            $firstLinkMeal = $this->firstMealWithGroups($parsed['meals'] ?? [], $itemIds);
            $firstIngredientId = $this->firstIngredientId($ingredientIds);

            if ($firstLinkMeal !== null && $firstIngredientId !== null) {
                $progress && $progress('discover_link', 'Discovering Kaman meal↔ingredient link API...', []);
                $this->discoverLink(
                    $baseUrl,
                    $token,
                    $firstLinkMeal,
                    $itemIds[$firstLinkMeal['haat_id']],
                    $ingredientIds,
                    $ingCategoryIds,
                    $progress,
                );
                if ($this->linkContract !== null) {
                    $progress && $progress('discover_link', 'Discovered '.$this->linkContract->endpoint, $this->linkContract->toArray() + ['status' => 'ok']);
                    $progress && $progress('links', 'Linking extras to meals via '.$this->linkContract->endpoint.'...', []);
                    $this->linkMeals($baseUrl, $token, $parsed['meals'] ?? [], $itemIds, $ingredientIds, $ingCategoryIds, $report, $progress);
                } else {
                    $error = $this->linkError ?? 'Could not attach extras to meals. This restaurant has no POS cashier link API, and updating /items does not save ingredients.';
                    $progress && $progress('links', $error, ['status' => 'fail']);
                    $this->failAllLinks($parsed['meals'] ?? [], $itemIds, $report, $error);
                }
            } elseif ($this->mealsNeedingLinks($parsed['meals'] ?? [], $itemIds) !== []) {
                $error = 'Cannot link extras: no meal and ingredient pair exists to discover the API.';
                $progress && $progress('links', $error, ['status' => 'fail']);
                $this->failAllLinks($parsed['meals'] ?? [], $itemIds, $report, $error);
            }
        }

        $report['discovered_link'] = $this->linkContract?->toArray();
        $report['rejected_haat_details'] = $this->rejectedDetails;
        $report['probe_log'] = $this->probeLog;
        $report['pipeline'] = $this->pipelineSummary($report);

        $categoryFailed = $report['categories']['failed'] !== [];
        $mealFailed = $report['meals']['failed'] !== [];
        $linkFailed = false;
        foreach ($report['links'] as $link) {
            if (($link['failed'] ?? 0) > 0 || ! empty($link['error'])) {
                $linkFailed = true;
                break;
            }
        }

        $report['success'] = ! $categoryFailed && ! $mealFailed && ! $linkFailed;

        if (! $report['success']) {
            Log::warning('HAAT menu copy finished with failures', [
                'pipeline' => $report['pipeline'],
                'meal_errors' => array_slice($report['meals']['failed'], 0, 25),
                'link_errors' => array_values(array_filter(
                    $report['links'],
                    fn (array $link) => ($link['failed'] ?? 0) > 0 || ! empty($link['error'])
                )),
            ]);
        }

        return $report;
    }

    private function http(int $timeout = 30): \Illuminate\Http\Client\PendingRequest
    {
        return KamanHttp::pending($timeout);
    }

    /**
     * @param  callable(): Response  $send
     */
    private function send(callable $send): Response
    {
        return KamanHttp::send($send);
    }

    /**
     * GET the live Kaman menu (manager categories/items + cashier ingredients when available).
     *
     * @param  array<string, mixed>  $parsed
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, mixed>
     */
    public function snapshot(string $baseUrl, string $token, array $parsed = [], ?callable $progress = null): array
    {
        $progress && $progress('snapshot', 'Reading existing Kaman menu...', []);
        $categories = $this->fetchList($baseUrl, $token, '/categories', ['categories']);
        $items = $this->fetchList($baseUrl, $token, '/items', ['items']);
        $cashierAvailable = $this->cashier->available($baseUrl, $token);

        $ingredientCategories = [];
        $ingredients = [];
        $itemLinks = [];
        if ($cashierAvailable) {
            $ingredientCategories = $this->cashier->listAll($baseUrl, $token, '/ingredient-categories');
            $ingredients = $this->cashier->listAll($baseUrl, $token, '/ingredients');
            $itemIds = $this->likelyItemIds($parsed['meals'] ?? [], $items);
            $total = count($itemIds);
            $i = 0;
            foreach ($itemIds as $itemId) {
                $i++;
                if ($total > 0 && ($i === 1 || $i === $total || $i % 10 === 0)) {
                    $progress && $progress('snapshot', 'Reading item ingredient links '.$i.'/'.$total, [
                        'index' => $i,
                        'total' => $total,
                    ]);
                }
                $itemLinks[$itemId] = $this->indexItemLinks($this->cashier->itemIngredients($baseUrl, $token, $itemId));
            }
        } else {
            $ingredientCategories = $this->fetchList($baseUrl, $token, '/ingredients-categories', ['ingredients_categories', 'categories']);
            $ingredients = $this->fetchList($baseUrl, $token, '/ingredients', ['ingredients']);
        }

        $progress && $progress('snapshot', sprintf(
            'Loaded %d categories, %d items, %d addon groups, %d ingredients%s',
            count($categories),
            count($items),
            count($ingredientCategories),
            count($ingredients),
            $cashierAvailable ? ' (cashier API)' : ' (manager API)',
        ), [
            'status' => 'ok',
            'cashier_available' => $cashierAvailable,
            'categories' => count($categories),
            'items' => count($items),
            'ingredient_categories' => count($ingredientCategories),
            'ingredients' => count($ingredients),
        ]);

        return [
            'cashier_available' => $cashierAvailable,
            'categories' => $categories,
            'items' => $items,
            'ingredient_categories' => $ingredientCategories,
            'ingredients' => $ingredients,
            'item_links' => $itemLinks,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function likelyItemIds(array $meals, array $items): array
    {
        $index = $this->indexItems($items);
        $ids = [];
        foreach ($meals as $meal) {
            foreach ([(string) ($meal['name_ar'] ?? ''), (string) ($meal['name_en'] ?? '')] as $name) {
                $key = $this->nameKey($name);
                if ($key === '' || ! isset($index[$key])) {
                    continue;
                }
                foreach ($index[$key] as $row) {
                    $ids[$row['id']] = $row['id'];
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexItemLinks(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['ingredient_id'] ?? $row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = [
                'ingredient_id' => $id,
                'default_ingredient' => (bool) ($row['default_ingredient'] ?? false),
                'extra_fee' => (bool) ($row['extra_fee'] ?? false),
                'custom_fee' => $row['custom_fee'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private function upsertCategories(string $baseUrl, string $token, array $categories, array &$report, ?callable $progress): array
    {
        $existing = $this->indexByName($this->fetchList($baseUrl, $token, '/categories', ['categories']));
        $map = [];
        $total = count($categories);
        $i = 0;

        foreach ($categories as $category) {
            $i++;
            $progress && $progress('categories', 'Category '.$i.'/'.$total.': '.$category['name_en'], ['name' => $category['name_en']]);
            $planned = $this->plan['categories'][$category['haat_id']] ?? null;
            $existingId = is_array($planned) ? ($planned['kaman_id'] ?? null) : $this->matchExisting($existing, $category['name_ar'], $category['name_en']);
            $op = is_array($planned) ? (string) ($planned['op'] ?? 'create') : ($existingId !== null ? 'skip' : 'create');
            if (is_string($existingId) && $existingId !== '' && $op !== 'create') {
                $map[$category['haat_id']] = $existingId;
                if ($op === 'update') {
                    $updated = $this->updateCategory($baseUrl, $token, $existingId, $category);
                    if ($updated) {
                        $report['categories']['updated'][] = ['haat_id' => $category['haat_id'], 'id' => $existingId, 'name' => $category['name_en']];
                        $progress && $progress('categories', 'Updated '.$category['name_en'], ['status' => 'ok', 'name' => $category['name_en']]);
                    } else {
                        $report['categories']['reused'][] = ['haat_id' => $category['haat_id'], 'id' => $existingId, 'name' => $category['name_en']];
                    }
                } else {
                    $report['categories']['reused'][] = ['haat_id' => $category['haat_id'], 'id' => $existingId, 'name' => $category['name_en']];
                }
                continue;
            }

            $response = $this->send(fn () => $this->http()->withToken($token)->post($baseUrl.'/categories', [
                'name_ar' => $category['name_ar'],
                'name_en' => $category['name_en'],
                'name_he' => $category['name_he'],
                'order_index' => (string) $category['order_index'],
            ]));

            if ($response->successful()) {
                $id = $this->extractId($response->json(), 'category');
                if ($id === null) {
                    $report['categories']['failed'][] = ['haat_id' => $category['haat_id'], 'error' => 'No id in create response'];
                    continue;
                }
                $map[$category['haat_id']] = $id;
                $existing[$this->nameKey($category['name_ar'])] = $id;
                $existing[$this->nameKey($category['name_en'])] = $id;
                $report['categories']['created'][] = ['haat_id' => $category['haat_id'], 'id' => $id, 'name' => $category['name_en']];
            } else {
                $error = $this->responseMessage($response);
                $report['categories']['failed'][] = [
                    'haat_id' => $category['haat_id'],
                    'name' => $category['name_en'],
                    'error' => $error,
                ];
                $progress && $progress('categories', 'FAILED '.$category['name_en'].': '.$error, [
                    'status' => 'fail',
                    'name' => $category['name_en'],
                    'error' => $error,
                ]);
            }
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $categoryIds
     * @param  array<string, string>  $categoryNames
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private function createMeals(
        string $baseUrl,
        string $token,
        array $meals,
        array $categoryIds,
        array $categoryNames,
        bool $withImages,
        array &$report,
        ?callable $progress,
    ): array {
        $existing = $this->indexItems($this->fetchList($baseUrl, $token, '/items', ['items']));
        $usedNames = [];
        $map = [];
        $total = count($meals);
        $i = 0;

        $this->tryBulkCreateMeals($baseUrl, $token, $meals, $categoryIds, $categoryNames, $existing, $usedNames, $map, $report, $progress);

        foreach ($meals as $meal) {
            $i++;
            $label = ($meal['name_en'] ?? '') !== '' ? (string) $meal['name_en'] : (string) ($meal['name_ar'] ?? 'meal');
            if (isset($map[$meal['haat_id']])) {
                continue;
            }
            $progress && $progress('meals', 'Meal '.$i.'/'.$total.': '.$label, [
                'name' => $label,
                'index' => $i,
                'total' => $total,
            ]);

            $categoryId = $categoryIds[$meal['category_haat_id']] ?? null;
            if ($categoryId === null) {
                $this->failMeal($report, $progress, $meal, $label, 'Category was not created');
                continue;
            }

            $planned = $this->plan['meals'][$meal['haat_id']] ?? null;
            $matched = is_array($planned) ? ($planned['kaman_id'] ?? null) : $this->matchExistingItem($existing, (string) $meal['name_ar'], (string) $meal['name_en'], $categoryId);
            $op = is_array($planned) ? (string) ($planned['op'] ?? 'create') : ($matched !== null ? 'skip' : 'create');
            if (is_string($matched) && $matched !== '' && $op !== 'create') {
                $map[$meal['haat_id']] = $matched;
                if ($op === 'update') {
                    $ok = $this->updateMeal($baseUrl, $token, $matched, $meal, $categoryId, $label);
                    if ($ok) {
                        $report['meals']['updated'][] = ['haat_id' => $meal['haat_id'], 'id' => $matched, 'name' => $label];
                        $progress && $progress('meals', 'Updated '.$label, ['status' => 'ok', 'name' => $label]);
                    } else {
                        $report['meals']['reused'][] = ['haat_id' => $meal['haat_id'], 'id' => $matched, 'name' => $label];
                        $progress && $progress('meals', 'Reused '.$label, ['status' => 'reuse', 'name' => $label]);
                    }
                } else {
                    $report['meals']['reused'][] = ['haat_id' => $meal['haat_id'], 'id' => $matched, 'name' => $label];
                    $progress && $progress('meals', 'Unchanged '.$label, ['status' => 'reuse', 'name' => $label]);
                }
                continue;
            }

            $nameAr = (string) $meal['name_ar'];
            $nameEn = (string) $meal['name_en'];
            $nameHe = (string) $meal['name_he'];
            if ($this->nameIsTaken($existing, $usedNames, $nameAr, $nameEn)) {
                $suffix = $categoryNames[$meal['category_haat_id']] ?? (string) $meal['haat_id'];
                $nameAr = trim($nameAr.' — '.$suffix);
                $nameEn = trim($nameEn.' ('.$suffix.')');
                $nameHe = trim($nameHe.' ('.$suffix.')');
            }

            $descriptionAr = trim((string) $meal['description_ar']);
            $descriptionEn = trim((string) $meal['description_en']);
            $descriptionHe = trim((string) $meal['description_he']);
            if ($descriptionAr === '') {
                $descriptionAr = $nameAr;
            }
            if ($descriptionEn === '') {
                $descriptionEn = $nameEn;
            }
            if ($descriptionHe === '') {
                $descriptionHe = $nameHe;
            }

            $body = [
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'name_he' => $nameHe,
                'description_ar' => $descriptionAr,
                'description_en' => $descriptionEn,
                'description_he' => $descriptionHe,
                'price' => $meal['price'],
                'category_id' => $categoryId,
            ];

            $imagePath = $withImages ? $this->images->download($meal['image_url'] ?? null) : null;
            $response = $this->postItemSafely($baseUrl, $token, $body, $imagePath);
            if ($imagePath && ($response === null || ! $response->successful())) {
                $response = $this->postItemSafely($baseUrl, $token, $body, null);
            }
            if ($imagePath && is_file($imagePath)) {
                @unlink($imagePath);
            }

            if ($response !== null && ! $response->successful() && $this->looksLikeDuplicateName($this->responseMessage($response))) {
                $suffix = $categoryNames[$meal['category_haat_id']] ?? (string) $meal['haat_id'];
                $body['name_ar'] = trim((string) $meal['name_ar'].' — '.$suffix);
                $body['name_en'] = trim((string) $meal['name_en'].' ('.$suffix.')');
                $body['name_he'] = trim((string) $meal['name_he'].' ('.$suffix.')');
                $response = $this->postItemSafely($baseUrl, $token, $body, null);
            }

            if ($response === null || ! $response->successful()) {
                $error = $response ? $this->responseMessage($response) : 'Item request failed';
                $this->failMeal($report, $progress, $meal, $label, $error, $response?->status());
                continue;
            }

            $id = $this->extractId($response->json(), 'item');
            if ($id === null) {
                $this->failMeal($report, $progress, $meal, $label, 'No id in create response: '.$this->responseSnippet($response));
                continue;
            }

            $map[$meal['haat_id']] = $id;
            $this->rememberItem($existing, $usedNames, $body['name_ar'], $body['name_en'], $id, $categoryId);
            $report['meals']['created'][] = ['haat_id' => $meal['haat_id'], 'id' => $id, 'name' => $label];
            $progress && $progress('meals', 'Created '.$label, ['status' => 'ok', 'name' => $label, 'id' => $id]);

            if (! $meal['is_available']) {
                $this->send(fn () => $this->http()->withToken($token)->asForm()->post($baseUrl.'/items/'.$id, [
                    '_method' => 'PUT',
                    'status' => '0',
                ]));
            }
        }

        $progress && $progress('meals', sprintf(
            'Meals done: %d created, %d updated, %d unchanged, %d failed',
            count($report['meals']['created']),
            count($report['meals']['updated'] ?? []),
            count($report['meals']['reused']),
            count($report['meals']['failed']),
        ), [
            'done' => true,
            'created' => count($report['meals']['created']),
            'updated' => count($report['meals']['updated'] ?? []),
            'reused' => count($report['meals']['reused']),
            'failed' => count($report['meals']['failed']),
        ]);

        return $map;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postItem(string $baseUrl, string $token, array $body, ?string $imagePath): Response
    {
        return $this->send(function () use ($baseUrl, $token, $body, $imagePath) {
            $http = $this->http($imagePath ? 90 : 30)->withToken($token);
            if ($imagePath && File::exists($imagePath)) {
                $http = $http->attach('image', File::get($imagePath), basename($imagePath));
            }

            return $http->post($baseUrl.'/items', $body);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $categoryIds
     * @param  array<string, string>  $categoryNames
     * @param  array<string, mixed>  $existing
     * @param  array<string, bool>  $usedNames
     * @param  array<string, string>  $map
     * @param  array<string, mixed>  $report
     */
    private function tryBulkCreateMeals(
        string $baseUrl,
        string $token,
        array $meals,
        array $categoryIds,
        array $categoryNames,
        array &$existing,
        array &$usedNames,
        array &$map,
        array &$report,
        ?callable $progress,
    ): void {
        $pending = [];
        foreach ($meals as $meal) {
            $haatId = (string) ($meal['haat_id'] ?? '');
            if ($haatId === '' || isset($map[$haatId])) {
                continue;
            }
            $categoryId = $categoryIds[$meal['category_haat_id'] ?? ''] ?? null;
            if ($categoryId === null) {
                continue;
            }
            $planned = $this->plan['meals'][$haatId] ?? null;
            $matched = is_array($planned) ? ($planned['kaman_id'] ?? null) : $this->matchExistingItem($existing, (string) $meal['name_ar'], (string) $meal['name_en'], $categoryId);
            $op = is_array($planned) ? (string) ($planned['op'] ?? 'create') : ($matched !== null ? 'skip' : 'create');
            if (is_string($matched) && $matched !== '' && $op !== 'create') {
                continue;
            }
            $pending[] = $meal;
        }

        if (count($pending) < 2) {
            return;
        }

        $progress && $progress('meals', 'Checking whether Kaman supports bulk meal create...', ['status' => 'run']);

        foreach (array_chunk($pending, 15) as $chunkIndex => $chunk) {
            $bodies = [];
            foreach ($chunk as $meal) {
                $categoryId = $categoryIds[$meal['category_haat_id']];
                $nameAr = (string) $meal['name_ar'];
                $nameEn = (string) $meal['name_en'];
                $nameHe = (string) $meal['name_he'];
                if ($this->nameIsTaken($existing, $usedNames, $nameAr, $nameEn)) {
                    $suffix = $categoryNames[$meal['category_haat_id']] ?? (string) $meal['haat_id'];
                    $nameAr = trim($nameAr.' — '.$suffix);
                    $nameEn = trim($nameEn.' ('.$suffix.')');
                    $nameHe = trim($nameHe.' ('.$suffix.')');
                }
                $descriptionAr = trim((string) $meal['description_ar']) !== '' ? (string) $meal['description_ar'] : $nameAr;
                $descriptionEn = trim((string) $meal['description_en']) !== '' ? (string) $meal['description_en'] : $nameEn;
                $descriptionHe = trim((string) $meal['description_he']) !== '' ? (string) $meal['description_he'] : $nameHe;
                $bodies[] = [
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'name_he' => $nameHe,
                    'description_ar' => $descriptionAr,
                    'description_en' => $descriptionEn,
                    'description_he' => $descriptionHe,
                    'price' => $meal['price'],
                    'category_id' => $categoryId,
                    'status' => ($meal['is_available'] ?? true) ? '1' : '0',
                ];
            }

            $response = $this->send(fn () => $this->http()->withToken($token)->asJson()->post($baseUrl.'/items/bulk', [
                'items' => $bodies,
            ]));

            if (in_array($response->status(), [404, 405], true) || ($chunkIndex === 0 && ! $response->successful())) {
                $progress && $progress('meals', 'Bulk meal API is not available. Creating meals one-by-one with rate-limit pacing.', ['status' => 'run']);

                return;
            }

            if (! $response->successful()) {
                $progress && $progress('meals', 'Bulk meal create stopped after HTTP '.$response->status().'. Remaining meals use paced single creates.', [
                    'status' => 'run',
                ]);

                return;
            }

            $ids = $this->extractBulkIds($response->json());
            if (count($ids) !== count($chunk)) {
                $progress && $progress('meals', 'Bulk meal create returned incomplete ids. Remaining meals use paced single creates.', ['status' => 'run']);

                return;
            }

            foreach ($chunk as $offset => $meal) {
                $id = $ids[$offset];
                $body = $bodies[$offset];
                $label = ($meal['name_en'] ?? '') !== '' ? (string) $meal['name_en'] : (string) ($meal['name_ar'] ?? 'meal');
                $map[$meal['haat_id']] = $id;
                $this->rememberItem($existing, $usedNames, $body['name_ar'], $body['name_en'], $id, (string) $body['category_id']);
                $report['meals']['created'][] = ['haat_id' => $meal['haat_id'], 'id' => $id, 'name' => $label, 'bulk' => true];
                $progress && $progress('meals', 'Created '.$label, ['status' => 'ok', 'name' => $label, 'id' => $id]);
            }
        }

        $progress && $progress('meals', 'Bulk meal create finished for '.count($map).' meals.', ['status' => 'ok']);
    }

    /**
     * @param  mixed  $json
     * @return list<string>
     */
    private function extractBulkIds(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        $rows = $json['data']['items'] ?? $json['data'] ?? $json['items'] ?? $json;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return [];
            }
            $id = $row['id'] ?? $row['item_id'] ?? null;
            if ($id === null || $id === '') {
                return [];
            }
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postItemSafely(string $baseUrl, string $token, array $body, ?string $imagePath): ?Response
    {
        try {
            return $this->postItem($baseUrl, $token, $body, $imagePath);
        } catch (\Throwable $e) {
            if ($e instanceof FormWorkflowPausedException) {
                throw $e;
            }
            Log::warning('HAAT meal request threw', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $meal
     */
    private function failMeal(array &$report, ?callable $progress, array $meal, string $label, string $error, ?int $status = null): void
    {
        $row = [
            'haat_id' => $meal['haat_id'] ?? null,
            'name' => $label,
            'error' => $error,
        ];
        if ($status !== null) {
            $row['status'] = $status;
        }
        $report['meals']['failed'][] = $row;
        Log::warning('HAAT meal copy failed', $row);
        $progress && $progress('meals', 'FAILED '.$label.': '.$error, [
            'status' => 'fail',
            'name' => $label,
            'error' => $error,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private function upsertIngredientCategories(string $baseUrl, string $token, array $categories, array &$report, ?callable $progress): array
    {
        $existing = $this->indexByName($this->fetchList($baseUrl, $token, '/ingredients-categories', ['ingredients_categories', 'categories']));
        $map = [];
        $total = count($categories);
        $i = 0;

        foreach ($categories as $category) {
            $i++;
            $progress && $progress('ingredient_categories', 'Ingredient category '.$i.'/'.$total.': '.$category['name_en'], []);
            $planned = $this->plan['ingredient_categories'][$category['title']] ?? null;
            $existingId = is_array($planned) ? ($planned['kaman_id'] ?? null) : $this->matchExisting($existing, $category['name_ar'], $category['name_en']);
            if ($existingId === null) {
                $existingId = $this->matchExisting($existing, $category['name_ar'], $category['name_en']);
            }
            $op = is_array($planned) ? (string) ($planned['op'] ?? 'create') : ($existingId !== null ? 'skip' : 'create');
            if (is_string($existingId) && $existingId !== '' && $op === 'create') {
                $op = 'skip';
            }
            if (is_string($existingId) && $existingId !== '' && $op !== 'create') {
                $map[$category['title']] = $existingId;
                if ($op === 'update' && ($this->plan['cashier_available'] ?? false)) {
                    $ok = $this->updateCashierIngredientCategory($baseUrl, $token, $existingId, $category);
                    if ($ok) {
                        $report['ingredient_categories']['updated'][] = ['title' => $category['title'], 'id' => $existingId];
                        $progress && $progress('ingredient_categories', 'Updated '.$category['name_en'], ['status' => 'ok']);
                    } else {
                        $report['ingredient_categories']['reused'][] = ['title' => $category['title'], 'id' => $existingId];
                    }
                } else {
                    $report['ingredient_categories']['reused'][] = ['title' => $category['title'], 'id' => $existingId];
                }
                continue;
            }

            $createdId = ($this->plan['cashier_available'] ?? false)
                ? $this->createCashierIngredientCategory($baseUrl, $token, $category)
                : $this->createManagerIngredientCategory($baseUrl, $token, $category);

            if ($createdId === null) {
                $report['ingredient_categories']['failed'][] = ['title' => $category['title'], 'error' => 'No id in create response'];
                continue;
            }
            $map[$category['title']] = $createdId;
            $existing[$this->nameKey($category['name_ar'])] = $createdId;
            $existing[$this->nameKey($category['name_en'])] = $createdId;
            $report['ingredient_categories']['created'][] = ['title' => $category['title'], 'id' => $createdId];
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     * @param  array<string, string>  $ingCategoryIds
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private function upsertIngredients(
        string $baseUrl,
        string $token,
        array $ingredients,
        array $ingCategoryIds,
        bool $withImages,
        array &$report,
        ?callable $progress,
    ): array {
        $snapshotRows = $this->fetchList($baseUrl, $token, '/ingredients', ['ingredients']);
        $existing = $this->indexIngredients($snapshotRows);
        $map = [];
        $total = count($ingredients);
        $i = 0;

        foreach ($ingredients as $ingredient) {
            $i++;
            $progress && $progress('ingredients', 'Ingredient '.$i.'/'.$total.': '.$ingredient['name'], []);
            $categoryId = $ingCategoryIds[$ingredient['category_title']] ?? null;
            $planned = $this->plan['ingredients'][$ingredient['haat_id']] ?? null;
            $existingId = is_array($planned) ? ($planned['kaman_id'] ?? null) : null;
            if ($existingId === null && $categoryId !== null) {
                $existingId = $this->matchExistingIngredient($existing, $ingredient, $categoryId);
            }
            $op = is_array($planned) ? (string) ($planned['op'] ?? 'create') : ($existingId !== null ? 'skip' : 'create');
            if (is_string($existingId) && $existingId !== '' && $op === 'create') {
                $op = 'skip';
            }
            if (is_string($existingId) && $existingId !== '' && $op !== 'create') {
                $this->rememberIngredientIds($map, $ingredient, $existingId);
                if ($op === 'update' && ($this->plan['cashier_available'] ?? false)) {
                    $ok = $this->updateCashierIngredient($baseUrl, $token, $existingId, $ingredient, $categoryId);
                    if ($ok) {
                        $report['ingredients']['updated'][] = ['haat_id' => $ingredient['haat_id'], 'id' => $existingId, 'name' => $ingredient['name']];
                        $progress && $progress('ingredients', 'Updated '.$ingredient['name'], ['status' => 'ok']);
                    } else {
                        $report['ingredients']['reused'][] = ['haat_id' => $ingredient['haat_id'], 'id' => $existingId, 'name' => $ingredient['name']];
                    }
                } else {
                    $report['ingredients']['reused'][] = ['haat_id' => $ingredient['haat_id'], 'id' => $existingId, 'name' => $ingredient['name']];
                    $progress && $progress('ingredients', 'Reused '.$ingredient['name'], ['status' => 'reuse']);
                }
                continue;
            }

            if ($categoryId === null) {
                $report['ingredients']['failed'][] = ['haat_id' => $ingredient['haat_id'], 'error' => 'Ingredient category missing'];
                continue;
            }

            $id = ($this->plan['cashier_available'] ?? false)
                ? $this->createCashierIngredient($baseUrl, $token, $ingredient, $categoryId, $withImages)
                : $this->createManagerIngredient($baseUrl, $token, $ingredient, $categoryId, $withImages);

            if ($id === null) {
                $report['ingredients']['failed'][] = [
                    'haat_id' => $ingredient['haat_id'],
                    'name' => $ingredient['name'],
                    'error' => 'Ingredient create failed',
                ];
                continue;
            }
            $this->rememberIngredientIds($map, $ingredient, $id);
            $this->rememberIndexedIngredient($existing, $ingredient, $categoryId, $id);
            $report['ingredients']['created'][] = ['haat_id' => $ingredient['haat_id'], 'id' => $id, 'name' => $ingredient['name']];
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function updateCategory(string $baseUrl, string $token, string $id, array $category): bool
    {
        $body = [
            'name_ar' => $category['name_ar'],
            'name_en' => $category['name_en'],
            'name_he' => $category['name_he'],
            'order_index' => (string) ($category['order_index'] ?? 0),
        ];
        $response = $this->send(fn () => $this->http()->withToken($token)->asJson()->put($baseUrl.'/categories/'.$id, $body));
        if ($response->successful()) {
            return true;
        }
        $response = $this->send(fn () => $this->http()->withToken($token)->asForm()->post($baseUrl.'/categories/'.$id, $body + ['_method' => 'PUT']));

        return $response->successful();
    }

    /**
     * @param  array<string, mixed>  $meal
     */
    private function updateMeal(string $baseUrl, string $token, string $id, array $meal, string $categoryId, string $label): bool
    {
        $nameAr = (string) $meal['name_ar'];
        $nameEn = (string) $meal['name_en'];
        $nameHe = (string) $meal['name_he'];
        $descriptionAr = trim((string) $meal['description_ar']) !== '' ? (string) $meal['description_ar'] : $nameAr;
        $descriptionEn = trim((string) $meal['description_en']) !== '' ? (string) $meal['description_en'] : $nameEn;
        $descriptionHe = trim((string) $meal['description_he']) !== '' ? (string) $meal['description_he'] : $nameHe;
        $body = [
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'name_he' => $nameHe,
            'description_ar' => $descriptionAr,
            'description_en' => $descriptionEn,
            'description_he' => $descriptionHe,
            'price' => $meal['price'],
            'category_id' => $categoryId,
        ];
        if (! ($meal['is_available'] ?? true)) {
            $body['status'] = '0';
        }
        $response = $this->send(fn () => $this->http()->withToken($token)->asJson()->put($baseUrl.'/items/'.$id, $body));
        if (! $response->successful()) {
            $response = $this->send(fn () => $this->http()->withToken($token)->asForm()->post($baseUrl.'/items/'.$id, $body + ['_method' => 'PUT']));
        }
        if (! $response->successful()) {
            Log::warning('HAAT meal update failed', [
                'name' => $label,
                'id' => $id,
                'error' => $this->responseMessage($response),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function cashierCategoryBody(array $category): array
    {
        return [
            'name_en' => (string) ($category['name_en'] ?: $category['name_ar']),
            'name_ar' => (string) $category['name_ar'],
            'name_he' => (string) ($category['name_he'] ?: $category['name_en'] ?: $category['name_ar']),
            'type' => (string) ($category['type'] ?? 'multi_option'),
            'max_options' => (int) ($category['max_options'] ?? 99),
            'min_options' => (int) ($category['min_options'] ?? 0),
            'order_index' => (int) ($category['order_index'] ?? 0),
            'must_pick' => (bool) ($category['must_pick'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function createCashierIngredientCategory(string $baseUrl, string $token, array $category): ?string
    {
        $response = $this->cashier->postJson($baseUrl, $token, '/ingredient-categories', $this->cashierCategoryBody($category));
        if (! $response->successful()) {
            Log::warning('HAAT cashier ingredient category create failed', ['error' => $this->responseMessage($response)]);

            return null;
        }

        return $this->cashier->extractId($response->json());
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function updateCashierIngredientCategory(string $baseUrl, string $token, string $id, array $category): bool
    {
        $response = $this->cashier->putJson($baseUrl, $token, '/ingredient-categories/'.$id, $this->cashierCategoryBody($category));

        return $response->successful();
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function createManagerIngredientCategory(string $baseUrl, string $token, array $category): ?string
    {
        $response = $this->send(fn () => $this->http()->withToken($token)->post($baseUrl.'/ingredients-categories', [
            'name_ar' => $category['name_ar'],
            'name_en' => $category['name_en'],
            'name_he' => $category['name_he'],
        ]));
        if (! $response->successful()) {
            Log::warning('HAAT ingredient category create failed', ['error' => $this->responseMessage($response)]);

            return null;
        }

        return $this->extractId($response->json(), 'category') ?? $this->extractId($response->json(), 'ingredient_category');
    }

    /**
     * @param  array<string, mixed>  $ingredient
     */
    private function cashierIngredientBody(array $ingredient, string $categoryId): array
    {
        return [
            'name_ar' => (string) $ingredient['name_ar'],
            'name_en' => (string) $ingredient['name_en'],
            'name_he' => (string) $ingredient['name_he'],
            'price' => (float) ($ingredient['price_number'] ?? $ingredient['price'] ?? 0),
            'category_id' => $categoryId,
            'allow_multiple' => (bool) ($ingredient['allow_multiple'] ?? false),
            'order_index' => (int) ($ingredient['order_index'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $ingredient
     */
    private function createCashierIngredient(string $baseUrl, string $token, array $ingredient, string $categoryId, bool $withImages): ?string
    {
        $body = $this->cashierIngredientBody($ingredient, $categoryId);
        $imagePath = $withImages ? $this->images->download($ingredient['image_url'] ?? null) : null;
        try {
            $response = $this->cashier->postIngredient($baseUrl, $token, $body, $imagePath);
            if ($imagePath && ! $response->successful()) {
                $response = $this->cashier->postJson($baseUrl, $token, '/ingredients', $body);
            }
        } catch (\Throwable $e) {
            if ($e instanceof FormWorkflowPausedException) {
                throw $e;
            }
            try {
                $response = $this->cashier->postJson($baseUrl, $token, '/ingredients', $body);
            } catch (\Throwable $retryError) {
                if ($retryError instanceof FormWorkflowPausedException) {
                    throw $retryError;
                }
                if ($imagePath && is_file($imagePath)) {
                    @unlink($imagePath);
                }

                return null;
            }
        }
        if ($imagePath && is_file($imagePath)) {
            @unlink($imagePath);
        }
        if (! $response->successful()) {
            Log::warning('HAAT cashier ingredient create failed', ['error' => $this->responseMessage($response)]);

            return null;
        }

        return $this->cashier->extractId($response->json());
    }

    /**
     * @param  array<string, mixed>  $ingredient
     */
    private function updateCashierIngredient(string $baseUrl, string $token, string $id, array $ingredient, ?string $categoryId): bool
    {
        if ($categoryId === null) {
            return false;
        }
        $response = $this->cashier->putJson($baseUrl, $token, '/ingredients/'.$id, $this->cashierIngredientBody($ingredient, $categoryId));

        return $response->successful();
    }

    /**
     * @param  array<string, mixed>  $ingredient
     */
    private function createManagerIngredient(string $baseUrl, string $token, array $ingredient, string $categoryId, bool $withImages): ?string
    {
        $body = [
            'name_ar' => $ingredient['name_ar'],
            'name_en' => $ingredient['name_en'],
            'name_he' => $ingredient['name_he'],
            'price' => $ingredient['price'],
            'category_id' => $categoryId,
        ];
        $imagePath = $withImages ? $this->images->download($ingredient['image_url'] ?? null) : null;
        try {
            $response = $this->send(function () use ($baseUrl, $token, $body, $imagePath) {
                $http = $this->http($imagePath ? 90 : 30)->withToken($token);
                if ($imagePath && File::exists($imagePath)) {
                    $http = $http->attach('image', File::get($imagePath), basename($imagePath));
                }

                return $http->post($baseUrl.'/ingredients', $body);
            });
            if ($imagePath && ! $response->successful()) {
                $response = $this->send(fn () => $this->http()->withToken($token)->post($baseUrl.'/ingredients', $body));
            }
        } catch (\Throwable $e) {
            if ($e instanceof FormWorkflowPausedException) {
                throw $e;
            }
            try {
                $response = $this->send(fn () => $this->http()->withToken($token)->post($baseUrl.'/ingredients', $body));
            } catch (\Throwable $retryError) {
                if ($retryError instanceof FormWorkflowPausedException) {
                    throw $retryError;
                }
                if ($imagePath && is_file($imagePath)) {
                    @unlink($imagePath);
                }
                Log::warning('HAAT ingredient create failed', ['error' => $retryError->getMessage()]);

                return null;
            }
        }
        if ($imagePath && is_file($imagePath)) {
            @unlink($imagePath);
        }
        if (! $response->successful()) {
            Log::warning('HAAT ingredient create failed', ['error' => $this->responseMessage($response)]);

            return null;
        }

        return $this->extractId($response->json(), 'ingredient');
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $itemIds
     * @param  array<string, string>  $ingredientIds
     * @param  array<string, mixed>  $report
     */
    private function linkMealsCashier(
        string $baseUrl,
        string $token,
        array $meals,
        array $itemIds,
        array $ingredientIds,
        array &$report,
        ?callable $progress,
    ): void {
        foreach ($meals as $meal) {
            if (($meal['groups'] ?? []) === []) {
                continue;
            }
            $itemId = $itemIds[$meal['haat_id']] ?? null;
            if ($itemId === null) {
                $report['links'][] = [
                    'haat_id' => $meal['haat_id'],
                    'name' => $meal['name_en'],
                    'groups' => count($meal['groups']),
                    'contents' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'failed' => 1,
                    'error' => 'Meal was not created; cannot link',
                ];
                continue;
            }

            $existingLinks = $this->indexItemLinks($this->cashier->itemIngredients($baseUrl, $token, $itemId));
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $error = null;
            $ops = $this->cashierLinkOpsFromMeal($meal, $itemId, $ingredientIds, $existingLinks);
            $progress && $progress('links', 'Linking '.$meal['name_en'].' ('.count($ops).' contents)', [
                'item_id' => $itemId,
                'name' => $meal['name_en'],
            ]);

            try {
                [$created, $updated, $skipped, $failed, $error] = $this->applyCashierLinkOps(
                    $baseUrl,
                    $token,
                    $itemId,
                    $ops,
                    $ingredientIds,
                    $existingLinks,
                    $meal['name_en'] ?? 'meal',
                    $progress,
                );
            } catch (FormWorkflowPausedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failed = count($ops);
                $created = 0;
                $updated = 0;
                $skipped = 0;
                $error = $e->getMessage();
                $progress && $progress('links', 'FAILED '.$meal['name_en'].': '.$error, [
                    'status' => 'fail',
                    'name' => $meal['name_en'],
                    'error' => $error,
                ]);
            }

            $report['links'][] = [
                'haat_id' => $meal['haat_id'],
                'name' => $meal['name_en'],
                'groups' => count($meal['groups']),
                'contents' => count($ops),
                'created' => $created,
                'updated' => $updated,
                'reused' => $skipped,
                'failed' => $failed,
                'error' => $error,
                'via' => 'cashier',
            ];
            if ($failed === 0) {
                $progress && $progress('links', 'Linked '.$meal['name_en'], ['status' => 'ok', 'name' => $meal['name_en']]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $ops
     * @param  array<string, string>  $ingredientIds
     * @param  array<string, array<string, mixed>>  $existingLinks
     * @return array{0: int, 1: int, 2: int, 3: int, string|null}
     */
    private function applyCashierLinkOps(
        string $baseUrl,
        string $token,
        string $itemId,
        array $ops,
        array $ingredientIds,
        array &$existingLinks,
        string $label,
        ?callable $progress,
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $error = null;
        $total = count($ops);

        foreach ($ops as $index => $op) {
            $ingredientId = $op['ingredient_id'] ?? ($ingredientIds[(string) ($op['content_haat_id'] ?? '')] ?? null);
            if (! is_string($ingredientId) || $ingredientId === '') {
                $failed++;
                $error = 'Ingredient missing for link';
                continue;
            }
            $payload = [
                'ingredient_id' => $ingredientId,
                'default_ingredient' => (bool) ($op['default_ingredient'] ?? false),
                'extra_fee' => (bool) ($op['extra_fee'] ?? false),
            ];
            if ($payload['extra_fee']) {
                $payload['custom_fee'] = (float) ($op['custom_fee'] ?? 0);
            }
            $action = (string) ($op['op'] ?? 'create');
            if ($action === 'skip' && isset($existingLinks[$ingredientId])) {
                $skipped++;
                continue;
            }
            if (isset($existingLinks[$ingredientId]) && $action !== 'create') {
                $path = '/api/cashier/items/'.$itemId.'/ingredients/'.$ingredientId;
                $response = $this->cashier->putJson(
                    $baseUrl,
                    $token,
                    '/items/'.$itemId.'/ingredients/'.$ingredientId,
                    [
                        'default_ingredient' => $payload['default_ingredient'],
                        'extra_fee' => $payload['extra_fee'],
                        'custom_fee' => $payload['extra_fee'] ? $payload['custom_fee'] : null,
                    ],
                );
                $this->noteHttp($progress, 'PUT', $path, $response, $label.' '.($index + 1).'/'.$total);
                if ($response->successful()) {
                    $updated++;
                    $existingLinks[$ingredientId] = $payload;
                } else {
                    $failed++;
                    $error = $this->responseMessage($response);
                }
                continue;
            }

            $path = '/api/cashier/items/'.$itemId.'/ingredients';
            $response = $this->cashier->postJson($baseUrl, $token, '/items/'.$itemId.'/ingredients', $payload);
            $this->noteHttp($progress, 'POST', $path, $response, $label.' '.($index + 1).'/'.$total);
            if ($response->successful()) {
                $created++;
                $existingLinks[$ingredientId] = $payload;
                continue;
            }
            if ($response->status() === 422) {
                $retry = $this->cashier->putJson(
                    $baseUrl,
                    $token,
                    '/items/'.$itemId.'/ingredients/'.$ingredientId,
                    [
                        'default_ingredient' => $payload['default_ingredient'],
                        'extra_fee' => $payload['extra_fee'],
                        'custom_fee' => $payload['extra_fee'] ? ($payload['custom_fee'] ?? null) : null,
                    ],
                );
                $this->noteHttp($progress, 'PUT', $path.'/'.$ingredientId, $retry, $label.' '.($index + 1).'/'.$total);
                if ($retry->successful()) {
                    $updated++;
                    $existingLinks[$ingredientId] = $payload;
                    continue;
                }
            }
            $failed++;
            $error = $this->responseMessage($response);
        }

        return [$created, $updated, $skipped, $failed, $error];
    }

    /**
     * @param  array<string, mixed>  $meal
     * @param  array<string, string>  $ingredientIds
     * @param  array<string, array<string, mixed>>  $existingLinks
     * @return list<array<string, mixed>>
     */
    private function cashierLinkOpsFromMeal(array $meal, string $itemId, array $ingredientIds, array $existingLinks): array
    {
        $ops = [];
        foreach ($meal['groups'] ?? [] as $group) {
            foreach ($group['contents'] ?? [] as $content) {
                if (! is_array($content) || ($content['view_type'] ?? '') === 'Unavailable') {
                    continue;
                }
                $ingredientId = $ingredientIds[(string) ($content['haat_id'] ?? '')] ?? null;
                if (! is_string($ingredientId)) {
                    continue;
                }
                $price = (float) ($content['price'] ?? 0);
                $wantedDefault = ! empty($content['selected_by_default']);
                $wantedExtra = $price > 0;
                $wantedFee = $wantedExtra ? round($price, 2) : null;
                $existing = $existingLinks[$ingredientId] ?? null;
                $op = 'create';
                if (is_array($existing)) {
                    $sameDefault = (bool) ($existing['default_ingredient'] ?? false) === $wantedDefault;
                    $sameExtra = (bool) ($existing['extra_fee'] ?? false) === $wantedExtra;
                    $sameFee = ! $wantedExtra || abs((float) ($existing['custom_fee'] ?? 0) - (float) $wantedFee) < 0.009;
                    $op = ($sameDefault && $sameExtra && $sameFee) ? 'skip' : 'update';
                }
                $ops[] = [
                    'item_id' => $itemId,
                    'ingredient_id' => $ingredientId,
                    'content_haat_id' => (string) ($content['haat_id'] ?? ''),
                    'default_ingredient' => $wantedDefault,
                    'extra_fee' => $wantedExtra,
                    'custom_fee' => $wantedFee,
                    'op' => $op,
                ];
            }
        }

        return $ops;
    }

    /**
     * @param  array<string, mixed>  $meal
     * @param  array<string, string>  $ingredientIds
     * @param  array<string, string>  $ingCategoryIds
     */
    private function discoverLink(
        string $baseUrl,
        string $token,
        array $meal,
        string $itemId,
        array $ingredientIds,
        array $ingCategoryIds,
        ?callable $progress = null,
    ): void {
        $rows = $this->linkRowsForMeal($meal, $ingredientIds, $ingCategoryIds);
        if ($rows === []) {
            $this->linkError = 'First meal has addon groups but no linkable contents.';

            return;
        }

        $show = $this->send(fn () => $this->http()->withToken($token)->get($baseUrl.'/items/'.$itemId));
        $this->probeLog[] = ['url' => $baseUrl.'/items/'.$itemId, 'method' => 'GET', 'status' => $show->status()];
        $this->noteHttp($progress, 'GET', '/api/manager/items/'.$itemId, $show, 'discover');
        if ($show->successful()) {
            $payload = $show->json();
            $data = is_array($payload) ? ($payload['data'] ?? $payload) : [];
            if (is_array($data) && $this->showHasLinkShape($data)) {
                $contract = new KamanLinkContract(
                    endpoint: 'POST /items/{itemId} _method=PUT (mirrored show)',
                    method: 'POST',
                    path: '/items/{itemId}',
                    shape: $this->inferShowShape($data),
                    fields: $this->fieldsFromRows($rows),
                    methodSpoof: true,
                );
                $response = $this->sendLink($baseUrl, $token, $itemId, $rows, $contract);
                $this->noteHttp($progress, 'POST', '/api/manager/items/'.$itemId, $response, 'discover');
                if ($response->successful() && $this->acceptDiscoveredContract($baseUrl, $token, $itemId, $rows, $contract, $progress)) {
                    return;
                }
                $this->recordRejection($baseUrl.'/items/'.$itemId, $response);
                if ($response->status() === 422) {
                    $retried = $this->retryFromValidation($baseUrl, $token, $itemId, $rows, $contract, $response);
                    if ($retried !== null && $this->acceptDiscoveredContract($baseUrl, $token, $itemId, $rows, $retried, $progress)) {
                        return;
                    }
                }
            }
        }

        foreach (self::PROBE_PATHS as $path) {
            $url = $baseUrl.$this->fillItemPath($path, $itemId);
            $contract = new KamanLinkContract(
                endpoint: 'POST '.$path,
                method: 'POST',
                path: $path,
                shape: str_contains($path, 'ingredient-categories') ? 'nested_groups' : 'nested_ingredients',
                fields: $this->fieldsFromRows($rows),
                asJson: true,
            );
            $response = $this->sendLink($baseUrl, $token, $itemId, $rows, $contract);
            $this->probeLog[] = ['url' => $url, 'method' => 'POST', 'status' => $response->status()];
            $this->noteHttp($progress, 'POST', '/api/manager'.$this->fillItemPath($path, $itemId), $response, 'discover');
            if ($response->successful() && $this->acceptDiscoveredContract($baseUrl, $token, $itemId, $rows, $contract, $progress)) {
                return;
            }
            $this->recordRejection($url, $response);
            if ($response->status() === 422) {
                $retried = $this->retryFromValidation($baseUrl, $token, $itemId, $rows, $contract, $response);
                if ($retried !== null && $this->acceptDiscoveredContract($baseUrl, $token, $itemId, $rows, $retried, $progress)) {
                    return;
                }
            }
        }

        $jsonContract = new KamanLinkContract(
            endpoint: 'PUT /items/{itemId} JSON ingredients',
            method: 'PUT',
            path: '/items/{itemId}',
            shape: 'nested_ingredients',
            fields: $this->fieldsFromRows($rows),
            asJson: true,
        );
        $putUrl = $baseUrl.'/items/'.$itemId;
        $putResponse = $this->sendLink($baseUrl, $token, $itemId, $rows, $jsonContract);
        $this->probeLog[] = ['url' => $putUrl, 'method' => 'PUT', 'status' => $putResponse->status()];
        $this->noteHttp($progress, 'PUT', '/api/manager/items/'.$itemId, $putResponse, 'discover (item update ignores extras unless they appear on GET)');
        if ($putResponse->successful() && $this->acceptDiscoveredContract($baseUrl, $token, $itemId, $rows, $jsonContract, $progress)) {
            return;
        }
        $this->recordRejection($putUrl, $putResponse);
        if ($putResponse->status() === 422) {
            $retried = $this->retryFromValidation($baseUrl, $token, $itemId, $rows, $jsonContract, $putResponse);
            if ($retried !== null && $this->acceptDiscoveredContract($baseUrl, $token, $itemId, $rows, $retried, $progress)) {
                return;
            }
        }

        $formContract = new KamanLinkContract(
            endpoint: 'POST /items/{itemId} _method=PUT form ingredients[]',
            method: 'POST',
            path: '/items/{itemId}',
            shape: 'nested_ingredients',
            fields: $this->fieldsFromRows($rows),
            methodSpoof: true,
        );
        $formResponse = $this->sendLink($baseUrl, $token, $itemId, $rows, $formContract);
        $this->probeLog[] = ['url' => $putUrl, 'method' => 'POST', 'status' => $formResponse->status()];
        $this->noteHttp($progress, 'POST', '/api/manager/items/'.$itemId, $formResponse, 'discover _method=PUT');
        if ($formResponse->successful() && $this->acceptDiscoveredContract($baseUrl, $token, $itemId, $rows, $formContract, $progress)) {
            return;
        }
        $this->recordRejection($putUrl, $formResponse);
        if ($formResponse->status() === 422) {
            $retried = $this->retryFromValidation($baseUrl, $token, $itemId, $rows, $formContract, $formResponse);
            if ($retried !== null && $this->acceptDiscoveredContract($baseUrl, $token, $itemId, $rows, $retried, $progress)) {
                return;
            }
        }

        $tried = array_map(fn (array $p) => $p['method'].' '.$p['url'].' → '.$p['status'], $this->probeLog);
        $this->linkError = 'Could not attach extras to meals. This restaurant has no /api/cashier item-ingredient API, and updating /items does not save ingredients. Tried: '.implode('; ', $tried);
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $itemIds
     * @param  array<string, string>  $ingredientIds
     * @param  array<string, string>  $ingCategoryIds
     * @param  array<string, mixed>  $report
     */
    private function linkMeals(
        string $baseUrl,
        string $token,
        array $meals,
        array $itemIds,
        array $ingredientIds,
        array $ingCategoryIds,
        array &$report,
        ?callable $progress,
    ): void {
        if ($this->linkContract === null) {
            foreach ($meals as $meal) {
                if (($meal['groups'] ?? []) === []) {
                    continue;
                }
                if (! isset($itemIds[$meal['haat_id']])) {
                    $report['links'][] = [
                        'haat_id' => $meal['haat_id'],
                        'name' => $meal['name_en'],
                        'groups' => count($meal['groups']),
                        'contents' => 0,
                        'created' => 0,
                        'failed' => 1,
                        'error' => 'Meal was not created; cannot link',
                    ];
                }
            }

            return;
        }

        $firstLinked = true;
        foreach ($meals as $meal) {
            if (($meal['groups'] ?? []) === []) {
                continue;
            }
            $itemId = $itemIds[$meal['haat_id']] ?? null;
            if ($itemId === null) {
                $report['links'][] = [
                    'haat_id' => $meal['haat_id'],
                    'name' => $meal['name_en'],
                    'groups' => count($meal['groups']),
                    'contents' => 0,
                    'created' => 0,
                    'failed' => 1,
                    'error' => 'Meal was not created; cannot link',
                ];
                continue;
            }

            $rows = $this->linkRowsForMeal($meal, $ingredientIds, $ingCategoryIds);
            $groupCount = count($meal['groups']);
            $contentCount = count($rows);

            if ($firstLinked) {
                $firstLinked = false;
                $report['links'][] = [
                    'haat_id' => $meal['haat_id'],
                    'name' => $meal['name_en'],
                    'groups' => $groupCount,
                    'contents' => $contentCount,
                    'created' => $contentCount,
                    'failed' => 0,
                    'via' => 'discover',
                ];
                continue;
            }

            $progress && $progress('links', 'Linking '.$meal['name_en'].' ('.$contentCount.' contents)', [
                'item_id' => $itemId,
                'name' => $meal['name_en'],
            ]);

            try {
                if ($this->linkContract->shape === 'flat_single') {
                    $created = 0;
                    $failed = 0;
                    $error = null;
                    foreach ($rows as $offset => $row) {
                        $progress && $progress('links', 'Link '.($offset + 1).'/'.$contentCount.' on '.$meal['name_en'], [
                            'status' => 'run',
                            'name' => $meal['name_en'],
                        ]);
                        $response = $this->sendLink($baseUrl, $token, $itemId, [$row], $this->linkContract);
                        if ($response->successful()) {
                            $created++;
                        } else {
                            $failed++;
                            $error = $this->responseMessage($response);
                            $this->recordRejection($baseUrl.$this->fillItemPath($this->linkContract->path, $itemId), $response);
                        }
                    }
                    $report['links'][] = [
                        'haat_id' => $meal['haat_id'],
                        'name' => $meal['name_en'],
                        'groups' => $groupCount,
                        'contents' => $contentCount,
                        'created' => $created,
                        'failed' => $failed,
                        'error' => $error,
                    ];
                    if ($failed === 0) {
                        $progress && $progress('links', 'Linked '.$meal['name_en'], ['status' => 'ok', 'name' => $meal['name_en']]);
                    } else {
                        $progress && $progress('links', 'FAILED '.$meal['name_en'].': '.$error, [
                            'status' => 'fail',
                            'name' => $meal['name_en'],
                            'error' => $error,
                        ]);
                    }
                    continue;
                }

                $response = $this->sendLink($baseUrl, $token, $itemId, $rows, $this->linkContract);
                if (! $response->successful() && ! $this->linkContract->asJson && $this->linkContract->shape !== 'flat_single') {
                    $jsonContract = new KamanLinkContract(
                        endpoint: $this->linkContract->endpoint,
                        method: $this->linkContract->method,
                        path: $this->linkContract->path,
                        shape: $this->linkContract->shape,
                        fields: $this->linkContract->fields,
                        asJson: true,
                        methodSpoof: $this->linkContract->methodSpoof,
                    );
                    $jsonResponse = $this->sendLink($baseUrl, $token, $itemId, $rows, $jsonContract);
                    if ($jsonResponse->successful()) {
                        $this->linkContract = $jsonContract;
                        $response = $jsonResponse;
                    }
                }
                if ($response->successful()) {
                    $report['links'][] = [
                        'haat_id' => $meal['haat_id'],
                        'name' => $meal['name_en'],
                        'groups' => $groupCount,
                        'contents' => $contentCount,
                        'created' => $contentCount,
                        'failed' => 0,
                    ];
                    $progress && $progress('links', 'Linked '.$meal['name_en'], ['status' => 'ok', 'name' => $meal['name_en']]);
                } else {
                    $this->recordRejection($baseUrl.$this->fillItemPath($this->linkContract->path, $itemId), $response);
                    $error = $this->responseMessage($response);
                    $report['links'][] = [
                        'haat_id' => $meal['haat_id'],
                        'name' => $meal['name_en'],
                        'groups' => $groupCount,
                        'contents' => $contentCount,
                        'created' => 0,
                        'failed' => $contentCount,
                        'error' => $error,
                        'status' => $response->status(),
                    ];
                    $progress && $progress('links', 'FAILED '.$meal['name_en'].': '.$error, [
                        'status' => 'fail',
                        'name' => $meal['name_en'],
                        'error' => $error,
                    ]);
                }
            } catch (FormWorkflowPausedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                $report['links'][] = [
                    'haat_id' => $meal['haat_id'],
                    'name' => $meal['name_en'],
                    'groups' => $groupCount,
                    'contents' => $contentCount,
                    'created' => 0,
                    'failed' => $contentCount,
                    'error' => $error,
                ];
                $progress && $progress('links', 'FAILED '.$meal['name_en'].': '.$error, [
                    'status' => 'fail',
                    'name' => $meal['name_en'],
                    'error' => $error,
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sendLink(string $baseUrl, string $token, string $itemId, array $rows, KamanLinkContract $contract): Response
    {
        $url = $baseUrl.$this->fillItemPath($contract->path, $itemId);
        $body = $this->bodyForContract($itemId, $rows, $contract);
        $useJson = $contract->asJson || $contract->shape !== 'flat_single';
        $method = strtolower($contract->method);

        return $this->send(function () use ($baseUrl, $token, $url, $body, $useJson, $method, $contract) {
            $http = $this->http(15)->withToken($token);
            if ($useJson) {
                return $http->asJson()->{$method}($url, $body);
            }
            $http = $http->asForm();
            if (strtoupper($contract->method) === 'PUT') {
                return $http->put($url, $body);
            }

            return $http->post($url, $body);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function bodyForContract(string $itemId, array $rows, KamanLinkContract $contract): array
    {
        $body = [];
        if ($contract->methodSpoof) {
            $body['_method'] = 'PUT';
        }

        if ($contract->shape === 'nested_groups') {
            $groups = [];
            foreach ($this->groupRows($rows) as $categoryId => $groupRows) {
                $first = $groupRows[0];
                $groups[] = [
                    'ingredient_category_id' => $categoryId,
                    'category_id' => $categoryId,
                    'min' => $first['min'],
                    'max' => $first['max'],
                    'min_select' => $first['min'],
                    'max_select' => $first['max'],
                    'is_limited' => $first['is_limited'],
                    'required' => $first['required'],
                    'type' => $first['type'],
                    'show_images' => $first['show_images'],
                    'ingredients' => array_map(fn (array $row) => $this->contentFields($row), $groupRows),
                ];
            }
            $body['ingredient_categories'] = $groups;
            $body['item_id'] = $itemId;

            return $body;
        }

        if ($contract->shape === 'flat_single') {
            $row = $rows[0];
            $body['item_id'] = $itemId;

            return array_merge($body, $this->contentFields($row), $this->groupFields($row));
        }

        $ingredients = [];
        foreach ($rows as $row) {
            $ingredients[] = array_merge($this->contentFields($row), $this->groupFields($row));
        }
        $body['ingredients'] = $ingredients;
        $body['item_id'] = $itemId;

        return $body;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function contentFields(array $row): array
    {
        return [
            'ingredient_id' => $row['ingredient_id'],
            'price' => $row['price'],
            'selected' => $row['selected'],
            'is_default' => $row['selected'],
            'default' => $row['selected'],
            'selected_by_default' => $row['selected'],
            'removable' => $row['removable'],
            'can_remove' => $row['removable'],
            'non_modifiable' => $row['non_modifiable'],
            'is_basic' => $row['non_modifiable'],
            'max_count' => $row['max_count'],
            'ingredient_category_id' => $row['ingredient_category_id'],
            'category_id' => $row['ingredient_category_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function groupFields(array $row): array
    {
        return [
            'min' => $row['min'],
            'max' => $row['max'],
            'min_select' => $row['min'],
            'max_select' => $row['max'],
            'minimum' => $row['min'],
            'maximum' => $row['max'],
            'min_quantity' => $row['min'],
            'max_quantity' => $row['max'],
            'is_limited' => $row['is_limited'],
            'limited' => $row['is_limited'],
            'required' => $row['required'],
            'is_required' => $row['required'],
            'type' => $row['type'],
            'show_images' => $row['show_images'],
            'showImages' => $row['show_images'],
            'free' => $row['free'],
            'free_quantity' => $row['free'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) $row['ingredient_category_id'];
            $grouped[$key][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function retryFromValidation(
        string $baseUrl,
        string $token,
        string $itemId,
        array $rows,
        KamanLinkContract $contract,
        Response $response,
    ): ?KamanLinkContract {
        $json = $response->json();
        $errors = is_array($json) ? ($json['errors'] ?? []) : [];
        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $keys = array_keys($errors);
        $this->rejectedDetails[] = [
            'url' => $baseUrl.$this->fillItemPath($contract->path, $itemId),
            'fields' => $keys,
            'message' => $this->responseMessage($response),
        ];

        $shape = $contract->shape;
        $joined = implode(' ', $keys);
        if (str_contains($joined, 'ingredient_categories')) {
            $shape = 'nested_groups';
        } elseif (preg_match('/ingredients\.\d+/', $joined)) {
            $shape = 'nested_ingredients';
        } elseif (in_array('ingredient_id', $keys, true) || in_array('item_id', $keys, true)) {
            $shape = 'flat_single';
        }

        $accepted = [];
        foreach ($keys as $key) {
            $accepted[] = preg_replace('/\.\d+\./', '.', $key) ?? $key;
        }

        $retried = new KamanLinkContract(
            endpoint: $contract->endpoint,
            method: $contract->method,
            path: $contract->path,
            shape: $shape,
            fields: array_values(array_unique($accepted)) ?: $contract->fields,
            asJson: $contract->asJson,
            methodSpoof: $contract->methodSpoof,
        );

        if ($shape === 'flat_single') {
            $ok = true;
            foreach ($rows as $row) {
                $retryResponse = $this->sendLink($baseUrl, $token, $itemId, [$row], $retried);
                if (! $retryResponse->successful()) {
                    $ok = false;
                    $this->recordRejection($baseUrl.$this->fillItemPath($retried->path, $itemId), $retryResponse);
                    break;
                }
            }
            if ($ok) {
                $this->probeLog[] = ['url' => $baseUrl.$this->fillItemPath($retried->path, $itemId), 'method' => $retried->method, 'status' => 200];

                return $retried;
            }

            return null;
        }

        $retryResponse = $this->sendLink($baseUrl, $token, $itemId, $rows, $retried);
        $this->probeLog[] = [
            'url' => $baseUrl.$this->fillItemPath($retried->path, $itemId),
            'method' => $retried->method,
            'status' => $retryResponse->status(),
        ];
        if ($retryResponse->successful()) {
            return $retried;
        }

        $this->recordRejection($baseUrl.$this->fillItemPath($retried->path, $itemId), $retryResponse);

        return null;
    }

    /**
     * @param  array<string, mixed>  $meal
     * @param  array<string, string>  $ingredientIds
     * @param  array<string, string>  $ingCategoryIds
     * @return list<array<string, mixed>>
     */
    private function linkRowsForMeal(array $meal, array $ingredientIds, array $ingCategoryIds): array
    {
        $rows = [];
        foreach ($meal['groups'] ?? [] as $group) {
            $categoryId = $ingCategoryIds[$group['title']] ?? null;
            $type = $this->groupType($group);
            $required = ((int) $group['min']) >= 1 ? '1' : '0';
            foreach ($group['contents'] ?? [] as $content) {
                if (($content['view_type'] ?? '') === 'Unavailable') {
                    continue;
                }
                $ingredientId = $ingredientIds[$content['haat_id']] ?? null;
                if ($ingredientId === null || $categoryId === null) {
                    continue;
                }
                $fixed = ($content['view_type'] ?? '') === 'Fixed' || ! empty($content['non_modifiable']);
                $rows[] = [
                    'ingredient_id' => $ingredientId,
                    'ingredient_category_id' => $categoryId,
                    'price' => $content['price'],
                    'selected' => ! empty($content['selected_by_default']) ? '1' : '0',
                    'removable' => $fixed ? '0' : '1',
                    'non_modifiable' => $fixed ? '1' : '0',
                    'max_count' => ((int) ($content['max_count'] ?? 0)) > 0 ? (string) $content['max_count'] : '0',
                    'min' => (string) $group['min'],
                    'max' => (string) $group['max'],
                    'is_limited' => ! empty($group['is_limited']) ? '1' : '0',
                    'required' => $required,
                    'type' => $type,
                    'show_images' => ! empty($group['show_images']) ? '1' : '0',
                    'free' => $group['free'] === null ? '' : (string) $group['free'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function groupType(array $group): string
    {
        $min = (int) ($group['min'] ?? 0);
        $max = (int) ($group['max'] ?? 0);
        $viewTypes = [];
        foreach ($group['contents'] ?? [] as $content) {
            $viewTypes[] = (string) ($content['view_type'] ?? '');
        }
        $unique = array_values(array_unique($viewTypes));
        if ($min === 1 && $max === 1) {
            return 'single';
        }
        if ($unique === ['Radio'] || (in_array('Radio', $unique, true) && ! in_array('Checkbox', $unique, true))) {
            return 'single';
        }

        return 'multiple';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function showHasLinkShape(array $data): bool
    {
        foreach (['ingredients', 'ingredient_categories', 'addons', 'item_ingredients'] as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function inferShowShape(array $data): string
    {
        if (isset($data['ingredient_categories']) && is_array($data['ingredient_categories'])) {
            return 'nested_groups';
        }

        return 'nested_ingredients';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function fieldsFromRows(array $rows): array
    {
        return $rows === [] ? [] : array_keys($this->contentFields($rows[0]) + $this->groupFields($rows[0]));
    }

    private function fillItemPath(string $path, string $itemId): string
    {
        return str_replace('{itemId}', $itemId, $path);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function acceptDiscoveredContract(
        string $baseUrl,
        string $token,
        string $itemId,
        array $rows,
        KamanLinkContract $contract,
        ?callable $progress,
    ): bool {
        $ingredientId = (string) ($rows[0]['ingredient_id'] ?? '');
        if ($ingredientId !== '' && $this->linkPersisted($baseUrl, $token, $itemId, $ingredientId)) {
            $this->linkContract = $contract;

            return true;
        }

        $progress && $progress('discover_link', $contract->method.' '.$contract->path.' returned HTTP 200 but the meal still has no extras', [
            'status' => 'fail',
            'item_id' => $itemId,
        ]);

        return false;
    }

    private function linkPersisted(string $baseUrl, string $token, string $itemId, string $ingredientId): bool
    {
        $cashierRows = $this->cashier->itemIngredients($baseUrl, $token, $itemId);
        if (isset($this->indexItemLinks($cashierRows)[$ingredientId])) {
            return true;
        }

        $show = $this->send(fn () => $this->http()->withToken($token)->get($baseUrl.'/items/'.$itemId));
        if (! $show->successful()) {
            return false;
        }

        $encoded = json_encode($show->json()) ?: '';

        return $ingredientId !== '' && str_contains($encoded, $ingredientId);
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $itemIds
     * @param  array<string, mixed>  $report
     */
    private function failAllLinks(array $meals, array $itemIds, array &$report, string $error): void
    {
        foreach ($meals as $meal) {
            if (($meal['groups'] ?? []) === []) {
                continue;
            }
            $contents = 0;
            foreach ($meal['groups'] as $group) {
                $contents += count($group['contents'] ?? []);
            }
            $report['links'][] = [
                'haat_id' => $meal['haat_id'] ?? '',
                'name' => $meal['name_en'] ?? '',
                'groups' => count($meal['groups'] ?? []),
                'contents' => $contents,
                'created' => 0,
                'failed' => max(1, $contents),
                'error' => $error,
            ];
        }
    }

    private function noteHttp(?callable $progress, string $method, string $path, Response $response, string $label = ''): void
    {
        if ($progress === null) {
            return;
        }
        $suffix = $label !== '' ? ' '.$label : '';
        $progress('links', sprintf('%s %s → %d%s', $method, $path, $response->status(), $suffix), [
            'status' => $response->successful() ? 'ok' : 'fail',
            'http' => $response->status(),
        ]);
    }

    private function recordRejection(string $url, Response $response): void
    {
        $json = $response->json();
        $keys = [];
        if (is_array($json) && isset($json['errors']) && is_array($json['errors'])) {
            $keys = array_keys($json['errors']);
        }
        $this->rejectedDetails[] = [
            'url' => $url,
            'fields' => $keys,
            'message' => $this->responseMessage($response),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $itemIds
     * @return array<string, mixed>|null
     */
    private function firstMealWithGroups(array $meals, array $itemIds): ?array
    {
        foreach ($meals as $meal) {
            if (($meal['groups'] ?? []) !== [] && isset($itemIds[$meal['haat_id']])) {
                return $meal;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $itemIds
     * @return list<array<string, mixed>>
     */
    private function mealsNeedingLinks(array $meals, array $itemIds): array
    {
        $out = [];
        foreach ($meals as $meal) {
            if (($meal['groups'] ?? []) !== [] && isset($itemIds[$meal['haat_id']])) {
                $out[] = $meal;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $ingredientIds
     */
    private function firstIngredientId(array $ingredientIds): ?string
    {
        foreach ($ingredientIds as $id) {
            return $id;
        }

        return null;
    }

    private function firstKitchenId(string $baseUrl, string $token): ?string
    {
        $response = $this->send(fn () => $this->http()->withToken($token)->get($baseUrl.'/kitchens'));
        if (! $response->successful()) {
            return null;
        }
        $list = $this->unwrapList($response->json(), ['kitchens']);
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? $row['kitchen_id'] ?? null;
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function fetchList(string $baseUrl, string $token, string $path, array $keys): array
    {
        $all = [];
        $page = 1;
        $lastPage = 1;

        do {
            $url = $page === 1 ? $baseUrl.$path : $baseUrl.$path.(str_contains($path, '?') ? '&' : '?').'page='.$page;
            $response = $this->send(fn () => $this->http()->withToken($token)->get($url));
            if (! $response->successful()) {
                break;
            }
            $json = $response->json();
            $list = $this->unwrapList($json, $keys);
            $all = array_merge($all, $list);
            $lastPage = $this->lastPageFrom($json, $page);
            $page++;
        } while ($page <= $lastPage && $page <= 50);

        return $all;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function lastPageFrom(mixed $json, int $page): int
    {
        if (! is_array($json)) {
            return $page;
        }
        $meta = is_array($json['meta'] ?? null) ? $json['meta'] : [];
        $last = $meta['last_page']
            ?? $json['last_page']
            ?? (is_array($json['data'] ?? null) ? ($json['data']['last_page'] ?? ($json['data']['pagination']['last_page'] ?? null)) : null);
        if (is_numeric($last)) {
            return max($page, (int) $last);
        }

        return $page;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function unwrapList(mixed $json, array $keys): array
    {
        if (! is_array($json)) {
            return [];
        }
        $list = $json['data'] ?? null;
        if (! is_array($list)) {
            foreach ($keys as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    $list = $json[$key];
                    break;
                }
            }
        }
        if (! is_array($list)) {
            $list = $json;
        }
        if (isset($list['data']) && is_array($list['data'])) {
            $list = $list['data'];
        }
        if (isset($list['items']) && is_array($list['items'])) {
            $list = $list['items'];
        }
        if ($list === [] || ! is_array($list)) {
            return [];
        }
        if (! array_is_list($list)) {
            return [];
        }

        return $list;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function indexByName(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? $row['category_id'] ?? $row['ingredient_id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $id = (string) $id;
            foreach (['name_ar', 'name_en', 'name_he', 'name'] as $field) {
                $name = trim((string) ($row[$field] ?? ''));
                if ($name !== '') {
                    $index[$this->nameKey($name)] = $id;
                }
            }
        }

        return $index;
    }

    /**
     * @param  array<string, string>  $existing
     */
    private function matchExisting(array $existing, string $nameAr, string $nameEn): ?string
    {
        foreach ([$nameAr, $nameEn] as $name) {
            $key = $this->nameKey($name);
            if ($key !== '' && isset($existing[$key])) {
                return $existing[$key];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{id: string, category_id: string}>
     */
    private function indexIngredients(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? $row['ingredient_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $categoryId = (string) ($row['category_id'] ?? '');
            foreach (['name_ar', 'name_en', 'name_he', 'name'] as $field) {
                $key = $this->nameKey((string) ($row[$field] ?? ''));
                if ($key === '') {
                    continue;
                }
                $index[$key.'|'.$categoryId] = ['id' => $id, 'category_id' => $categoryId];
                $index[$key] ??= ['id' => $id, 'category_id' => $categoryId];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, array{id: string, category_id: string}>  $existing
     * @param  array<string, mixed>  $ingredient
     */
    private function matchExistingIngredient(array $existing, array $ingredient, string $categoryId): ?string
    {
        foreach ([(string) ($ingredient['name_ar'] ?? ''), (string) ($ingredient['name_en'] ?? ''), (string) ($ingredient['name'] ?? '')] as $name) {
            $key = $this->nameKey($name);
            if ($key === '') {
                continue;
            }
            $hit = $existing[$key.'|'.$categoryId] ?? null;
            if (is_array($hit) && ($hit['id'] ?? '') !== '') {
                return (string) $hit['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $map
     * @param  array<string, mixed>  $ingredient
     */
    private function rememberIngredientIds(array &$map, array $ingredient, string $id): void
    {
        foreach ($this->ingredientHaatIds($ingredient) as $haatId) {
            $map[$haatId] = $id;
        }
    }

    /**
     * @param  array<string, array{id: string, category_id: string}>  $existing
     * @param  array<string, mixed>  $ingredient
     */
    private function rememberIndexedIngredient(array &$existing, array $ingredient, string $categoryId, string $id): void
    {
        foreach ([(string) ($ingredient['name_ar'] ?? ''), (string) ($ingredient['name_en'] ?? ''), (string) ($ingredient['name'] ?? '')] as $name) {
            $key = $this->nameKey($name);
            if ($key === '') {
                continue;
            }
            $row = ['id' => $id, 'category_id' => $categoryId];
            $existing[$key.'|'.$categoryId] = $row;
            $existing[$key] ??= $row;
        }
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

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractId(?array $json, string $entity): ?string
    {
        if ($json === null) {
            return null;
        }
        $id = $json['data']['id']
            ?? $json['id']
            ?? $json[$entity]['id']
            ?? (is_array($json['data'][$entity] ?? null) ? ($json['data'][$entity]['id'] ?? null) : null)
            ?? (is_array($json['data']['data'] ?? null) ? ($json['data']['data']['id'] ?? null) : null);
        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;
    }

    private function responseMessage(Response $response): string
    {
        $status = $response->status();
        $body = $response->json();
        $parts = [];
        if (is_array($body)) {
            $message = $body['message'] ?? $body['error'] ?? null;
            if (is_array($message)) {
                $message = implode(' ', array_map(fn ($v) => is_string($v) ? $v : json_encode($v), $message));
            }
            if (is_string($message) && $message !== '') {
                $parts[] = $message;
            }
            $errors = $body['errors'] ?? null;
            if (is_array($errors) && $errors !== []) {
                foreach ($errors as $field => $msgs) {
                    $text = is_array($msgs)
                        ? implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $msgs))
                        : (string) $msgs;
                    $parts[] = $field.': '.$text;
                }
            }
        }
        if ($parts === []) {
            $raw = trim($response->body());
            if ($raw !== '') {
                $parts[] = mb_substr($raw, 0, 280);
            }
        }

        $detail = implode(' | ', $parts);

        return $detail !== '' ? 'HTTP '.$status.': '.$detail : 'HTTP '.$status;
    }

    private function responseSnippet(Response $response): string
    {
        $raw = trim($response->body());

        return $raw === '' ? 'empty body' : mb_substr($raw, 0, 280);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array{id: string, category_id: string}>>
     */
    private function indexItems(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? $row['item_id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $categoryId = (string) ($row['category_id'] ?? (is_array($row['category'] ?? null) ? ($row['category']['id'] ?? '') : ''));
            foreach (['name_ar', 'name_en', 'name_he', 'name'] as $field) {
                $key = $this->nameKey((string) ($row[$field] ?? ''));
                if ($key === '') {
                    continue;
                }
                $index[$key][] = ['id' => (string) $id, 'category_id' => $categoryId];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, list<array{id: string, category_id: string}>>  $existing
     */
    private function matchExistingItem(array $existing, string $nameAr, string $nameEn, string $categoryId): ?string
    {
        foreach ([$nameAr, $nameEn] as $name) {
            $key = $this->nameKey($name);
            if ($key === '' || ! isset($existing[$key])) {
                continue;
            }
            foreach ($existing[$key] as $row) {
                if ($row['category_id'] === $categoryId || $row['category_id'] === '') {
                    return $row['id'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<array{id: string, category_id: string}>>  $existing
     * @param  array<string, true>  $usedNames
     */
    private function nameIsTaken(array $existing, array $usedNames, string $nameAr, string $nameEn): bool
    {
        foreach ([$nameAr, $nameEn] as $name) {
            $key = $this->nameKey($name);
            if ($key !== '' && (isset($usedNames[$key]) || isset($existing[$key]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<array{id: string, category_id: string}>>  $existing
     * @param  array<string, true>  $usedNames
     */
    private function rememberItem(array &$existing, array &$usedNames, string $nameAr, string $nameEn, string $id, string $categoryId): void
    {
        foreach ([$nameAr, $nameEn] as $name) {
            $key = $this->nameKey($name);
            if ($key === '') {
                continue;
            }
            $usedNames[$key] = true;
            $existing[$key][] = ['id' => $id, 'category_id' => $categoryId];
        }
    }

    private function looksLikeDuplicateName(string $message): bool
    {
        $haystack = mb_strtolower($message);

        return str_contains($haystack, 'already been taken')
            || str_contains($haystack, 'has already been taken')
            || str_contains($haystack, 'unique')
            || str_contains($haystack, 'مستخدم')
            || str_contains($haystack, 'موجود');
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function pipelineSummary(array $report): array
    {
        $linkFailed = 0;
        foreach ($report['links'] ?? [] as $link) {
            if (($link['failed'] ?? 0) > 0 || ! empty($link['error'])) {
                $linkFailed++;
            }
        }

        $bucket = static fn (array $group): array => [
            'created' => count($group['created'] ?? []),
            'updated' => count($group['updated'] ?? []),
            'reused' => count($group['reused'] ?? []),
            'failed' => count($group['failed'] ?? []),
        ];

        $linkCreated = 0;
        $linkUpdated = 0;
        $linkReused = 0;
        foreach ($report['links'] ?? [] as $link) {
            $linkCreated += (int) ($link['created'] ?? 0);
            $linkUpdated += (int) ($link['updated'] ?? 0);
            $linkReused += (int) ($link['reused'] ?? 0);
        }

        return [
            'categories' => $bucket($report['categories'] ?? []),
            'meals' => $bucket($report['meals'] ?? []),
            'ingredient_categories' => $bucket($report['ingredient_categories'] ?? []),
            'ingredients' => $bucket($report['ingredients'] ?? []),
            'links' => [
                'created' => $linkCreated,
                'updated' => $linkUpdated,
                'reused' => $linkReused,
                'failed' => $linkFailed,
            ],
        ];
    }
}
