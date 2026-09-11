<?php

declare(strict_types=1);

namespace App\Services\Form;

use App\Models\FormWorkflowRun;
use App\Services\AI\FormWorkflowRunner;

final class FormWorkflowExecutor
{
    public function __construct(
        private readonly FormWorkflowRunner $runner,
        private readonly FormWorkflowRunService $runs,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): void|null  $onEmit
     * @return array<string, mixed>
     */
    public function run(FormWorkflowRun $run, array $payload, ?callable $onEmit = null): array
    {
        @ignore_user_abort(true);
        @set_time_limit($run->method_type === 'HAAT Menu Copy' ? 1800 : 600);

        $payload['run_id'] = $run->id;
        $payload['worker_generation'] = $this->runs->workerGeneration($run);

        $onProgress = function (string $step, string $message, array $data = []) use ($run, $onEmit): void {
            $timestamp = now()->toIso8601String();
            $event = [
                'event' => 'step',
                'step' => $step,
                'message' => $message,
                'data' => $data,
                'timestamp' => $timestamp,
            ];
            $this->runs->appendEvent($run, $event);
            if ($onEmit !== null) {
                $onEmit($event + ['run_id' => $run->id]);
            }
        };

        try {
            $result = $this->runner->run((string) $run->method_type, $payload, $onProgress);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        $paused = (bool) ($result['paused'] ?? false);
        $doneMessage = $paused
            ? (string) ($result['message'] ?? 'Workflow paused. You can continue it from the list.')
            : (($result['success'] ?? false)
                ? 'Workflow finished.'
                : (string) ($result['error'] ?? 'Workflow finished with errors.'));
        $this->runs->appendEvent($run, [
            'event' => 'done',
            'step' => 'done',
            'message' => $doneMessage,
            'data' => ['status' => $paused ? 'run' : (($result['success'] ?? false) ? 'ok' : 'fail')],
            'timestamp' => now()->toIso8601String(),
        ]);
        $this->runs->finishFromResult($run, $result);

        return $result;
    }
}
