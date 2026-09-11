<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\FormWorkflowRunner;
use App\Support\KamanUrl;
use Illuminate\Console\Command;

final class HaatMenuCopyCommand extends Command
{
    protected $signature = 'haat:copy-menu
        {restaurant_name : Kaman subdomain}
        {--password= : Kaman password}
        {--username= : Kaman username/email}
        {--environment=rest : Kaman TLD (dev|rest)}
        {--json= : Path to HAAT menu JSON}
        {--no-images : Skip downloading HAAT images}
        {--haat-restaurant-id= : HAAT restaurant id (only used when HAAT_MENU_API_URL is set)}';

    protected $description = 'Sync a HAAT menu JSON into Kaman, creating or editing categories, items, ingredients, and meal links';

    public function handle(FormWorkflowRunner $runner): int
    {
        $restaurantName = trim((string) $this->argument('restaurant_name'));
        $password = (string) $this->option('password');
        if ($restaurantName === '' || $password === '') {
            $this->error('restaurant_name and --password are required.');

            return self::FAILURE;
        }

        $payload = [
            'method_type' => 'HAAT Menu Copy',
            'restaurant_name' => KamanUrl::normalizeSubdomain($restaurantName),
            'subdomain' => KamanUrl::normalizeSubdomain($restaurantName),
            'username' => (string) $this->option('username'),
            'password' => $password,
            'environment' => KamanUrl::tldFromEnvironment((string) $this->option('environment')),
            'haat_json_path' => (string) $this->option('json'),
            'no_images' => (bool) $this->option('no-images'),
            'haat_restaurant_id' => (string) $this->option('haat-restaurant-id'),
        ];

        $result = $runner->run('HAAT Menu Copy', $payload, function (string $step, string $message) {
            $this->line("[{$step}] {$message}");
        });

        if (! ($result['success'] ?? false)) {
            $this->error($result['error'] ?? 'HAAT menu copy failed.');
            if (isset($result['data']) && is_array($result['data'])) {
                $this->line(json_encode($this->summarize($result['data']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');
            }

            return self::FAILURE;
        }

        $this->info($result['message'] ?? 'HAAT menu copied.');
        if (isset($result['data']) && is_array($result['data'])) {
            $this->line(json_encode($this->summarize($result['data']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function summarize(array $report): array
    {
        return [
            'categories_created' => count($report['categories']['created'] ?? []),
            'categories_updated' => count($report['categories']['updated'] ?? []),
            'categories_reused' => count($report['categories']['reused'] ?? []),
            'categories_failed' => count($report['categories']['failed'] ?? []),
            'meals_created' => count($report['meals']['created'] ?? []),
            'meals_updated' => count($report['meals']['updated'] ?? []),
            'meals_failed' => count($report['meals']['failed'] ?? []),
            'ingredient_categories_created' => count($report['ingredient_categories']['created'] ?? []),
            'ingredient_categories_updated' => count($report['ingredient_categories']['updated'] ?? []),
            'ingredient_categories_reused' => count($report['ingredient_categories']['reused'] ?? []),
            'ingredients_created' => count($report['ingredients']['created'] ?? []),
            'ingredients_updated' => count($report['ingredients']['updated'] ?? []),
            'ingredients_reused' => count($report['ingredients']['reused'] ?? []),
            'links' => $report['links'] ?? [],
            'discovered_link' => $report['discovered_link'] ?? null,
            'rejected_haat_details' => $report['rejected_haat_details'] ?? [],
        ];
    }
}
