<?php

declare(strict_types=1);

namespace App\Services\Kaman;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class KamanInventoryPlanBuilder
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{summary: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    public function build(array $options): array
    {
        $script = base_path('scripts/export_import_plan.py');
        if (!File::exists($script)) {
            throw new RuntimeException('Plan script not found: scripts/export_import_plan.py');
        }

        $python = $this->pythonBinary();
        $args = [
            $python,
            $script,
            '--json',
            '--limit=' . (int) ($options['limit'] ?? 50),
        ];

        if (!empty($options['skip_stock'])) {
            $args[] = '--skip-stock';
        }
        if (!empty($options['skip_links'])) {
            $args[] = '--skip-links';
        }
        if (!empty($options['no_ingredients'])) {
            $args[] = '--no-ingredients';
        }
        if (!empty($options['no_recipe_links'])) {
            $args[] = '--no-recipe-links';
        }

        $suppliersMapPath = $options['suppliers_map_path'] ?? null;
        if (is_string($suppliersMapPath) && $suppliersMapPath !== '' && File::exists($suppliersMapPath)) {
            $args[] = '--suppliers-map=' . $suppliersMapPath;
        }

        $result = Process::path(base_path())
            ->timeout(300)
            ->run($args);

        if (!$result->successful()) {
            throw new RuntimeException(
                'Failed to build import plan: ' . trim($result->errorOutput() ?: $result->output())
            );
        }

        $decoded = json_decode($result->output(), true);
        if (!is_array($decoded) || !isset($decoded['steps']) || !is_array($decoded['steps'])) {
            throw new RuntimeException('Invalid plan JSON from export script.');
        }

        return $decoded;
    }

    private function pythonBinary(): string
    {
        foreach (['python', 'python3', 'py'] as $candidate) {
            $check = Process::run([$candidate, '--version']);
            if ($check->successful()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Python is required to build the inventory import plan (install Python 3 + openpyxl).');
    }
}
