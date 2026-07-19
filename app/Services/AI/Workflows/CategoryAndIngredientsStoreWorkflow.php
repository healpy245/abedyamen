<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Support\KamanUrl;

use App\Services\AI\MenuCategoryNameMatcher;
use App\Services\AI\StructuredCategoryBlocksParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Creates ingredients categories first (only names not already on the restaurant), then ingredients for non-empty blocks.
 */
final class CategoryAndIngredientsStoreWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        $restaurantName = trim($payload['restaurant_name'] ?? '');
        $password = $payload['password'] ?? '';
        $description = trim($payload['description'] ?? '');

        $progress = static function (string $step, string $message, array $data = []) use ($onProgress): void {
            $onProgress && $onProgress($step, $message, $data);
        };

        if ($restaurantName === '' || $password === '') {
            return [
                'success' => false,
                'error' => 'Restaurant name and password are required.',
            ];
        }

        $parsed = StructuredCategoryBlocksParser::parseStrict($description);
        if (!$parsed['ok']) {
            return [
                'success' => false,
                'error' => $parsed['error'],
            ];
        }

        $blocks = $parsed['blocks'];

        $labelsOrdered = [];
        $seen = [];
        foreach ($blocks as $b) {
            $label = trim($b['label']);
            if ($label === '') {
                continue;
            }
            if (!isset($seen[$label])) {
                $seen[$label] = true;
                $labelsOrdered[] = $label;
            }
        }

        if ($labelsOrdered === []) {
            return [
                'success' => false,
                'error' => 'No category names found in description.',
            ];
        }

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = KamanUrl::managerApi($subdomain, KamanUrl::tldFromEnvironment($payload['environment'] ?? null));

        try {
            $progress('login', 'Checking existing ingredients categories...', ['subdomain' => $subdomain]);
            $loginEmail = KamanUrl::loginEmail($subdomain, $payload['username'] ?? null);
            $token = $this->kamanLogin($baseUrl, $loginEmail, $password);
            $existingCategories = $this->kamanFetchIngredientsCategories($baseUrl, $token);
            $progress('categories', 'Loaded ' . count($existingCategories) . ' existing ingredients categories', ['count' => count($existingCategories)]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        $labelsToCreate = [];
        $skippedLabels = [];
        foreach ($labelsOrdered as $label) {
            if (MenuCategoryNameMatcher::exists($label, $existingCategories)) {
                $skippedLabels[] = $label;
                $progress('categories', 'Ingredients category already exists — will only add items under it: ' . $label, ['label' => $label]);
            } else {
                $labelsToCreate[] = $label;
            }
        }

        $categoryResult = null;

        if ($labelsToCreate === []) {
            $progress('phase', 'All listed ingredients categories already exist; skipping category creation.', ['skipped' => $skippedLabels]);
            $categoryResult = [
                'success' => true,
                'message' => 'All ingredients categories already existed on this restaurant.',
                'data' => [
                    'categories_skipped' => $skippedLabels,
                    'categories_created' => ['created' => [], 'failed' => []],
                    'categories_failed' => [],
                ],
            ];
        } else {
            $categoryDescription = implode("\n", $labelsToCreate);
            $progress('phase', 'Creating new ingredients categories...', ['count' => count($labelsToCreate), 'skipped_existing' => count($skippedLabels)]);

            $categoryPayload = [
                'restaurant_name' => $restaurantName,
                'password' => $password,
                'description' => $categoryDescription,
                'translate_names' => $payload['translate_names'] ?? true,
            ];

            $categoryResult = app(CategoryIngredientsStoreWorkflow::class)->run($categoryPayload, $onProgress);

            if (!$categoryResult['success']) {
                return $categoryResult;
            }

            $categoryResult['data'] ??= [];
            $categoryResult['data']['categories_skipped'] = $skippedLabels;
        }

        $ingredientsDescription = self::buildStructuredBodyDescription($blocks);

        if ($ingredientsDescription === '') {
            $progress('phase', 'No ingredient lines in non-empty blocks.', []);

            Log::info('CategoryAndIngredientsStoreWorkflow completed (categories only)', [
                'restaurant' => $restaurantName,
                'skipped_categories' => $skippedLabels,
            ]);

            $msg = ($labelsToCreate === [])
                ? 'All ingredients categories already existed. No ingredients to add (empty category bodies).'
                : 'Ingredients categories processed. No ingredients to add (empty category bodies).';

            return [
                'success' => true,
                'message' => $msg,
                'data' => [
                    'phase' => 'categories_only',
                    'categories' => $categoryResult['data'] ?? [],
                ],
            ];
        }

        $progress('phase', 'Creating ingredients for non-empty blocks...', []);

        $ingredientsPayload = [
            'restaurant_name' => $restaurantName,
            'password' => $password,
            'description' => $ingredientsDescription,
            'translate_names' => $payload['translate_names'] ?? true,
        ];

        $ingredientsResult = app(IngredientsStoreWorkflow::class)->run($ingredientsPayload, $onProgress);

        Log::info('CategoryAndIngredientsStoreWorkflow completed', [
            'restaurant' => $restaurantName,
            'ingredients_success' => $ingredientsResult['success'] ?? false,
            'skipped_existing_categories' => $skippedLabels,
        ]);

        return [
            'success' => $ingredientsResult['success'] ?? false,
            'error' => $ingredientsResult['error'] ?? null,
            'message' => ($ingredientsResult['success'] ?? false)
                ? 'Ingredients categories and ingredients processed successfully.'
                : ($ingredientsResult['error'] ?? 'Ingredients creation phase failed.'),
            'data' => [
                'categories' => $categoryResult['data'] ?? [],
                'ingredients' => $ingredientsResult['data'] ?? [],
            ],
        ];
    }

    /**
     * @param  list<array{label: string, body: string}>  $blocks
     */
    private static function buildStructuredBodyDescription(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            $body = trim($b['body']);
            if ($body === '') {
                continue;
            }
            $label = trim($b['label']);
            $parts[] = $label . ' : {' . "\n" . $body . "\n" . '}';
        }

        return implode("\n\n", $parts);
    }

    private function kamanHttp(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout(30)->acceptJson();

        if (!config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    private function kamanLogin(string $baseUrl, string $email, string $password): string
    {
        $response = $this->kamanHttp()->post("{$baseUrl}/login", [
            'email' => $email,
            'password' => $password,
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            $message = $body['message'] ?? $body['error'] ?? $response->body();

            throw new \RuntimeException('Login failed: ' . (is_string($message) ? $message : json_encode($message)));
        }

        $data = $response->json();
        $token = $data['token'] ?? $data['access_token'] ?? $data['data']['token'] ?? null;

        if ($token === null) {
            throw new \RuntimeException('Login response did not contain a token.');
        }

        return $token;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function kamanFetchIngredientsCategories(string $baseUrl, string $token): array
    {
        $response = $this->kamanHttp()
            ->withToken($token)
            ->get("{$baseUrl}/ingredients-categories");

        if (!$response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body();

            throw new \RuntimeException('Failed to fetch ingredients categories: ' . (is_string($message) ? $message : json_encode($message)));
        }

        $data = $response->json();
        $list = $data['data'] ?? $data['categories'] ?? $data['ingredients_categories'] ?? $data;

        if (!is_array($list)) {
            throw new \RuntimeException('Ingredients categories response format is invalid.');
        }

        return $list;
    }

    private function toSubdomain(string $name): string
    {
        $subdomain = strtolower(trim($name));
        $subdomain = preg_replace('/[^a-z0-9\-]/', '-', $subdomain);
        $subdomain = trim($subdomain, '-');
        $subdomain = preg_replace('/-+/', '-', $subdomain);

        return $subdomain ?: 'default';
    }
}
