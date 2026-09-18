<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Haat\KamanMenuWriter;
use App\Services\Haat\KamanPublicMenuScraper;
use App\Support\KamanUrl;
use Illuminate\Console\Command;

final class KamanImportPublicMenuCommand extends Command
{
    protected $signature = 'kaman:import-public-menu
        {--from-url=https://gcp.kaman.rest/menu : Public customer menu URL}
        {--to=tgcp : Destination Kaman subdomain}
        {--to-environment=dev : Destination TLD (dev|rest)}
        {--username= : Destination login email}
        {--password= : Destination password}
        {--no-images : Skip images}';

    protected $description = 'Scrape a public Kaman customer menu and write it to another restaurant';

    public function handle(KamanPublicMenuScraper $scraper, KamanMenuWriter $writer): int
    {
        $fromUrl = trim((string) $this->option('from-url'));
        $to = KamanUrl::normalizeSubdomain((string) $this->option('to'));
        $username = trim((string) $this->option('username'));
        $password = (string) $this->option('password');
        if ($fromUrl === '' || $to === '' || $password === '') {
            $this->error('--from-url, --to, and --password are required.');

            return self::FAILURE;
        }

        $progress = function (string $step, string $message): void {
            $this->line("[{$step}] {$message}");
        };

        $this->info("Scraping {$fromUrl}…");
        $parsed = $scraper->scrape($fromUrl);
        $this->line(sprintf(
            'Scraped %d categories, %d meals, %d addon groups, %d ingredients',
            count($parsed['categories']),
            count($parsed['meals']),
            count($parsed['unique_ingredient_categories']),
            count($parsed['unique_ingredients']),
        ));

        $toBase = KamanUrl::managerApi($to, KamanUrl::tldFromEnvironment((string) $this->option('to-environment')));
        $this->info("Logging into {$toBase} as {$username}…");
        $token = $writer->login($toBase, $username !== '' ? $username : KamanUrl::loginEmail($to), $password);
        $this->info('Writing menu…');
        $report = $writer->write($toBase, $token, $parsed, ! (bool) $this->option('no-images'), $progress);

        $this->line(json_encode([
            'categories_created' => count($report['categories']['created'] ?? []),
            'categories_reused' => count($report['categories']['reused'] ?? []),
            'categories_failed' => count($report['categories']['failed'] ?? []),
            'meals_created' => count($report['meals']['created'] ?? []),
            'meals_updated' => count($report['meals']['updated'] ?? []),
            'meals_reused' => count($report['meals']['reused'] ?? []),
            'meals_failed' => count($report['meals']['failed'] ?? []),
            'ingredient_categories_created' => count($report['ingredient_categories']['created'] ?? []),
            'ingredients_created' => count($report['ingredients']['created'] ?? []),
            'ingredients_failed' => count($report['ingredients']['failed'] ?? []),
            'links_failed' => collect($report['links'] ?? [])->where('failed', '>', 0)->count(),
            'pipeline' => $report['pipeline'] ?? [],
            'success' => $report['success'] ?? false,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');

        if (! ($report['success'] ?? false)) {
            $this->error('Import finished with failures.');
            $fails = array_slice($report['meals']['failed'] ?? [], 0, 8);
            if ($fails !== []) {
                $this->line(json_encode($fails, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');
            }

            return self::FAILURE;
        }

        $this->info('Public menu imported.');

        return self::SUCCESS;
    }
}
