<?php

declare(strict_types=1);

namespace Tests\Feature\Haat;

use App\Services\AI\Workflows\HaatMenuCopyWorkflow;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HaatMenuCopyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_exposes_haat_menu_copy_and_json_upload(): void
    {
        $this->seed(WorkspaceUserSeeder::class);

        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($user)
            ->get(route('form.index'))
            ->assertOk()
            ->assertSee('HAAT Menu Copy', false)
            ->assertSee('name="haat_menu_json"', false)
            ->assertSee('id="haatMenuSection"', false)
            ->assertSee('id="formRunsPanel"', false)
            ->assertSee('id="workflowPauseBtn"', false);
    }

    public function test_workflow_creates_entities_and_links_two_contents(): void
    {
        $this->fakeKaman();

        $json = json_encode([
            'sections' => [
                ['type' => 'TopSelling', 'name' => 'Top', 'restaurantItems' => []],
                [
                    'type' => 'Categories',
                    'id' => 1,
                    'name' => 'برجر',
                    'nameEnglish' => 'Burgers',
                    'restaurantItems' => [[
                        'id' => 10,
                        'menuItemType' => 'Meal',
                        'name' => 'برجر لحم',
                        'nameEnglish' => 'Beef burger',
                        'description' => 'وصف',
                        'price' => ['finalPrice' => 38],
                        'availability' => ['isAvailable' => true],
                        'addonGroups' => [[
                            'id' => 20,
                            'title' => 'إضافات',
                            'min' => 0,
                            'max' => 5,
                            'isLimited' => false,
                            'showImages' => true,
                            'free' => null,
                            'mealContents' => [
                                [
                                    'id' => 100,
                                    'name' => 'خس',
                                    'price' => 0,
                                    'viewType' => 'Checkbox',
                                    'selectedByDefault' => true,
                                    'nonModifiable' => false,
                                    'maxCount' => 0,
                                ],
                                [
                                    'id' => 101,
                                    'name' => 'بندورة',
                                    'price' => 2,
                                    'viewType' => 'Checkbox',
                                    'selectedByDefault' => false,
                                    'nonModifiable' => false,
                                    'maxCount' => 0,
                                ],
                            ],
                        ]],
                    ]],
                ],
                ['type' => 'Footer', 'name' => 'Footer', 'restaurantItems' => []],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'username' => 'demo@kaman.rest',
            'password' => 'secret',
            'environment' => 'rest',
            'haat_json' => $json,
            'no_images' => true,
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? json_encode($result));
        $this->assertCount(1, $result['data']['categories']['created']);
        $this->assertCount(1, $result['data']['meals']['created']);
        $this->assertCount(1, $result['data']['ingredient_categories']['created']);
        $this->assertCount(2, $result['data']['ingredients']['created']);
        $this->assertSame('POST /api/cashier/items/{itemId}/ingredients', $result['data']['discovered_link']['endpoint']);

        $linkPosts = collect(Http::recorded())
            ->filter(function (array $pair) {
                /** @var Request $request */
                $request = $pair[0];

                return $request->method() === 'POST'
                    && str_contains($request->url(), '/items/item-1/ingredients');
            })
            ->values();

        $this->assertGreaterThanOrEqual(1, $linkPosts->count());

        $ingredientIds = [];
        foreach ($linkPosts as $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $data = $request->data();
            if (isset($data['ingredients']) && is_array($data['ingredients'])) {
                foreach ($data['ingredients'] as $row) {
                    if (is_array($row) && isset($row['ingredient_id'])) {
                        $ingredientIds[] = (string) $row['ingredient_id'];
                    }
                }
            } elseif (isset($data['ingredient_id'])) {
                $ingredientIds[] = (string) $data['ingredient_id'];
            }
        }

        $this->assertCount(2, $ingredientIds);
        $this->assertEqualsCanonicalizing(['ing-1', 'ing-2'], $ingredientIds);
        foreach ($linkPosts as $pair) {
            $this->assertArrayHasKey('ingredient_id', $pair[0]->data());
            $this->assertArrayNotHasKey('ingredients', $pair[0]->data());
        }

        $bodies = json_encode(array_map(fn (array $pair) => $pair[0]->data(), $linkPosts->all()));
        $this->assertIsString($bodies);
        $this->assertStringNotContainsString('hashedPrice', $bodies);
    }

    public function test_existing_meal_in_same_category_is_reused(): void
    {
        $this->fakeKaman([
            'items' => [[
                'id' => 'existing-item',
                'name_ar' => 'برجر لحم',
                'name_en' => 'Beef burger',
                'price' => 38,
                'category_id' => 'cat-1',
            ]],
        ]);

        $json = json_encode([
            'sections' => [[
                'type' => 'Categories',
                'id' => 1,
                'name' => 'برجر',
                'nameEnglish' => 'Burgers',
                'restaurantItems' => [[
                    'id' => 10,
                    'menuItemType' => 'Meal',
                    'name' => 'برجر لحم',
                    'nameEnglish' => 'Beef burger',
                    'description' => '',
                    'price' => ['finalPrice' => 38],
                    'availability' => ['isAvailable' => true],
                    'addonGroups' => [[
                        'id' => 20,
                        'title' => 'إضافات',
                        'min' => 0,
                        'max' => 5,
                        'mealContents' => [[
                            'id' => 100,
                            'name' => 'خس',
                            'price' => 0,
                            'viewType' => 'Checkbox',
                            'selectedByDefault' => true,
                            'nonModifiable' => false,
                            'maxCount' => 0,
                        ]],
                    ]],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'password' => 'secret',
            'environment' => 'rest',
            'haat_json' => $json,
            'no_images' => true,
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? json_encode($result));
        $this->assertCount(0, $result['data']['meals']['created']);
        $this->assertCount(1, $result['data']['meals']['reused']);
        $this->assertSame('existing-item', $result['data']['meals']['reused'][0]['id']);
    }

    public function test_item_update_200_is_not_counted_as_links_when_cashier_api_is_missing(): void
    {
        $this->fakeKaman(['cashier' => false]);

        $json = json_encode([
            'sections' => [[
                'type' => 'Categories',
                'id' => 1,
                'name' => 'برجر',
                'nameEnglish' => 'Burgers',
                'restaurantItems' => [[
                    'id' => 10,
                    'menuItemType' => 'Meal',
                    'name' => 'برجر لحم',
                    'nameEnglish' => 'Beef burger',
                    'description' => '',
                    'price' => ['finalPrice' => 38],
                    'availability' => ['isAvailable' => true],
                    'addonGroups' => [[
                        'id' => 20,
                        'title' => 'إضافات',
                        'min' => 0,
                        'max' => 5,
                        'mealContents' => [[
                            'id' => 100,
                            'name' => 'خس',
                            'price' => 0,
                            'viewType' => 'Checkbox',
                            'selectedByDefault' => true,
                            'nonModifiable' => false,
                            'maxCount' => 0,
                        ]],
                    ]],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'password' => 'secret',
            'environment' => 'rest',
            'haat_json' => $json,
            'no_images' => true,
        ]);

        $this->assertFalse($result['success'] ?? true, json_encode($result));
        $this->assertSame(0, $result['data']['pipeline']['links']['created'] ?? -1);
        $this->assertGreaterThan(0, $result['data']['pipeline']['links']['failed'] ?? 0);
        $this->assertStringContainsString('cashier', strtolower((string) ($result['error'] ?? $result['data']['links'][0]['error'] ?? '')));

        $linkPosts = collect(Http::recorded())->filter(function (array $pair) {
            return $pair[0]->method() === 'POST'
                && str_contains($pair[0]->url(), '/api/cashier/items/')
                && str_contains($pair[0]->url(), '/ingredients');
        });
        $this->assertCount(0, $linkPosts);
    }

    public function test_stored_kaman_token_skips_password_login(): void
    {
        $this->fakeKaman();

        $json = json_encode([
            'sections' => [[
                'type' => 'Categories',
                'id' => 1,
                'name' => 'برجر',
                'nameEnglish' => 'Burgers',
                'restaurantItems' => [[
                    'id' => 10,
                    'menuItemType' => 'Item',
                    'name' => 'كولا',
                    'nameEnglish' => 'Cola',
                    'description' => '',
                    'price' => ['finalPrice' => 8],
                    'availability' => ['isAvailable' => true],
                    'addonGroups' => [],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'environment' => 'rest',
            'kaman_token' => 'stored-token',
            'kaman_base_url' => 'https://demo.kaman.rest/api/manager',
            'haat_json' => $json,
            'no_images' => true,
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? json_encode($result));
        $loginPosts = collect(Http::recorded())->filter(function (array $pair) {
            return $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/login');
        });
        $this->assertCount(0, $loginPosts);
    }

    public function test_existing_meal_price_is_updated(): void
    {
        $this->fakeKaman([
            'categories' => [[
                'id' => 'cat-1',
                'name_ar' => 'برجر',
                'name_en' => 'Burgers',
            ]],
            'items' => [[
                'id' => 'existing-item',
                'name_ar' => 'برجر لحم',
                'name_en' => 'Beef burger',
                'price' => 10,
                'category_id' => 'cat-1',
                'description_ar' => 'وصف',
            ]],
        ]);

        $json = json_encode([
            'sections' => [[
                'type' => 'Categories',
                'id' => 1,
                'name' => 'برجر',
                'nameEnglish' => 'Burgers',
                'restaurantItems' => [[
                    'id' => 10,
                    'menuItemType' => 'Item',
                    'name' => 'برجر لحم',
                    'nameEnglish' => 'Beef burger',
                    'description' => 'وصف',
                    'price' => ['finalPrice' => 42],
                    'availability' => ['isAvailable' => true],
                    'addonGroups' => [],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'password' => 'secret',
            'environment' => 'rest',
            'haat_json' => $json,
            'no_images' => true,
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? json_encode($result));
        $this->assertCount(0, $result['data']['meals']['created']);
        $this->assertCount(1, $result['data']['meals']['updated']);
        $this->assertSame('existing-item', $result['data']['meals']['updated'][0]['id']);

        $itemCreates = collect(Http::recorded())->filter(function (array $pair) {
            return $pair[0]->method() === 'POST' && preg_match('#/api/manager/items$#', $pair[0]->url());
        });
        $this->assertCount(0, $itemCreates);

        $itemUpdates = collect(Http::recorded())->filter(function (array $pair) {
            $url = $pair[0]->url();

            return ($pair[0]->method() === 'PUT' && str_contains($url, '/items/existing-item'))
                || ($pair[0]->method() === 'POST' && preg_match('#/items/existing-item$#', $url));
        });
        $this->assertGreaterThanOrEqual(1, $itemUpdates->count());
    }

    public function test_cashier_updates_existing_ingredient_and_link(): void
    {
        $this->fakeKaman([
            'categories' => [[
                'id' => 'cat-1',
                'name_ar' => 'برجر',
                'name_en' => 'Burgers',
            ]],
            'items' => [[
                'id' => 'existing-item',
                'name_ar' => 'برجر لحم',
                'name_en' => 'Beef burger',
                'price' => 38,
                'category_id' => 'cat-1',
            ]],
            'cashier_ing_cats' => [[
                'id' => 'ic-1',
                'name_ar' => 'إضافات',
                'name_en' => 'Addons',
                'type' => 'multi_option',
                'min_options' => 0,
                'max_options' => 5,
                'must_pick' => false,
            ]],
            'cashier_ings' => [[
                'id' => 'ing-keep',
                'name_ar' => 'خس',
                'name_en' => 'خس',
                'price' => 1,
                'category_id' => 'ic-1',
                'allow_multiple' => true,
            ]],
            'item_links' => [
                'existing-item' => [[
                    'ingredient_id' => 'ing-keep',
                    'default_ingredient' => true,
                    'extra_fee' => false,
                    'custom_fee' => null,
                ]],
            ],
        ]);

        $json = json_encode([
            'sections' => [[
                'type' => 'Categories',
                'id' => 1,
                'name' => 'برجر',
                'nameEnglish' => 'Burgers',
                'restaurantItems' => [[
                    'id' => 10,
                    'menuItemType' => 'Meal',
                    'name' => 'برجر لحم',
                    'nameEnglish' => 'Beef burger',
                    'description' => '',
                    'price' => ['finalPrice' => 38],
                    'availability' => ['isAvailable' => true],
                    'addonGroups' => [[
                        'id' => 20,
                        'title' => 'إضافات',
                        'min' => 0,
                        'max' => 5,
                        'mealContents' => [
                            [
                                'id' => 100,
                                'name' => 'خس',
                                'price' => 0,
                                'viewType' => 'Checkbox',
                                'selectedByDefault' => true,
                                'nonModifiable' => false,
                                'maxCount' => 0,
                            ],
                            [
                                'id' => 101,
                                'name' => 'بندورة',
                                'price' => 2,
                                'viewType' => 'Checkbox',
                                'selectedByDefault' => false,
                                'nonModifiable' => false,
                                'maxCount' => 0,
                            ],
                        ],
                    ]],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'password' => 'secret',
            'environment' => 'rest',
            'haat_json' => $json,
            'no_images' => true,
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? json_encode($result));
        $this->assertCount(0, $result['data']['meals']['created']);
        $this->assertNotEmpty($result['data']['ingredients']['updated']);
        $this->assertCount(1, $result['data']['ingredients']['created']);

        $ingredientPuts = collect(Http::recorded())->filter(function (array $pair) {
            return $pair[0]->method() === 'PUT'
                && str_contains($pair[0]->url(), '/api/cashier/ingredients/ing-keep');
        });
        $this->assertGreaterThanOrEqual(1, $ingredientPuts->count());
    }

    public function test_same_ingredient_in_two_meals_is_created_once(): void
    {
        $this->fakeKaman();

        $meal = static fn (int $id, int $lettuceId): array => [
            'id' => $id,
            'menuItemType' => 'Meal',
            'name' => 'سلطة '.$id,
            'nameEnglish' => 'Salad '.$id,
            'description' => '',
            'price' => ['finalPrice' => 20],
            'availability' => ['isAvailable' => true],
            'addonGroups' => [[
                'id' => 20,
                'title' => 'إضافات',
                'min' => 0,
                'max' => 5,
                'mealContents' => [[
                    'id' => $lettuceId,
                    'name' => 'خس',
                    'price' => 0,
                    'viewType' => 'Checkbox',
                    'selectedByDefault' => true,
                    'nonModifiable' => false,
                    'maxCount' => 0,
                ]],
            ]],
        ];

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'username' => 'demo@kaman.rest',
            'password' => 'secret',
            'environment' => 'rest',
            'no_images' => true,
            'haat_json' => json_encode([
                'sections' => [[
                    'type' => 'Categories',
                    'id' => 1,
                    'name' => 'سلطات',
                    'nameEnglish' => 'Salads',
                    'restaurantItems' => [$meal(10, 100), $meal(11, 101)],
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? json_encode($result));
        $this->assertCount(1, $result['data']['ingredients']['created']);
        $this->assertCount(2, $result['data']['meals']['created']);

        $ingredientCreates = collect(Http::recorded())->filter(function (array $pair) {
            $url = $pair[0]->url();

            return $pair[0]->method() === 'POST'
                && str_contains($url, '/api/cashier/ingredients')
                && ! str_contains($url, 'ingredient-categories');
        });
        $this->assertCount(1, $ingredientCreates);
    }

    public function test_item_create_retries_http_429_instead_of_failing(): void
    {
        $this->fakeKaman(['item_429' => 2]);

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'username' => 'demo@kaman.rest',
            'password' => 'secret',
            'environment' => 'rest',
            'no_images' => true,
            'haat_json' => json_encode([
                'sections' => [[
                    'type' => 'Categories',
                    'id' => 1,
                    'name' => 'برجر',
                    'nameEnglish' => 'Burgers',
                    'restaurantItems' => [[
                        'id' => 10,
                        'menuItemType' => 'Item',
                        'name' => 'برجر',
                        'nameEnglish' => 'Burger',
                        'price' => ['finalPrice' => 30],
                        'availability' => ['isAvailable' => true],
                    ]],
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? json_encode($result));
        $this->assertCount(1, $result['data']['meals']['created']);
        $this->assertSame([], $result['data']['meals']['failed']);
    }

    public function test_pause_command_stops_the_workflow(): void
    {
        $this->fakeKaman();
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $run = \App\Models\FormWorkflowRun::query()->create([
            'user_id' => $user->id,
            'method_type' => 'HAAT Menu Copy',
            'subdomain' => 'demo',
            'environment' => 'rest',
            'status' => \App\Models\FormWorkflowRun::STATUS_RUNNING,
            'payload' => ['subdomain' => 'demo'],
            'started_at' => now(),
        ]);
        app(\App\Services\Form\FormWorkflowRunService::class)->requestPause($run);

        $result = app(HaatMenuCopyWorkflow::class)->run([
            'restaurant_name' => 'demo',
            'username' => 'demo@kaman.rest',
            'password' => 'secret',
            'environment' => 'rest',
            'no_images' => true,
            'run_id' => $run->id,
            'haat_json' => json_encode([
                'sections' => [[
                    'type' => 'Categories',
                    'id' => 1,
                    'name' => 'برجر',
                    'nameEnglish' => 'Burgers',
                    'restaurantItems' => [[
                        'id' => 10,
                        'menuItemType' => 'Item',
                        'name' => 'برجر',
                        'nameEnglish' => 'Burger',
                        'price' => ['finalPrice' => 30],
                        'availability' => ['isAvailable' => true],
                    ]],
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertTrue($result['paused'] ?? false);
        $this->assertFalse($result['success'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function fakeKaman(array $state = []): void
    {
        config([
            'services.kaman.ssl_verify' => false,
            'services.kaman.api_tld' => 'rest',
            'openai.api_key' => '',
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($state) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'POST' && str_ends_with($url, '/login')) {
                return Http::response(['token' => 'test-token'], 200);
            }

            if (($state['cashier'] ?? true) === false && str_contains($url, '/api/cashier/')) {
                return Http::response(['message' => 'The route could not be found.'], 404);
            }

            if (str_contains($url, '/api/cashier/ingredient-categories')) {
                if ($method === 'GET') {
                    return Http::response(['success' => true, 'data' => $state['cashier_ing_cats'] ?? []], 200);
                }
                if ($method === 'POST') {
                    return Http::response(['success' => true, 'data' => [
                        'id' => 'ic-1',
                        'type' => 'multi_option',
                        'must_pick' => false,
                    ]], 200);
                }
                if ($method === 'PUT') {
                    return Http::response(['success' => true, 'data' => ['id' => 'ic-1']], 200);
                }
            }

            if (preg_match('#/api/cashier/items/([^/]+)/ingredients(?:/([^/?]+))?#', $url, $matches)) {
                $itemId = $matches[1];
                if ($method === 'GET') {
                    return Http::response(['success' => true, 'data' => $state['item_links'][$itemId] ?? []], 200);
                }
                if ($method === 'POST' || $method === 'PUT') {
                    return Http::response(['success' => true, 'data' => [
                        'ingredient_id' => $request->data()['ingredient_id'] ?? ($matches[2] ?? 'ing-1'),
                        'default_ingredient' => (bool) ($request->data()['default_ingredient'] ?? false),
                        'extra_fee' => (bool) ($request->data()['extra_fee'] ?? false),
                        'custom_fee' => $request->data()['custom_fee'] ?? null,
                    ]], 200);
                }
            }

            if (str_contains($url, '/api/cashier/ingredients')) {
                if ($method === 'GET') {
                    return Http::response(['success' => true, 'data' => $state['cashier_ings'] ?? []], 200);
                }
                if ($method === 'POST') {
                    static $ingredientSeq = 0;
                    $ingredientSeq++;

                    return Http::response(['success' => true, 'data' => ['id' => 'ing-'.$ingredientSeq]], 200);
                }
                if ($method === 'PUT') {
                    return Http::response(['success' => true, 'data' => ['id' => 'ing-keep']], 200);
                }
            }

            if ($method === 'GET' && str_contains($url, '/kitchens')) {
                return Http::response(['data' => []], 200);
            }

            if ($method === 'GET' && str_contains($url, '/ingredients-categories')) {
                return Http::response(['data' => []], 200);
            }

            if ($method === 'POST' && str_contains($url, '/ingredients-categories')) {
                return Http::response(['data' => ['id' => 'ic-1']], 200);
            }

            if ($method === 'GET' && preg_match('#/api/manager/ingredients($|\?)#', $url)) {
                return Http::response(['data' => []], 200);
            }

            if ($method === 'POST' && preg_match('#/api/manager/ingredients$#', $url)) {
                return Http::response(['data' => ['id' => 'ing-mgr']], 200);
            }

            if ($method === 'GET' && preg_match('#/categories$#', $url)) {
                return Http::response(['data' => $state['categories'] ?? []], 200);
            }

            if ($method === 'POST' && preg_match('#/categories$#', $url)) {
                return Http::response(['data' => ['id' => 'cat-1']], 200);
            }

            if ($method === 'PUT' && str_contains($url, '/categories/')) {
                return Http::response(['data' => ['id' => 'cat-1']], 200);
            }

            if ($method === 'GET' && preg_match('#/items(\?|$)#', $url) && ! preg_match('#/items/[^/?]+#', $url)) {
                return Http::response(['data' => $state['items'] ?? []], 200);
            }

            if ($method === 'POST' && preg_match('#/items/bulk$#', $url)) {
                return Http::response(['message' => 'Not found'], 404);
            }

            if ($method === 'POST' && preg_match('#/items$#', $url)) {
                static $itemSeq = 0;
                static $item429 = 0;
                $failBudget = (int) ($state['item_429'] ?? 0);
                if ($item429 < $failBudget) {
                    $item429++;

                    return Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '0']);
                }
                $itemSeq++;

                return Http::response(['data' => ['id' => 'item-'.$itemSeq]], 200);
            }

            if ($method === 'PUT' && preg_match('#/items/([^/?]+)$#', $url, $matches)) {
                return Http::response(['data' => ['id' => $matches[1]]], 200);
            }

            if ($method === 'POST' && preg_match('#/items/([^/?]+)$#', $url, $matches)) {
                return Http::response(['data' => ['id' => $matches[1]]], 200);
            }

            if ($method === 'GET' && preg_match('#/items/([^/?]+)$#', $url, $matches)) {
                return Http::response(['data' => ['id' => $matches[1]]], 200);
            }

            return Http::response(['message' => 'Not found', 'url' => $url], 404);
        });
    }
}
