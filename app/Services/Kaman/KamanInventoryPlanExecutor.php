<?php

declare(strict_types=1);

namespace App\Services\Kaman;

use App\Support\KamanUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class KamanInventoryPlanExecutor
{
    /** @var array<string, string> */
    private array $resolved = [];

    /** @var array<string, string> */
    private array $itemIdsByName = [];

    /** @var array<string, string> */
    private array $inventoryIdsByName = [];

    /** @var array<string, string> */
    private array $inventoryIdsBySku = [];

    private bool $itemsLoaded = false;

    private bool $inventoryLoaded = false;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $email,
        private readonly string $password,
    ) {}

    /**
     * @param  array<string, mixed>  $step
     * @return array{success: bool, status: int, body: mixed, skipped?: bool, message?: string}
     */
    public function executeStep(array $step): array
    {
        $phase = (string) ($step['phase'] ?? '');
        if ($phase === 'login') {
            return [
                'success' => true,
                'status' => 200,
                'skipped' => true,
                'message' => 'Login handled by session token.',
                'body' => ['token_preview' => Str::limit($this->token, 12, '…')],
            ];
        }

        if ($phase === 'create_ingredient_category') {
            $existing = $this->fetchIngredientCategoryId();
            if ($existing !== null) {
                $this->resolved['ingredient_category:raw'] = $existing;

                return [
                    'success' => true,
                    'status' => 200,
                    'skipped' => true,
                    'message' => 'Ingredient category already exists.',
                    'body' => ['id' => $existing],
                ];
            }
        }

        if ($phase === 'fetch_inventory') {
            $this->loadInventory(true);

            return [
                'success' => true,
                'status' => 200,
                'message' => 'Loaded ' . count($this->inventoryIdsByName) . ' inventory name(s) from API.',
                'body' => [
                    'count' => count($this->inventoryIdsByName),
                    'sku_count' => count($this->inventoryIdsBySku),
                ],
            ];
        }

        $http = $step['http'] ?? [];
        if (!is_array($http)) {
            return ['success' => false, 'status' => 0, 'message' => 'Missing HTTP block.', 'body' => null];
        }

        $method = strtoupper((string) ($http['method'] ?? 'POST'));
        $path = $this->resolvePath((string) ($http['path'] ?? ''));
        $body = $this->resolveBody($http['body'] ?? []);

        if ($phase === 'create_inventory' && is_array($body)) {
            $existingId = $this->findExistingInventoryId($body);
            if ($existingId !== null) {
                if (isset($step['save_as']) && is_string($step['save_as'])) {
                    $this->resolved[$step['save_as']] = $existingId;
                }

                return [
                    'success' => true,
                    'status' => 200,
                    'skipped' => true,
                    'message' => 'Inventory item already exists.',
                    'body' => ['id' => $existingId],
                ];
            }
        }

        if ($this->containsUnresolvedPlaceholder($path) || $this->bodyHasUnresolvedPlaceholder($body)) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Unresolved reference — run earlier steps first or check name mapping.',
                'body' => ['path' => $path, 'body' => $body],
            ];
        }

        $url = KamanUrl::join($this->baseUrl, $path);
        $client = Http::timeout(90)->acceptJson()->withToken($this->token);
        if (!config('services.kaman.ssl_verify', false)) {
            $client = $client->withoutVerifying();
        }

        try {
            $response = match ($method) {
                'GET' => $client->get($url),
                'PUT' => $client->put($url, $body),
                'PATCH' => $client->patch($url, $body),
                'DELETE' => $client->delete($url, $body),
                default => $client->post($url, $body),
            };
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => $e->getMessage(),
                'body' => null,
            ];
        }

        $json = $response->json();
        $ok = $response->successful();

        if (!$ok && $phase === 'create_inventory' && is_array($body) && $response->status() === 422) {
            $this->loadInventory(true);
            $existingId = $this->findExistingInventoryId($body);
            if ($existingId !== null) {
                if (isset($step['save_as']) && is_string($step['save_as'])) {
                    $this->resolved[$step['save_as']] = $existingId;
                }

                return [
                    'success' => true,
                    'status' => 200,
                    'skipped' => true,
                    'message' => 'Inventory item already exists (matched after 422).',
                    'body' => ['id' => $existingId],
                ];
            }
        }

        if ($ok && isset($step['save_as']) && is_string($step['save_as'])) {
            $id = $json['data']['id'] ?? $json['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $this->resolved[(string) $step['save_as']] = $id;
                if ($phase === 'create_inventory' && is_array($body)) {
                    $this->rememberInventoryRecord($id, $body);
                }
            }
        }

        return [
            'success' => $ok,
            'status' => $response->status(),
            'message' => $ok ? null : ($json['message'] ?? $response->body()),
            'body' => $json ?? $response->body(),
        ];
    }

    /** @return array<string, string> */
    public function resolvedIds(): array
    {
        return $this->resolved;
    }

    public function mergeResolved(array $resolved): void
    {
        foreach ($resolved as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $this->resolved[$key] = $value;
            }
        }
    }

    private function resolvePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = str_replace('{{today}}', now()->toDateString(), $path);

        return preg_replace_callback(
            '/@([a-z_]+):([^\/]+)/i',
            function (array $m): string {
                $ref = $m[1] . ':' . $m[2];
                if (str_starts_with($ref, 'item:')) {
                    $id = $this->resolveItemId($m[2]);
                    if ($id !== null) {
                        return $id;
                    }
                }
                if (str_starts_with($ref, 'inventory:')) {
                    $id = $this->resolveInventoryId($m[2]);
                    if ($id !== null) {
                        return $id;
                    }
                }

                return $this->resolved[$ref] ?? $m[0];
            },
            $path
        ) ?? $path;
    }

    /**
     * @param  mixed  $body
     * @return mixed
     */
    private function resolveBody(mixed $body): mixed
    {
        if (is_string($body)) {
            return $this->resolveStringRef($body);
        }
        if (!is_array($body)) {
            return $body;
        }

        $out = [];
        foreach ($body as $key => $value) {
            $out[$key] = $this->resolveBody($value);
        }

        return $out;
    }

    private function resolveStringRef(string $value): string
    {
        if ($value === '{{email}}') {
            return $this->email;
        }
        if ($value === '{{password}}') {
            return $this->password;
        }
        if ($value === '{{today}}') {
            return now()->toDateString();
        }
        if (preg_match('/^@([a-z_]+):(.+)$/i', $value, $m)) {
            $ref = $m[1] . ':' . $m[2];
            if (str_starts_with($ref, 'item:')) {
                $id = $this->resolveItemId($m[2]);
                if ($id !== null) {
                    return $id;
                }
            }
            if (str_starts_with($ref, 'inventory:')) {
                $id = $this->resolveInventoryId($m[2]);
                if ($id !== null) {
                    return $id;
                }
            }
            if (str_starts_with($ref, 'supplier:')) {
                return $this->resolved[$ref] ?? $value;
            }

            return $this->resolved[$ref] ?? $value;
        }

        return $value;
    }

    private function resolveItemId(string $normalizedDishKey): ?string
    {
        $this->loadItems();
        $key = $this->normalizeName($normalizedDishKey);

        return $this->itemIdsByName[$key] ?? null;
    }

    private function resolveInventoryId(string $normalizedKey): ?string
    {
        $this->loadInventory();
        $key = $this->normalizeName($normalizedKey);

        return $this->resolved['inventory:' . $normalizedKey]
            ?? $this->resolved['inventory:' . $key]
            ?? $this->inventoryIdsByName[$key]
            ?? null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function findExistingInventoryId(array $body): ?string
    {
        $this->loadInventory();

        foreach (['name_he', 'name_en', 'name_ar'] as $field) {
            if (empty($body[$field]) || !is_string($body[$field])) {
                continue;
            }
            $key = $this->normalizeName($body[$field]);
            if (isset($this->inventoryIdsByName[$key])) {
                return $this->inventoryIdsByName[$key];
            }
        }

        if (!empty($body['sku']) && is_string($body['sku'])) {
            $sku = strtolower(trim($body['sku']));
            if ($sku !== '' && isset($this->inventoryIdsBySku[$sku])) {
                return $this->inventoryIdsBySku[$sku];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function rememberInventoryRecord(string $id, array $body): void
    {
        foreach (['name_he', 'name_en', 'name_ar'] as $field) {
            if (!empty($body[$field]) && is_string($body[$field])) {
                $this->inventoryIdsByName[$this->normalizeName($body[$field])] = $id;
            }
        }
        if (!empty($body['sku']) && is_string($body['sku'])) {
            $sku = strtolower(trim($body['sku']));
            if ($sku !== '') {
                $this->inventoryIdsBySku[$sku] = $id;
            }
        }
    }

    private function loadInventory(bool $force = false): void
    {
        if ($this->inventoryLoaded && !$force) {
            return;
        }
        $this->inventoryLoaded = true;

        $url = KamanUrl::join($this->baseUrl, '/inventory-items');
        $client = Http::timeout(60)->acceptJson()->withToken($this->token);
        if (!config('services.kaman.ssl_verify', false)) {
            $client = $client->withoutVerifying();
        }

        try {
            $response = $client->get($url);
        } catch (ConnectionException) {
            return;
        }

        if (!$response->successful()) {
            return;
        }

        foreach ($response->json('data') ?? [] as $inv) {
            if (!is_array($inv) || empty($inv['id'])) {
                continue;
            }
            $id = (string) $inv['id'];
            if (!empty($inv['sku']) && is_string($inv['sku'])) {
                $sku = strtolower(trim($inv['sku']));
                if ($sku !== '') {
                    $this->inventoryIdsBySku[$sku] = $id;
                }
            }
            foreach (['name_en', 'name_he', 'name_ar'] as $field) {
                if (!empty($inv[$field])) {
                    $this->inventoryIdsByName[$this->normalizeName((string) $inv[$field])] = $id;
                }
            }
        }
    }

    private function loadItems(): void
    {
        if ($this->itemsLoaded) {
            return;
        }
        $this->itemsLoaded = true;

        $url = KamanUrl::join($this->baseUrl, '/items');
        $client = Http::timeout(60)->acceptJson()->withToken($this->token);
        if (!config('services.kaman.ssl_verify', false)) {
            $client = $client->withoutVerifying();
        }

        try {
            $response = $client->get($url);
        } catch (ConnectionException) {
            return;
        }

        if (!$response->successful()) {
            return;
        }

        foreach ($response->json('data') ?? [] as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            foreach (['name_en', 'name_he', 'name_ar'] as $field) {
                if (!empty($item[$field])) {
                    $this->itemIdsByName[$this->normalizeName((string) $item[$field])] = (string) $item['id'];
                }
            }
        }
    }

    private function fetchIngredientCategoryId(): ?string
    {
        $url = KamanUrl::join($this->baseUrl, '/ingredients-categories');
        $client = Http::timeout(60)->acceptJson()->withToken($this->token);
        if (!config('services.kaman.ssl_verify', false)) {
            $client = $client->withoutVerifying();
        }

        try {
            $response = $client->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        foreach ($response->json('data') ?? [] as $cat) {
            if (!is_array($cat) || empty($cat['id'])) {
                continue;
            }
            $label = $this->normalizeName((string) ($cat['name_he'] ?? $cat['name_en'] ?? $cat['name_ar'] ?? ''));
            if (in_array($label, [$this->normalizeName('חומרי גלם'), $this->normalizeName('materials'), $this->normalizeName('مواد')], true)) {
                return (string) $cat['id'];
            }
        }

        return null;
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['״', '׳', '”', '“'], ['"', "'", '"', '"'], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    private function containsUnresolvedPlaceholder(string $path): bool
    {
        return (bool) preg_match('/@[a-z_]+:/i', $path);
    }

    /**
     * @param  mixed  $body
     */
    private function bodyHasUnresolvedPlaceholder(mixed $body): bool
    {
        if (is_string($body)) {
            return str_contains($body, '@');
        }
        if (!is_array($body)) {
            return false;
        }
        foreach ($body as $value) {
            if ($this->bodyHasUnresolvedPlaceholder($value)) {
                return true;
            }
        }

        return false;
    }
}
