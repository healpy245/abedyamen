<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\KamanSupport\SupportDeviceSessionService;
use Illuminate\Console\Command;

class PruneSupportLogsCommand extends Command
{
    protected $signature = 'kaman-support:prune-logs';

    protected $description = 'Delete expired POS support logs. Failures stay 14 days; routine logs stay 1 day.';

    public function handle(SupportDeviceSessionService $sessions): int
    {
        $deleted = $sessions->pruneLogs();
        $this->info("Pruned {$deleted} support logs.");

        return self::SUCCESS;
    }
}
