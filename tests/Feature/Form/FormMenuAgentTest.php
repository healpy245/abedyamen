<?php

declare(strict_types=1);

namespace Tests\Feature\Form;

use App\Models\FormWorkflowRun;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FormMenuAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_page_shows_menu_agent_panel(): void
    {
        $user = $this->formUser();

        $this->actingAs($user)
            ->get(route('form.index'))
            ->assertOk()
            ->assertSee(__('form.agent_title'), false)
            ->assertSee('id="menuAgentForm"', false)
            ->assertSee('id="workflowDebugContent"', false)
            ->assertSee(__('form.debug_title'), false)
            ->assertSee('HAAT Menu Copy', false)
            ->assertSee(__('form.haat_submit'), false)
            ->assertDontSee(__('form.select_method_type'), false)
            ->assertDontSee('wf-debug__body hidden', false);
    }

    public function test_chat_requires_restaurant_login(): void
    {
        $user = $this->formUser();

        $this->actingAs($user)
            ->post(route('form.menu-agent.chat'), [
                'messages' => [
                    ['role' => 'user', 'content' => 'Add a meal'],
                ],
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Sign in to the restaurant first (subdomain + Login).');
    }

    public function test_agent_sends_kaman_http_from_chat(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();

        Http::fake([
            '*chat/completions*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'kaman_request',
                                    'arguments' => json_encode([
                                        'method' => 'POST',
                                        'path' => '/items',
                                        'body' => [
                                            'name_en' => 'Family Meal',
                                            'name_ar' => 'عائلي',
                                            'name_he' => 'משפחתי',
                                            'price' => '50.00',
                                            'category_id' => '1',
                                        ],
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => 'Created the Family Meal (عائلي) at 50.00.',
                        ],
                    ]],
                ]),
            'https://alarishe.kaman.rest/*' => Http::response(['id' => 99], 201),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'messages' => [
                    ['role' => 'user', 'content' => 'Add a family meal at 50. Item name is the description.'],
                ],
            ]);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('Created the Family Meal', $body);
        $this->assertStringContainsString('POST /items', $body);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://alarishe.kaman.rest/api/manager/items'
                && $request->method() === 'POST'
                && $request['name_ar'] === 'عائلي'
                && $request->hasHeader('Authorization', 'Bearer kaman-token');
        });
    }

    public function test_agent_rejects_off_host_paths(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();

        Http::fake([
            '*chat/completions*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_evil',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'kaman_request',
                                    'arguments' => json_encode([
                                        'method' => 'GET',
                                        'path' => 'https://evil.example/steal',
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => 'That path is not allowed.',
                        ],
                    ]],
                ]),
            'https://alarishe.kaman.rest/*' => Http::response(['id' => 1], 200),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'messages' => [
                    ['role' => 'user', 'content' => 'Call that URL'],
                ],
            ]);

        $response->assertOk();
        $this->assertStringContainsString('That path is not allowed.', $response->streamedContent());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_agent_accepts_menu_image_upload(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();

        Http::fake([
            '*chat/completions*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => 'I read the photo. Say go and I will create the meals.',
                    ],
                ]],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'messages' => [
                    ['role' => 'user', 'content' => 'Here is the menu'],
                ],
                'attachments' => [
                    UploadedFile::fake()->image('menu.jpg', 40, 40),
                ],
            ]);

        $response->assertOk();
        $this->assertStringContainsString('I read the photo', $response->streamedContent());
    }

    public function test_agent_stores_every_extracted_meal_from_photos(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'chat/completions')) {
                $payload = $request->data();
                if (empty($payload['tools'])) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'content' => json_encode([
                                    'categories' => [
                                        [
                                            'name_en' => 'Meats',
                                            'name_ar' => 'لحوم',
                                            'name_he' => 'בשרים',
                                            'meals' => [
                                                [
                                                    'name_en' => 'Lamb Head',
                                                    'name_ar' => 'راس خاروف',
                                                    'name_he' => 'ראש כבש',
                                                    'price' => '100',
                                                    'description_en' => 'Lamb Head',
                                                    'description_ar' => 'راس خاروف',
                                                    'description_he' => 'ראש כבש',
                                                ],
                                                [
                                                    'name_en' => 'Mokhach',
                                                    'name_ar' => 'مخاخ',
                                                    'name_he' => 'מוחח',
                                                    'price' => '70',
                                                    'description_en' => 'Mokhach',
                                                    'description_ar' => 'مخاخ',
                                                    'description_he' => 'מוחח',
                                                ],
                                            ],
                                        ],
                                    ],
                                ]),
                            ],
                        ]],
                    ]);
                }

                return Http::response([
                    'choices' => [['message' => ['content' => 'unused']]],
                ]);
            }

            if (str_contains($url, '/categories') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/categories') && $request->method() === 'POST') {
                return Http::response(['id' => 7], 201);
            }
            if (str_contains($url, '/items') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/items') && $request->method() === 'POST') {
                return Http::response(['id' => 99], 201);
            }

            return Http::response(['ok' => true], 200);
        });

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'conversation_id' => '11111111-1111-1111-1111-111111111111',
                'messages' => [
                    ['role' => 'user', 'content' => 'analyze images very well and store categories + meals'],
                ],
                'attachments' => [
                    UploadedFile::fake()->image('menu-1.jpg', 40, 40),
                ],
            ]);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('2 meals', $body);
        $this->assertStringContainsString('راس خاروف', $body);
        $this->assertStringContainsString('مخاخ', $body);
        $this->assertStringNotContainsString('Please upload', $body);

        Http::assertSent(fn ($request) => $request->url() === 'https://alarishe.kaman.rest/api/manager/categories'
            && $request->method() === 'POST');
        Http::assertSent(fn ($request) => $request->url() === 'https://alarishe.kaman.rest/api/manager/items'
            && $request->method() === 'POST'
            && $request['name_en'] === 'Lamb Head');
        Http::assertSent(fn ($request) => $request->url() === 'https://alarishe.kaman.rest/api/manager/items'
            && $request->method() === 'POST'
            && $request['name_en'] === 'Mokhach');
    }

    public function test_follow_up_store_all_does_not_ask_to_reupload(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();
        $conversationId = '22222222-2222-2222-2222-222222222222';

        app(\App\Services\Form\FormMenuAgentMemory::class)->put($user->id, $conversationId, [
            'attachments' => [],
            'menu_draft' => [
                'categories' => [
                    [
                        'name_en' => 'Drinks',
                        'name_ar' => 'مشروبات',
                        'name_he' => 'משקאות',
                        'meals' => [
                            [
                                'name_en' => 'Cola',
                                'name_ar' => 'كولا',
                                'name_he' => 'קולה',
                                'price' => '8',
                                'description_en' => 'Cola',
                                'description_ar' => 'كولا',
                                'description_he' => 'קולה',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/categories') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/categories') && $request->method() === 'POST') {
                return Http::response(['id' => 3], 201);
            }
            if (str_contains($url, '/items') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/items') && $request->method() === 'POST') {
                return Http::response(['id' => 44], 201);
            }

            return Http::response(['choices' => [['message' => ['content' => 'unused']]]], 200);
        });

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'conversation_id' => $conversationId,
                'messages' => [
                    ['role' => 'user', 'content' => 'store all meals in the images'],
                ],
            ]);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('كولا', $body);
        $this->assertStringContainsString('photos in this chat', $body);
        $this->assertStringNotContainsString('upload them again', $body);
        $this->assertStringNotContainsString('Please upload the images', $body);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://alarishe.kaman.rest/api/manager/items'
                && $request->method() === 'POST'
                && $this->httpField($request, 'name_en') === 'Cola';
        });

        $this->assertSame(1, FormWorkflowRun::query()->where('method_type', 'Menu Agent')->count());
        $this->assertStringContainsString('"event":"run"', $body);
        $this->assertStringContainsString('"event":"step"', $body);
    }

    public function test_agent_skips_existing_meals_instead_of_posting_duplicates(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();
        $conversationId = '33333333-3333-3333-3333-333333333333';

        app(\App\Services\Form\FormMenuAgentMemory::class)->put($user->id, $conversationId, [
            'attachments' => [],
            'menu_draft' => [
                'categories' => [
                    [
                        'name_en' => 'Drinks',
                        'name_ar' => 'مشروبات',
                        'name_he' => 'משקאות',
                        'meals' => [
                            [
                                'name_en' => 'Cola',
                                'name_ar' => 'كولا',
                                'name_he' => 'קולה',
                                'price' => '8',
                                'description_en' => 'Cola',
                                'description_ar' => 'كولا',
                                'description_he' => 'קולה',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/categories') && $request->method() === 'GET') {
                return Http::response(['data' => [[
                    'id' => 3,
                    'name_en' => 'Drinks',
                    'name_ar' => 'المشروبات',
                    'name_he' => 'משקאות',
                ]]], 200);
            }
            if (str_contains($url, '/items') && $request->method() === 'GET') {
                return Http::response(['data' => [[
                    'id' => 44,
                    'category_id' => 3,
                    'name_en' => 'Coca Cola',
                    'name_ar' => 'كولا',
                    'name_he' => 'קולה',
                ]]], 200);
            }
            if (str_contains($url, '/ingredients') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['choices' => [['message' => ['content' => 'unused']]]], 200);
        });

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'conversation_id' => $conversationId,
                'messages' => [
                    ['role' => 'user', 'content' => 'store all meals'],
                ],
            ]);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('already on the dashboard', $body);
        $this->assertStringNotContainsString('Failed:', $body);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/items') && $request->method() === 'POST');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/categories') && $request->method() === 'POST');
    }

    public function test_agent_treats_duplicate_api_errors_as_skipped(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();
        $conversationId = '44444444-4444-4444-4444-444444444444';

        app(\App\Services\Form\FormMenuAgentMemory::class)->put($user->id, $conversationId, [
            'attachments' => [],
            'menu_draft' => [
                'categories' => [
                    [
                        'name_en' => 'Drinks',
                        'name_ar' => 'مشروبات',
                        'name_he' => 'משקאות',
                        'meals' => [
                            [
                                'name_en' => 'Sprite',
                                'name_ar' => 'سبرايت',
                                'name_he' => 'ספרייט',
                                'price' => '8',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/categories') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/categories') && $request->method() === 'POST') {
                return Http::response(['id' => 9], 201);
            }
            if (str_contains($url, '/items') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/items') && $request->method() === 'POST') {
                return Http::response(['message' => 'The name has already been taken.'], 422);
            }

            return Http::response(['ok' => true], 200);
        });

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'conversation_id' => $conversationId,
                'messages' => [
                    ['role' => 'user', 'content' => 'store all meals'],
                ],
            ]);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('already on the dashboard', $body);
        $this->assertStringNotContainsString('Failed:', $body);
    }

    public function test_agent_deletes_duplicate_meals_when_asked(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/items') && $request->method() === 'GET') {
                return Http::response(['data' => [
                    ['id' => 10, 'name_en' => 'Cola', 'name_ar' => 'كولا', 'name_he' => 'קולה'],
                    ['id' => 22, 'name_en' => 'Cola', 'name_ar' => 'كولا', 'name_he' => 'קולה'],
                ]], 200);
            }
            if (str_contains($url, '/categories') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/ingredients') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }
            if (preg_match('#/items/22$#', $url) && $request->method() === 'DELETE') {
                return Http::response(['ok' => true], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'messages' => [
                    ['role' => 'user', 'content' => 'delete the duplicates'],
                ],
            ]);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('Deleted 1 duplicate meals', $body);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://alarishe.kaman.rest/api/manager/items/22');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/items/10'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    public function test_agent_attaches_catalog_image_when_storing_known_drinks(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();
        $conversationId = '55555555-5555-5555-5555-555555555555';
        $dir = public_path('ColdDrinks');
        File::ensureDirectoryExists($dir);
        $imagePath = $dir.DIRECTORY_SEPARATOR.'cola.png';
        $createdImage = ! is_file($imagePath);
        if ($createdImage) {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
            file_put_contents($imagePath, $png);
        }

        app(\App\Services\Form\FormMenuAgentMemory::class)->put($user->id, $conversationId, [
            'attachments' => [],
            'menu_draft' => [
                'categories' => [
                    [
                        'name_en' => 'Drinks',
                        'name_ar' => 'مشروبات',
                        'name_he' => 'משקאות',
                        'meals' => [
                            [
                                'name_en' => 'Cola',
                                'name_ar' => 'كولا',
                                'name_he' => 'קולה',
                                'price' => '8',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        try {
            Http::fake(function ($request) {
                $url = $request->url();
                if (str_contains($url, '/categories') && $request->method() === 'GET') {
                    return Http::response(['data' => []], 200);
                }
                if (str_contains($url, '/categories') && $request->method() === 'POST') {
                    return Http::response(['id' => 3], 201);
                }
                if (str_contains($url, '/items') && $request->method() === 'GET') {
                    return Http::response(['data' => []], 200);
                }
                if (str_contains($url, '/items') && $request->method() === 'POST') {
                    return Http::response(['id' => 44], 201);
                }

                return Http::response(['ok' => true], 200);
            });

            $response = $this->actingAs($user)
                ->withSession([
                    'full_ai_kaman_token' => 'kaman-token',
                    'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
                ])
                ->post(route('form.menu-agent.chat'), [
                    'conversation_id' => $conversationId,
                    'messages' => [
                        ['role' => 'user', 'content' => 'store all meals'],
                    ],
                ]);

            $response->assertOk();
            $response->streamedContent();
            Http::assertSent(function ($request) {
                return $request->url() === 'https://alarishe.kaman.rest/api/manager/items'
                    && $request->method() === 'POST'
                    && $request->isMultipart()
                    && $this->httpField($request, 'name_en') === 'Cola';
            });
        } finally {
            if ($createdImage && is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    public function test_agent_retries_item_create_without_image_after_422(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();
        $conversationId = '66666666-6666-6666-6666-666666666666';
        $dir = public_path('ColdDrinks');
        File::ensureDirectoryExists($dir);
        $imagePath = $dir.DIRECTORY_SEPARATOR.'sprite.png';
        $createdImage = ! is_file($imagePath);
        if ($createdImage) {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
            file_put_contents($imagePath, $png);
        }

        app(\App\Services\Form\FormMenuAgentMemory::class)->put($user->id, $conversationId, [
            'attachments' => [],
            'menu_draft' => [
                'categories' => [
                    [
                        'name_en' => 'Drinks',
                        'name_ar' => 'مشروبات',
                        'name_he' => 'משקאות',
                        'meals' => [
                            [
                                'name_en' => 'Sprite',
                                'name_ar' => 'سبرايت',
                                'name_he' => 'ספרייט',
                                'price' => '8',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        try {
            $itemPosts = 0;
            Http::fake(function ($request) use (&$itemPosts) {
                $url = $request->url();
                if (str_contains($url, '/categories') && $request->method() === 'GET') {
                    return Http::response(['data' => []], 200);
                }
                if (str_contains($url, '/categories') && $request->method() === 'POST') {
                    return Http::response(['id' => 3], 201);
                }
                if (str_contains($url, '/items') && $request->method() === 'GET') {
                    return Http::response(['data' => []], 200);
                }
                if (str_contains($url, '/items') && $request->method() === 'POST') {
                    $itemPosts++;
                    if ($request->isMultipart()) {
                        return Http::response([
                            'message' => 'The image failed to upload.',
                            'errors' => ['image' => ['The image must be a file of type: jpeg, jpg.']],
                        ], 422);
                    }

                    return Http::response(['id' => 55], 201);
                }

                return Http::response(['ok' => true], 200);
            });

            $response = $this->actingAs($user)
                ->withSession([
                    'full_ai_kaman_token' => 'kaman-token',
                    'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
                ])
                ->post(route('form.menu-agent.chat'), [
                    'conversation_id' => $conversationId,
                    'messages' => [
                        ['role' => 'user', 'content' => 'store all meals'],
                    ],
                ]);

            $response->assertOk();
            $body = $response->streamedContent();
            $this->assertSame(2, $itemPosts);
            $this->assertStringContainsString('1 meals stored', $body);
            Http::assertSent(function ($request) {
                return $request->url() === 'https://alarishe.kaman.rest/api/manager/items'
                    && $request->method() === 'POST'
                    && ! $request->isMultipart()
                    && $this->httpField($request, 'name_en') === 'Sprite';
            });
        } finally {
            if ($createdImage && is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    public function test_agent_retries_openai_rate_limit_then_succeeds(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();

        Http::fake([
            '*chat/completions*' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'Rate limit reached for gpt-4o in organization org-test on tokens per min (TPM): Limit 30000, Used 22719, Requested 16558. Please try again in 18.554s.',
                    ],
                ], 429)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => 'There are 9 categories on the dashboard.',
                        ],
                    ]],
                ]),
            'https://alarishe.kaman.rest/*' => Http::response(['data' => []], 200),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'full_ai_kaman_token' => 'kaman-token',
                'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
            ])
            ->post(route('form.menu-agent.chat'), [
                'messages' => [
                    ['role' => 'user', 'content' => 'How many categories are on the dashboard?'],
                ],
            ]);

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('There are 9 categories', $body);
        $this->assertStringContainsString('OpenAI is busy', $body);
        $this->assertStringNotContainsString('Rate limit reached for gpt-4o', $body);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'), 2);
    }

    public function test_inspect_returns_stored_conversation_and_reuses_the_same_run(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = $this->formUser();
        $conversationId = '11111111-1111-4111-8111-111111111111';

        Http::fake([
            '*chat/completions*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => 'I stored the family meal.',
                        ],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => 'There are 12 meals now.',
                        ],
                    ]],
                ]),
            'https://alarishe.kaman.rest/*' => Http::response(['data' => []], 200),
        ]);

        $session = [
            'full_ai_kaman_token' => 'kaman-token',
            'full_ai_kaman_base_url' => 'https://alarishe.kaman.rest/api/manager',
        ];

        $this->actingAs($user)
            ->withSession($session)
            ->post(route('form.menu-agent.chat'), [
                'conversation_id' => $conversationId,
                'messages' => [
                    ['role' => 'user', 'content' => 'Add a family meal at 50.'],
                ],
            ])
            ->assertOk()
            ->streamedContent();

        $run = FormWorkflowRun::query()->where('method_type', 'Menu Agent')->sole();
        $this->assertSame('Add a family meal at 50.', $run->payload['title'] ?? $run->result['title'] ?? null);

        $this->actingAs($user)
            ->getJson(route('form.runs.show', $run))
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversationId)
            ->assertJsonPath('conversation.messages.0.role', 'user')
            ->assertJsonPath('conversation.messages.0.content', 'Add a family meal at 50.')
            ->assertJsonPath('conversation.messages.1.role', 'assistant')
            ->assertJsonPath('conversation.messages.1.content', 'I stored the family meal.')
            ->assertJsonPath('run.has_conversation', true);

        $this->actingAs($user)
            ->withSession($session)
            ->post(route('form.menu-agent.chat'), [
                'conversation_id' => $conversationId,
                'messages' => [
                    ['role' => 'user', 'content' => 'Add a family meal at 50.'],
                    ['role' => 'assistant', 'content' => 'I stored the family meal.'],
                    ['role' => 'user', 'content' => 'How many meals now?'],
                ],
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertSame(1, FormWorkflowRun::query()->where('method_type', 'Menu Agent')->count());
        $run->refresh();
        $this->assertSame('There are 12 meals now.', $run->result['messages'][3]['content'] ?? null);
    }

    public function test_inspect_falls_back_to_stored_reply_when_messages_missing(): void
    {
        $user = $this->formUser();
        $run = FormWorkflowRun::query()->create([
            'user_id' => $user->id,
            'method_type' => 'Menu Agent',
            'subdomain' => 'alarishetest',
            'environment' => 'rest',
            'status' => FormWorkflowRun::STATUS_COMPLETED,
            'payload' => ['conversation_id' => '22222222-2222-4222-8222-222222222222'],
            'result' => ['reply' => 'Stored 6 meals.'],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('form.runs.show', $run))
            ->assertOk()
            ->assertJsonPath('conversation.id', '22222222-2222-4222-8222-222222222222')
            ->assertJsonPath('conversation.messages.0.role', 'assistant')
            ->assertJsonPath('conversation.messages.0.content', 'Stored 6 meals.');
    }

    private function httpField($request, string $key): ?string
    {
        $data = $request->data();
        if (isset($data[$key]) && ! is_array($data[$key])) {
            return (string) $data[$key];
        }
        foreach ($data as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $key) {
                $value = $part['contents'] ?? null;

                return is_scalar($value) ? (string) $value : null;
            }
        }

        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : null;
    }

    private function formUser(): User
    {
        $this->seed(WorkspaceUserSeeder::class);

        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }
}
