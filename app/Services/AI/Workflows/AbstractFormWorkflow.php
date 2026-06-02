<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Services\AI\Contracts\FormWorkflowContract;
use App\Services\AI\MenuNameLocalization;
use App\Services\AI\StructuredMealsParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractFormWorkflow implements FormWorkflowContract
{
    protected function chat(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        return $this->chatWithMessages([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ], array_merge($options, ['temperature' => $options['temperature'] ?? 0.3]));
    }

    /**
     * Analyze an image with a text prompt (Vision).
     */
    protected function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        $imageUrl = $this->toVisionInput($imagePath, $options['detail'] ?? 'auto');

        return $this->chatWithMessages([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    $imageUrl,
                ],
            ],
        ], array_merge($options, ['model' => $options['model'] ?? config('openai.vision_model', 'gpt-4o')]));
    }

    /**
     * Analyze an image with separate system and user instructions (Vision).
     */
    protected function analyzeImageWithSystem(string $systemPrompt, string $imagePath, string $userPrompt, array $options = []): string
    {
        $imageUrl = $this->toVisionInput($imagePath, $options['detail'] ?? 'auto');

        return $this->chatWithMessages([
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userPrompt],
                    $imageUrl,
                ],
            ],
        ], array_merge($options, ['model' => $options['model'] ?? config('openai.vision_model', 'gpt-4o')]));
    }

    /**
     * Analyze multiple images with a text prompt.
     */
    protected function analyzeImages(array $imagePaths, string $prompt, array $options = []): string
    {
        $content = [['type' => 'text', 'text' => $prompt]];

        foreach ($imagePaths as $path) {
            $content[] = $this->toVisionInput($path, $options['detail'] ?? 'auto');
        }

        return $this->chatWithMessages([['role' => 'user', 'content' => $content]], array_merge($options, ['model' => $options['model'] ?? config('openai.vision_model', 'gpt-4o')]));
    }

    /**
     * Build the vision content block for an image path. Supports local files via base64 data URLs.
     *
     * @return array{type:string,image_url:array{url:string,detail:string}}
     */
    private function toVisionInput(string $imagePath, string $detail = 'auto'): array
    {
        $imageUrl = null;

        if (str_starts_with($imagePath, 'http')) {
            $imageUrl = $imagePath;
        } elseif (str_starts_with($imagePath, 'data:')) {
            $imageUrl = $imagePath;
        } elseif (file_exists($imagePath)) {
            $mime = mime_content_type($imagePath) ?: 'image/png';
            $data = base64_encode(file_get_contents($imagePath) ?: '');
            $imageUrl = 'data:' . $mime . ';base64,' . $data;
        } else {
            $relative = trim(str_replace('\\', '/', str_replace(public_path(), '', $imagePath)), '/');
            $imageUrl = asset($relative);
        }

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => $imageUrl,
                'detail' => $detail,
            ],
        ];
    }

    /**
     * Call OpenAI chat API with custom messages (bypasses OpenAI PHP client).
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  array<string, mixed>  $options
     */
    protected function chatWithMessages(array $messages, array $options = []): string
    {
        $apiKey = trim((string) (config('openai.api_key') ?? ''));
        $baseUrl = config('openai.base_uri') ?: 'https://api.openai.com/v1';
        $model = $options['model'] ?? config('openai.default_model', 'gpt-4o-mini');
        $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);

        $http = Http::withToken($apiKey)
            ->timeout((int) config('openai.request_timeout', 600))
            ->acceptJson()
            ->baseUrl(rtrim($baseUrl, '/'));

        if (config('openai.organization')) {
            $http = $http->withHeaders(['OpenAI-Organization' => config('openai.organization')]);
        }

        if (!$sslVerify) {
            $http = $http->withOptions(['verify' => false]);
        }

        $response = $http->post('/chat/completions', [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ]);

        $body = $response->json() ?? [];
        $status = $response->status();

        if (!$response->successful()) {
            $errorMsg = is_array($body) ? ($body['error']['message'] ?? $body['error'] ?? null) : null;
            $errorMsg = $errorMsg ?? $response->body();
            Log::error('OpenAI API error', ['status' => $status, 'body' => $body]);
            throw new \RuntimeException(
                'OpenAI API error (HTTP ' . $status . '): ' . (is_string($errorMsg) ? $errorMsg : json_encode($errorMsg))
            );
        }

        if (is_array($body) && isset($body['error'])) {
            $errorMsg = $body['error']['message'] ?? $body['error'];
            throw new \RuntimeException(
                'OpenAI returned error: ' . (is_string($errorMsg) ? $errorMsg : json_encode($errorMsg))
            );
        }

        if (!is_array($body) || empty($body['choices']) || !is_array($body['choices'])) {
            $bodyStr = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            Log::error('OpenAI response missing choices', ['body' => $body]);
            throw new \RuntimeException(
                'OpenAI returned unexpected response (no choices). Check logs or debug panel. Response: ' . (strlen($bodyStr) > 1500 ? substr($bodyStr, 0, 1500) . '...' : $bodyStr)
            );
        }

        $content = $body['choices'][0]['message']['content'] ?? '';

        return (string) $content;
    }

    /** Empty or invalid prices become "0.00". */
    protected function normalizeExtractedPrice(string $price): string
    {
        return StructuredMealsParser::normalizePrice($price);
    }

    protected function shouldTranslateNames(array $payload): bool
    {
        return MenuNameLocalization::translateNamesEnabled($payload);
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, array<string, mixed>>
     */
    protected function localizeMenuRecords(array $records, array $payload, ?callable $progress = null): array
    {
        return MenuNameLocalization::apply(
            $records,
            $this->shouldTranslateNames($payload),
            fn (string $system, string $user, array $options = []) => $this->chat($system, $user, $options),
            $progress
        );
    }
}
