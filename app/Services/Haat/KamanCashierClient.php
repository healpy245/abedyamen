<?php

declare(strict_types=1);

namespace App\Services\Haat;

use App\Exceptions\FormWorkflowPausedException;
use App\Support\KamanUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;

final class KamanCashierClient
{
    public function origin(string $managerBaseUrl): string
    {
        return KamanUrl::originFromManagerApi($managerBaseUrl);
    }

    public function base(string $managerBaseUrl): string
    {
        return KamanUrl::cashierApiFromManager($managerBaseUrl);
    }

    public function available(string $managerBaseUrl, string $token): bool
    {
        try {
            $response = KamanHttp::send(fn () => $this->http()->withToken($token)->get($this->base($managerBaseUrl).'/ingredient-categories'));

            return $response->successful();
        } catch (FormWorkflowPausedException $e) {
            throw $e;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function listAll(string $managerBaseUrl, string $token, string $path, array $query = []): array
    {
        $all = [];
        $page = 1;
        $lastPage = 1;
        $base = $this->base($managerBaseUrl).'/'.ltrim($path, '/');

        do {
            $response = KamanHttp::send(fn () => $this->http()->withToken($token)->get($base, array_merge($query, [
                'page' => $page,
                'per_page' => 100,
            ])));
            if (! $response->successful()) {
                break;
            }
            $json = $response->json();
            $all = array_merge($all, $this->unwrapList($json));
            $lastPage = $this->lastPage($json, $page);
            $page++;
        } while ($page <= $lastPage && $page <= 50);

        return $all;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemIngredients(string $managerBaseUrl, string $token, string $itemId): array
    {
        $response = KamanHttp::send(fn () => $this->http()->withToken($token)->get($this->base($managerBaseUrl).'/items/'.$itemId.'/ingredients'));
        if (! $response->successful()) {
            return [];
        }

        return $this->unwrapList($response->json());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function postJson(string $managerBaseUrl, string $token, string $path, array $body): Response
    {
        return KamanHttp::send(fn () => $this->http()->withToken($token)->asJson()->post($this->base($managerBaseUrl).'/'.ltrim($path, '/'), $body));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function putJson(string $managerBaseUrl, string $token, string $path, array $body): Response
    {
        return KamanHttp::send(fn () => $this->http()->withToken($token)->asJson()->put($this->base($managerBaseUrl).'/'.ltrim($path, '/'), $body));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function postIngredient(string $managerBaseUrl, string $token, array $body, ?string $imagePath = null): Response
    {
        $url = $this->base($managerBaseUrl).'/ingredients';
        if ($imagePath && File::exists($imagePath)) {
            $http = $this->http(90)->withToken($token);
            $attach = [];
            foreach ($body as $key => $value) {
                if (is_bool($value)) {
                    $attach[$key] = $value ? '1' : '0';
                } elseif (is_array($value)) {
                    continue;
                } else {
                    $attach[$key] = (string) $value;
                }
            }

            return KamanHttp::send(fn () => $http->attach('image', File::get($imagePath), basename($imagePath))->post($url, $attach));
        }

        return $this->postJson($managerBaseUrl, $token, '/ingredients', $body);
    }

    public function extractId(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }
        $id = $json['data']['id'] ?? $json['id'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return list<array<string, mixed>>
     */
    public function unwrapList(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        $data = $json['data'] ?? $json;
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['items']) && is_array($data['items'])) {
            $data = $data['items'];
        }
        if ($data === [] || ! is_array($data)) {
            return [];
        }
        if (! array_is_list($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function lastPage(mixed $json, int $page): int
    {
        if (! is_array($json)) {
            return $page;
        }
        $pagination = [];
        if (is_array($json['data'] ?? null) && is_array($json['data']['pagination'] ?? null)) {
            $pagination = $json['data']['pagination'];
        } elseif (is_array($json['pagination'] ?? null)) {
            $pagination = $json['pagination'];
        } elseif (is_array($json['meta'] ?? null)) {
            $pagination = $json['meta'];
        }
        $last = $pagination['last_page'] ?? $json['last_page'] ?? null;
        if (is_numeric($last)) {
            return max($page, (int) $last);
        }

        return $page;
    }

    private function http(int $timeout = 30): \Illuminate\Http\Client\PendingRequest
    {
        return KamanHttp::pending($timeout);
    }
}
