<?php

declare(strict_types=1);

namespace App\Services\KamanAgents;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class KamanAgentsApiClient
{
    private const TOKEN_CACHE_KEY = 'kaman_agents_api_tokens';

    private string $baseUrl;

    private string $username;

    private string $password;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.kaman_agents.base_url', ''), '/');
        $this->username = trim((string) config('services.kaman_agents.username', ''));
        $this->password = (string) config('services.kaman_agents.password', '');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAssignableUsers(): array
    {
        $response = $this->authenticatedRequest()->get($this->url('/api/users/assignable'));

        if (!$response->successful()) {
            throw new RuntimeException('Failed to load assignable users: HTTP ' . $response->status());
        }

        $body = $response->json();
        if (!is_array($body)) {
            return [];
        }

        return array_values(array_filter($body, 'is_array'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllUsers(): array
    {
        $users = [];
        $page = 1;

        do {
            $response = $this->authenticatedRequest()->get($this->url('/api/users'), [
                'page' => $page,
                'pageSize' => 100,
            ]);

            if (!$response->successful()) {
                throw new RuntimeException('Failed to load users: HTTP ' . $response->status());
            }

            $body = $response->json();
            if (!is_array($body)) {
                break;
            }

            $chunk = $body['data'] ?? $body;
            if (!is_array($chunk)) {
                break;
            }

            foreach ($chunk as $user) {
                if (is_array($user)) {
                    $users[] = $user;
                }
            }

            $totalPages = (int) ($body['meta']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $users;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllClients(): array
    {
        $clients = [];
        $page = 1;

        do {
            $response = $this->authenticatedRequest()->get($this->url('/api/clients'), [
                'page' => $page,
                'pageSize' => 100,
            ]);

            if (!$response->successful()) {
                throw new RuntimeException('Failed to load clients: HTTP ' . $response->status());
            }

            $body = $response->json();
            if (!is_array($body)) {
                break;
            }

            $chunk = $body['data'] ?? $body;
            if (!is_array($chunk)) {
                break;
            }

            foreach ($chunk as $client) {
                if (is_array($client)) {
                    $clients[] = $client;
                }
            }

            $totalPages = (int) ($body['meta']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $clients;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getIncomingTasks(): array
    {
        $response = $this->authenticatedRequest()->get($this->url('/api/tasks'), [
            'box' => 'incoming',
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Failed to load tasks: HTTP ' . $response->status());
        }

        $body = $response->json();
        if (!is_array($body)) {
            return [];
        }

        return array_values(array_filter($body, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    public function createTask(
        string $type,
        string $title,
        string $description,
        string $toUserId,
        ?string $clientId = null,
    ): array {
        $payload = [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'toUserId' => $toUserId,
        ];

        if ($clientId !== null && $clientId !== '') {
            $payload['clientId'] = $clientId;
        }

        $response = $this->authenticatedRequest()
            ->asMultipart()
            ->post($this->url('/api/tasks'), $this->multipartFields($payload));

        if (!$response->successful()) {
            $message = $response->json('message') ?? $response->body();
            throw new RuntimeException('Failed to create task: HTTP ' . $response->status() . ' — ' . $message);
        }

        $body = $response->json();
        if (!is_array($body)) {
            throw new RuntimeException('Task created but response was invalid.');
        }

        return $body;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<int, array{name:string,contents:string}>
     */
    private function multipartFields(array $fields): array
    {
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = [
                'name' => $name,
                'contents' => $value,
            ];
        }

        return $multipart;
    }

    private function authenticatedRequest(): PendingRequest
    {
        return $this->httpClient()
            ->withToken($this->accessToken());
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_array($cached) && is_string($cached['accessToken'] ?? null) && $cached['accessToken'] !== '') {
            return $cached['accessToken'];
        }

        return $this->login();
    }

    private function login(): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Kaman Agents API is not configured.');
        }

        try {
            $response = $this->httpClient()->post($this->url('/api/auth/login'), [
                'username' => $this->username,
                'password' => $this->password,
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to reach Kaman Agents API: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            throw new RuntimeException('Kaman Agents login failed: HTTP ' . $response->status());
        }

        $body = $response->json();
        if (!is_array($body) || !is_string($body['accessToken'] ?? null) || $body['accessToken'] === '') {
            throw new RuntimeException('Kaman Agents login returned an invalid token payload.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, [
            'accessToken' => $body['accessToken'],
            'refreshToken' => $body['refreshToken'] ?? null,
        ], now()->addMinutes(12));

        return $body['accessToken'];
    }

    private function httpClient(): PendingRequest
    {
        $client = Http::timeout(30)->acceptJson();

        if (!config('services.kaman_agents.ssl_verify', true)) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }
}
