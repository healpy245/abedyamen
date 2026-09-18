<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Haat\KamanMenuSnapshotParser;
use App\Services\Haat\KamanMenuWriter;
use App\Support\KamanUrl;
use Illuminate\Console\Command;

final class KamanCloneMenuCommand extends Command
{
    protected $signature = 'kaman:clone-menu
        {from=gcp : Source Kaman subdomain}
        {to=tgcp : Destination Kaman subdomain}
        {--from-password= : Source restaurant password}
        {--to-password= : Destination restaurant password}
        {--from-username= : Source login email}
        {--to-username= : Destination login email}
        {--from-environment=rest : Source TLD (dev|rest)}
        {--to-environment=dev : Destination TLD (dev|rest)}
        {--no-images : Skip downloading and uploading images}';

    protected $description = 'Copy a full Kaman menu (categories, meals, extras, images) from one restaurant to another';

    public function handle(KamanMenuWriter $writer, KamanMenuSnapshotParser $parser): int
    {
        $from = KamanUrl::normalizeSubdomain((string) $this->argument('from'));
        $to = KamanUrl::normalizeSubdomain((string) $this->argument('to'));
        $fromPassword = (string) ($this->option('from-password') ?: env('KAMAN_CLONE_FROM_PASSWORD', ''));
        $toPassword = (string) ($this->option('to-password') ?: env('KAMAN_CLONE_TO_PASSWORD', $fromPassword));
        if ($from === '' || $to === '' || $fromPassword === '' || $toPassword === '') {
            $this->error('from, to, --from-password, and --to-password are required.');

            return self::FAILURE;
        }

        $fromBase = KamanUrl::managerApi($from, KamanUrl::tldFromEnvironment((string) $this->option('from-environment')));
        $toBase = KamanUrl::managerApi($to, KamanUrl::tldFromEnvironment((string) $this->option('to-environment')));
        $withImages = ! (bool) $this->option('no-images');
        $progress = function (string $step, string $message): void {
            $this->line("[{$step}] {$message}");
        };

        $this->info("Logging into source {$fromBase}…");
        $fromToken = $writer->login($fromBase, KamanUrl::loginEmail($from, $this->option('from-username')), $fromPassword);
        $this->info("Reading menu from {$from}…");
        $snapshot = $writer->snapshot($fromBase, $fromToken, [], $progress);
        $parsed = $parser->parse($snapshot, $fromBase);
        $this->line(sprintf(
            'Source: %d categories, %d meals, %d addon groups, %d ingredients',
            count($parsed['categories']),
            count($parsed['meals']),
            count($parsed['unique_ingredient_categories']),
            count($parsed['unique_ingredients']),
        ));

        $this->info("Logging into destination {$toBase}…");
        $toToken = $writer->login($toBase, KamanUrl::loginEmail($to, $this->option('to-username')), $toPassword);
        $this->info("Writing menu to {$to}…");
        $report = $writer->write($toBase, $toToken, $parsed, $withImages, $progress);

        $summary = [
            'categories_created' => count($report['categories']['created'] ?? []),
            'categories_updated' => count($report['categories']['updated'] ?? []),
            'categories_reused' => count($report['categories']['reused'] ?? []),
            'categories_failed' => count($report['categories']['failed'] ?? []),
            'meals_created' => count($report['meals']['created'] ?? []),
            'meals_updated' => count($report['meals']['updated'] ?? []),
            'meals_reused' => count($report['meals']['reused'] ?? []),
            'meals_failed' => count($report['meals']['failed'] ?? []),
            'ingredient_categories_created' => count($report['ingredient_categories']['created'] ?? []),
            'ingredients_created' => count($report['ingredients']['created'] ?? []),
            'ingredients_failed' => count($report['ingredients']['failed'] ?? []),
            'links' => $report['links'] ?? [],
            'pipeline' => $report['pipeline'] ?? [],
        ];
        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');

        if (! ($report['success'] ?? false)) {
            $this->error('Clone finished with failures.');

            return self::FAILURE;
        }

        $this->info('Menu cloned successfully.');

        return self::SUCCESS;
    }
}
