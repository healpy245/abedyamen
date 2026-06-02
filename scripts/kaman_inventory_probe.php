<?php

declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$baseUrl = 'https://thex.kaman.dev/api/manager';

$response = Http::withoutVerifying()
    ->timeout(60)
    ->acceptJson()
    ->post("{$baseUrl}/login", [
        'email' => 'thex@kaman.rest',
        'password' => '1234',
    ]);

echo "Login status: {$response->status()}\n";
$login = $response->json();
if (!$response->successful()) {
    echo json_encode($login, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$token = $login['token'] ?? $login['access_token'] ?? ($login['data']['token'] ?? null);
if (!$token) {
    echo "No token in response\n";
    print_r($login);
    exit(1);
}

echo "Token obtained: " . substr((string) $token, 0, 20) . "...\n";

$client = Http::withoutVerifying()->timeout(60)->acceptJson()->withToken($token);
$apiBase = $baseUrl;

foreach (['ingredients', 'items', 'inventory-items', 'suppliers'] as $path) {
    $r = $client->get("{$apiBase}/{$path}");
    echo "\nGET /{$path} => {$r->status()}\n";
    if ($r->successful()) {
        $data = $r->json();
        $list = $data['data'] ?? $data;
        if (is_array($list)) {
            $count = isset($list[0]) ? count($list) : (isset($list['data']) && is_array($list['data']) ? count($list['data']) : 1);
            echo "  count-ish: {$count}\n";
            if (isset($list[0]) && is_array($list[0])) {
                echo "  sample keys: " . implode(', ', array_slice(array_keys($list[0]), 0, 12)) . "\n";
                echo "  sample name: " . ($list[0]['name_he'] ?? $list[0]['name_ar'] ?? $list[0]['name'] ?? 'n/a') . "\n";
            } elseif (isset($list['data'][0])) {
                $row = $list['data'][0];
                echo "  sample keys: " . implode(', ', array_slice(array_keys($row), 0, 12)) . "\n";
            }
        }
    } else {
        echo "  body: " . substr($r->body(), 0, 300) . "\n";
    }
}
