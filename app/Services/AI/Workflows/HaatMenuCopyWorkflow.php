<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Exceptions\FormWorkflowPausedException;
use App\Models\FormWorkflowRun;
use App\Services\Form\FormWorkflowRunService;
use App\Services\Haat\HaatMenuParser;
use App\Services\Haat\KamanHttp;
use App\Services\Haat\KamanMenuWriter;
use App\Support\KamanUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class HaatMenuCopyWorkflow extends AbstractFormWorkflow
{
    public function __construct(
        private readonly HaatMenuParser $parser,
        private readonly KamanMenuWriter $writer,
    ) {
    }

    public function run(array $payload, ?callable $onProgress = null): array
    {
        $restaurantName = trim((string) ($payload['restaurant_name'] ?? $payload['subdomain'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $storedToken = trim((string) ($payload['kaman_token'] ?? ''));
        $progress = static function (string $step, string $message, array $data = []) use ($onProgress): void {
            $onProgress && $onProgress($step, $message, $data);
        };

        if ($restaurantName === '' || ($password === '' && $storedToken === '')) {
            return [
                'success' => false,
                'error' => 'Restaurant name and password are required. Click Login first.',
            ];
        }

        $subdomain = KamanUrl::normalizeSubdomain($restaurantName);
        $baseUrl = trim((string) ($payload['kaman_base_url'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = KamanUrl::managerApi($subdomain, KamanUrl::tldFromEnvironment($payload['environment'] ?? null));
        }
        $withImages = ! filter_var($payload['no_images'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $runId = isset($payload['run_id']) && is_numeric($payload['run_id']) ? (int) $payload['run_id'] : null;
        $generation = isset($payload['worker_generation']) && is_numeric($payload['worker_generation'])
            ? (int) $payload['worker_generation']
            : null;
        KamanHttp::bind($runId, $progress, $generation);

        try {
            $progress('login', 'Connecting to Kaman API...', ['subdomain' => $subdomain]);
            $token = $storedToken;
            if ($token !== '' && $this->writer->tokenIsUsable($baseUrl, $token)) {
                $progress('login', 'Using saved restaurant session', ['status' => 'ok', 'subdomain' => $subdomain]);
            } else {
                if ($password === '') {
                    $progress('login', 'Restaurant session expired. Signing in is required to continue.', ['status' => 'fail']);

                    return [
                        'success' => false,
                        'paused' => true,
                        'needs_login' => true,
                        'message' => 'Restaurant session expired. Sign in, then Continue.',
                        'error' => 'Restaurant session expired. Sign in, then Continue.',
                    ];
                }
                $progress('login', 'Restaurant session expired. Signing in again…', ['status' => 'run', 'subdomain' => $subdomain]);
                $loginEmail = KamanUrl::loginEmail($subdomain, $payload['username'] ?? null);
                $token = $this->writer->login($baseUrl, $loginEmail, $password);
                $progress('login', 'Logged in successfully', ['status' => 'ok', 'subdomain' => $subdomain]);
            }
            $this->rememberRunToken($runId, $token);

            $progress('parse', 'Parsing HAAT menu JSON...', []);
            $parsed = $this->loadAndParse($payload);
            $progress('parse', sprintf(
                'Parsed %d categories, %d meals, %d ingredient categories, %d ingredients',
                count($parsed['categories']),
                count($parsed['meals']),
                count($parsed['unique_ingredient_categories']),
                count($parsed['unique_ingredients']),
            ), [
                'categories' => count($parsed['categories']),
                'meals' => count($parsed['meals']),
                'ingredient_categories' => count($parsed['unique_ingredient_categories']),
                'ingredients' => count($parsed['unique_ingredients']),
            ]);

            $chat = null;
            $apiKey = trim((string) (config('openai.api_key') ?? ''));
            if ($apiKey !== '') {
                $chat = function (string $systemPrompt, string $userPrompt): string {
                    return $this->chat($systemPrompt, $userPrompt, [
                        'temperature' => 0.1,
                        'max_tokens' => 4000,
                    ]);
                };
            }

            $report = $this->writer->write($baseUrl, $token, $parsed, $withImages, $progress, $chat);
            $progress('done', 'HAAT menu sync finished', ['success' => $report['success'] ?? false]);

            Log::info('HaatMenuCopyWorkflow completed', [
                'restaurant' => $restaurantName,
                'success' => $report['success'] ?? false,
                'pipeline' => $report['pipeline'] ?? [],
            ]);

            $success = (bool) ($report['success'] ?? false);

            return [
                'success' => $success,
                'message' => $success
                    ? 'HAAT menu synced to Kaman.'
                    : 'HAAT menu sync finished with failures.',
                'error' => $success ? null : $this->summarizeFailure($report),
                'data' => $report,
            ];
        } catch (FormWorkflowPausedException $e) {
            Log::info('HaatMenuCopyWorkflow paused', [
                'restaurant' => $restaurantName,
                'run_id' => $runId,
            ]);

            return [
                'success' => false,
                'paused' => true,
                'message' => 'Workflow paused. You can continue it from the submissions list.',
                'error' => null,
                'data' => $e->report,
            ];
        } catch (\Throwable $e) {
            Log::error('HaatMenuCopyWorkflow failed', [
                'error' => $e->getMessage(),
                'restaurant' => $restaurantName,
            ]);

            throw $e;
        } finally {
            KamanHttp::unbind();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function loadAndParse(array $payload): array
    {
        if (! empty($payload['haat_json']) && is_string($payload['haat_json'])) {
            return $this->parser->parse($payload['haat_json']);
        }

        $path = trim((string) ($payload['haat_json_path'] ?? $payload['json'] ?? ''));
        if ($path !== '') {
            return $this->parser->parseFile($path);
        }

        $restaurantId = trim((string) ($payload['haat_restaurant_id'] ?? ''));
        $apiUrl = trim((string) config('services.haat.menu_api_url', ''));
        if ($restaurantId !== '' && $apiUrl !== '') {
            return $this->parser->parse($this->fetchHaatMenuJson($apiUrl, $restaurantId));
        }

        throw new \InvalidArgumentException('Upload a HAAT menu JSON file or pass --json= path.');
    }

    private function fetchHaatMenuJson(string $apiUrl, string $restaurantId): string
    {
        $url = str_contains($apiUrl, '{id}')
            ? str_replace('{id}', rawurlencode($restaurantId), $apiUrl)
            : $apiUrl.(str_contains($apiUrl, '?') ? '&' : '?').'restaurant_id='.rawurlencode($restaurantId);

        $http = Http::timeout(60)->acceptJson();
        if (! config('services.haat.ssl_verify', config('services.kaman.ssl_verify', false))) {
            $http = $http->withoutVerifying();
        }

        $response = $http->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('HAAT menu API request failed (HTTP '.$response->status().').');
        }

        $body = $response->body();
        if ($body === '') {
            throw new \RuntimeException('HAAT menu API returned an empty body.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function summarizeFailure(array $report): string
    {
        $parts = [];
        $catFail = count($report['categories']['failed'] ?? []);
        $mealFail = count($report['meals']['failed'] ?? []);
        $linkFail = 0;
        foreach ($report['links'] ?? [] as $link) {
            if (($link['failed'] ?? 0) > 0 || ! empty($link['error'])) {
                $linkFail++;
            }
        }
        if ($catFail > 0) {
            $parts[] = $catFail.' categor'.($catFail === 1 ? 'y' : 'ies');
        }
        if ($mealFail > 0) {
            $parts[] = $mealFail.' meal'.($mealFail === 1 ? '' : 's');
        }
        if ($linkFail > 0) {
            $parts[] = $linkFail.' link batch'.($linkFail === 1 ? '' : 'es');
        }

        return $parts === []
            ? 'HAAT menu copy failed.'
            : 'Failed: '.implode(', ', $parts).'.'.$this->firstErrorSuffix($report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function firstErrorSuffix(array $report): string
    {
        $first = $report['meals']['failed'][0]['error']
            ?? $report['categories']['failed'][0]['error']
            ?? null;
        if ($first === null) {
            foreach ($report['links'] ?? [] as $link) {
                if (! empty($link['error'])) {
                    $first = $link['error'];
                    break;
                }
            }
        }
        if (! is_string($first) || $first === '') {
            return '';
        }

        return ' First error: '.$first;
    }

    private function rememberRunToken(?int $runId, string $token): void
    {
        if ($runId === null || $token === '') {
            return;
        }

        $run = FormWorkflowRun::query()->find($runId);
        if ($run instanceof FormWorkflowRun) {
            app(FormWorkflowRunService::class)->rememberToken($run, $token);
        }
    }
}
