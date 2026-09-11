<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FormWorkflowRun;
use App\Services\Form\FormWorkflowExecutor;
use App\Services\Form\FormWorkflowRunService;
use Illuminate\Console\Command;

final class RunFormWorkflowCommand extends Command
{
    protected $signature = 'form:workflow-run {run : Form workflow run id}';

    protected $description = 'Execute a form workflow run in the background';

    public function handle(FormWorkflowRunService $runs, FormWorkflowExecutor $executor): int
    {
        $run = FormWorkflowRun::query()->find((int) $this->argument('run'));
        if ($run === null) {
            $this->error('Run not found.');

            return self::FAILURE;
        }

        if (! $runs->claimWorker($run)) {
            if (! $runs->isStale($run, 45)) {
                $this->warn('Run is already being processed.');

                return self::SUCCESS;
            }
            $runs->releaseWorker($run);
            $runs->claimWorker($run);
        }

        $payload = is_array($run->payload) ? $run->payload : [];
        $token = $runs->token($run);
        if (is_string($token) && $token !== '') {
            $payload['kaman_token'] = $token;
        }

        if (! $run->isRunning()) {
            $runs->markRunning($run);
            $run = $run->refresh();
        }

        $executor->run($run, $payload);

        return self::SUCCESS;
    }
}
