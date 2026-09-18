<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Haat\KamanHttp;
use App\Services\Haat\KamanMenuWriter;
use App\Support\KamanUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class KamanAddNamedMealsCommand extends Command
{
    protected $signature = 'kaman:add-named-meals
        {restaurant=gcp : Kaman subdomain}
        {--environment=rest : TLD (dev|rest)}
        {--username= : Login email}
        {--password= : Login password}
        {--meals= : JSON list of {category,name,price,description?}}
        {--meals-file= : Path to JSON meals file}';

    protected $description = 'Create specific meals on a Kaman restaurant without syncing the rest of the menu';

    public function handle(KamanMenuWriter $writer): int
    {
        $restaurant = KamanUrl::normalizeSubdomain((string) $this->argument('restaurant'));
        $password = (string) $this->option('password');
        $mealsFile = trim((string) $this->option('meals-file'));
        $mealsJson = $mealsFile !== '' && is_file($mealsFile)
            ? (string) file_get_contents($mealsFile)
            : (string) $this->option('meals');
        $meals = json_decode($mealsJson, true);
        if ($restaurant === '' || $password === '' || ! is_array($meals) || $meals === []) {
            $this->error('restaurant, --password, and --meals JSON are required.');

            return self::FAILURE;
        }

        $baseUrl = KamanUrl::managerApi($restaurant, KamanUrl::tldFromEnvironment((string) $this->option('environment')));
        $email = KamanUrl::loginEmail($restaurant, $this->option('username'));
        $this->info("Logging into {$baseUrl} as {$email}…");
        $token = $writer->login($baseUrl, $email, $password);

        $categories = $this->listCategories($baseUrl, $token);
        $created = [];
        $failed = [];

        foreach ($meals as $meal) {
            if (! is_array($meal)) {
                continue;
            }
            $categoryName = trim((string) ($meal['category'] ?? ''));
            $name = trim((string) ($meal['name'] ?? $meal['name_ar'] ?? ''));
            $nameEn = trim((string) ($meal['name_en'] ?? ''));
            $nameHe = trim((string) ($meal['name_he'] ?? ''));
            $price = number_format((float) ($meal['price'] ?? 0), 2, '.', '');
            $description = trim((string) ($meal['description'] ?? $meal['description_ar'] ?? ''));
            $descriptionEn = trim((string) ($meal['description_en'] ?? ''));
            $descriptionHe = trim((string) ($meal['description_he'] ?? ''));
            if ($description === '') {
                $description = $name;
            }
            if ($nameEn === '') {
                $nameEn = $name;
            }
            if ($nameHe === '') {
                $nameHe = $name;
            }
            if ($descriptionEn === '') {
                $descriptionEn = $description;
            }
            if ($descriptionHe === '') {
                $descriptionHe = $description;
            }
            $categoryId = $this->categoryId($categories, $categoryName);
            if ($categoryId === null || $name === '') {
                $failed[] = ['name' => $name, 'error' => 'Category not found: '.$categoryName];
                $this->error("Skip {$name}: category {$categoryName} not found");
                continue;
            }

            $body = [
                'name_ar' => $name,
                'name_en' => $nameEn,
                'name_he' => $nameHe,
                'description_ar' => $description,
                'description_en' => $descriptionEn,
                'description_he' => $descriptionHe,
                'price' => $price,
                'category_id' => $categoryId,
                'status' => '1',
            ];
            $response = KamanHttp::send(fn () => $this->http()->withToken($token)->asJson()->post($baseUrl.'/items', $body));
            if (! $response->successful()) {
                $response = KamanHttp::send(fn () => $this->http()->withToken($token)->asForm()->post($baseUrl.'/items', $body));
            }
            if (! $response->successful()) {
                $failed[] = ['name' => $name, 'error' => $response->body()];
                $this->error("FAILED {$name}: ".$response->status().' '.$response->body());
                continue;
            }
            $id = $response->json('data.id') ?? $response->json('id') ?? $response->json('data.item.id');
            $created[] = ['name' => $name, 'id' => $id, 'category' => $categoryName, 'price' => $price];
            $this->info("Created {$name} ({$price}) in {$categoryName} id={$id}");
        }

        $this->line(json_encode(['created' => $created, 'failed' => $failed], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listCategories(string $baseUrl, string $token): array
    {
        $all = [];
        for ($page = 1; $page <= 20; $page++) {
            $response = KamanHttp::send(fn () => $this->http()->withToken($token)->get($baseUrl.'/categories', ['page' => $page]));
            if (! $response->successful()) {
                break;
            }
            $json = $response->json();
            $rows = $json['data'] ?? $json;
            if (is_array($rows) && isset($rows['data']) && is_array($rows['data'])) {
                $rows = $rows['data'];
            }
            if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
                break;
            }
            $all = array_merge($all, array_filter($rows, 'is_array'));
            $last = $json['meta']['last_page'] ?? $json['last_page'] ?? $page;
            if ($page >= (int) $last) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     */
    private function categoryId(array $categories, string $name): ?string
    {
        $want = $this->norm($name);
        foreach ($categories as $category) {
            foreach (['name_ar', 'name_en', 'name_he', 'name'] as $field) {
                if ($this->norm((string) ($category[$field] ?? '')) === $want) {
                    $id = $category['id'] ?? null;

                    return $id === null || $id === '' ? null : (string) $id;
                }
            }
        }

        return null;
    }

    private function norm(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return str_replace(['ة', 'ه', 'أ', 'إ', 'آ', 'ى', 'ؤ', 'ئ'], ['ه', 'ه', 'ا', 'ا', 'ا', 'ي', 'و', 'ي'], $value);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout(30)->acceptJson();
        if (! config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }
}
