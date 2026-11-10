<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KamanSupport\SupportPrinterIssue;
use App\Services\KamanSupport\SupportDeviceSessionService;
use Illuminate\Console\Command;

class PruneSupportLogsCommand extends Command
{
    protected $signature = 'kaman-support:prune-logs';

    protected $description = 'Delete expired POS support logs. Failures stay 14 days; routine logs stay 1 day. Resolved printer issues stay 180 days.';

    public function handle(SupportDeviceSessionService $sessions): int
    {
        $deleted = $sessions->pruneLogs();
        $this->info("Pruned {$deleted} support logs.");

        $days = max(30, (int) config('kaman-support.printer_issue_days', 180));
        $issues = SupportPrinterIssue::query()
            ->where('status', SupportPrinterIssue::STATUS_RESOLVED)
            ->where('resolved_at', '<', now()->subDays($days))
            ->delete();
        $this->info("Pruned {$issues} resolved printer issues.");

        return self::SUCCESS;
    }
}
