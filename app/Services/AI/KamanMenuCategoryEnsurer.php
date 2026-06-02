<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Support\KamanUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Creates menu categories on Kaman when block labels are not found on the restaurant.
 */
final class KamanMenuCategoryEnsurer
{
    /**
     * @param  list<string>  $labels  Category names from description blocks
     * @param  array<int, array<string, mixed>>  $categories  Existing categories; new ones are appended in place
     * @param  callable(string, string, array): void|null  $progress
     * @return array{created: list<string>, failed: list<array{label: string, error: string}>}
     */
    public static function ensureLabelsExist(
        string $baseUrl,
        string $token,
        array $labels,
        array &$categories,
        bool $translateNames = true,
        ?callable $chat = null,
        ?callable $progress = null,
    ): array {
        $created = [];
        $failed = [];
        $seen = [];

        $http = Http::timeout(30)->acceptJson()->withToken($token);
        if (!config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }

        foreach ($labels as $label) {
            $label = trim($label);
            if ($label === '' || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;

            if (MenuCategoryNameMatcher::exists($label, $categories)) {
                continue;
            }

            $progress && $progress('category', 'Creating category: ' . $label, ['label' => $label]);

            $body = MenuNameLocalization::namesForLabel($label, $translateNames, $chat);

            try {
                $response = $http->post(KamanUrl::join($baseUrl, '/categories'), $body);
            } catch (\Throwable $e) {
                $failed[] = ['label' => $label, 'error' => $e->getMessage()];
                Log::warning('KamanMenuCategoryEnsurer request failed', ['label' => $label, 'error' => $e->getMessage()]);
                continue;
            }

            if ($response->successful()) {
                $data = $response->json();
                $id = $data['data']['id'] ?? $data['id'] ?? $data['category']['id'] ?? null;
                $categories[] = array_merge([
                    'id' => $id,
                    'name' => $body['name_en'] ?: $label,
                ], $body);
                $created[] = $label;
                $progress && $progress('category', 'Created category: ' . $label, ['label' => $label, 'id' => $id]);
            } else {
                $message = $response->json('message') ?? $response->json('error') ?? $response->body();
                $error = is_string($message) ? $message : json_encode($message);
                $failed[] = ['label' => $label, 'error' => $error];
                Log::warning('KamanMenuCategoryEnsurer creation failed', [
                    'label' => $label,
                    'status' => $response->status(),
                    'response' => $error,
                ]);
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * @return list<string>
     */
    public static function labelsFromBlocks(array $blocks): array
    {
        $labels = [];
        $seen = [];

        foreach ($blocks as $block) {
            $label = trim($block['label'] ?? '');
            $body = trim($block['body'] ?? '');
            if ($label === '' || $body === '') {
                continue;
            }
            if (!isset($seen[$label])) {
                $seen[$label] = true;
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  callable(string, string, array): void|null  $progress
     */
    public static function ensureFromDescription(
        string $baseUrl,
        string $token,
        string $description,
        array &$categories,
        array $payload = [],
        ?callable $chat = null,
        ?callable $progress = null,
    ): void {
        $parsed = StructuredCategoryBlocksParser::parseStrict($description);
        if (!$parsed['ok']) {
            return;
        }

        $labels = self::labelsFromBlocks($parsed['blocks']);
        if ($labels === []) {
            return;
        }

        $translateNames = MenuNameLocalization::translateNamesEnabled($payload);
        $result = self::ensureLabelsExist($baseUrl, $token, $labels, $categories, $translateNames, $chat, $progress);

        if ($result['failed'] !== []) {
            $first = $result['failed'][0];
            throw new \RuntimeException(
                'Failed to create category "' . $first['label'] . '": ' . $first['error']
            );
        }
    }
}
