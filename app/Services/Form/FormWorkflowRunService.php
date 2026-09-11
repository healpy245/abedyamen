<?php

declare(strict_types=1);

namespace App\Services\Form;

use App\Models\FormWorkflowRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class FormWorkflowRunService
{
    public const COMMAND_PAUSE = 'pause';

    private const TTL_SECONDS = 21600;

    public function start(User $user, string $methodType, array $payload): FormWorkflowRun
    {
        $safe = $this->safePayload($payload);

        $run = FormWorkflowRun::query()->create([
            'user_id' => $user->id,
            'method_type' => $methodType,
            'subdomain' => $safe['subdomain'] ?? $safe['restaurant_name'] ?? null,
            'environment' => $safe['environment'] ?? null,
            'status' => FormWorkflowRun::STATUS_RUNNING,
            'payload' => $safe,
            'started_at' => now(),
        ]);

        $token = trim((string) ($payload['kaman_token'] ?? ''));
        if ($token !== '') {
            $this->rememberToken($run, $token);
        }

        return $run;
    }

    public function rememberToken(FormWorkflowRun $run, ?string $token): void
    {
        $token = trim((string) $token);
        if ($token === '') {
            return;
        }

        Cache::put($this->tokenKey($run->id), $token, self::TTL_SECONDS);
    }

    public function token(FormWorkflowRun $run): ?string
    {
        $token = Cache::get($this->tokenKey($run->id));

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function requestPause(FormWorkflowRun $run): void
    {
        Cache::put($this->commandKey($run->id), self::COMMAND_PAUSE, self::TTL_SECONDS);
    }

    public function clearCommand(FormWorkflowRun $run): void
    {
        Cache::forget($this->commandKey($run->id));
    }

    public function command(?int $runId): ?string
    {
        if ($runId === null || $runId < 1) {
            return null;
        }

        $value = Cache::get($this->commandKey($runId));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function markRunning(FormWorkflowRun $run, bool $clearResult = true): FormWorkflowRun
    {
        $this->clearCommand($run);
        $fill = [
            'status' => FormWorkflowRun::STATUS_RUNNING,
            'paused_at' => null,
            'finished_at' => null,
            'error' => null,
        ];
        if ($clearResult) {
            $fill['result'] = null;
        }
        $run->forceFill($fill)->save();

        return $run->refresh();
    }

    public function resetEvents(FormWorkflowRun $run): void
    {
        Cache::forget($this->eventsKey($run->id));
        try {
            $run->forceFill(['events' => []])->save();
        } catch (\Throwable) {
            // Logging must never stop the workflow.
        }
    }

    public function findMenuAgentByConversation(User $user, string $conversationId): ?FormWorkflowRun
    {
        if ($conversationId === '') {
            return null;
        }

        return FormWorkflowRun::query()
            ->where('user_id', $user->id)
            ->where('method_type', 'Menu Agent')
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->first(function (FormWorkflowRun $run) use ($conversationId) {
                $payload = is_array($run->payload) ? $run->payload : [];
                $result = is_array($run->result) ? $run->result : [];

                return (string) ($payload['conversation_id'] ?? $result['conversation_id'] ?? '') === $conversationId;
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function startMenuAgent(User $user, string $conversationId, array $payload): FormWorkflowRun
    {
        $safe = $this->safePayload($payload);
        $safe['conversation_id'] = $conversationId;
        $existing = $this->findMenuAgentByConversation($user, $conversationId);
        if ($existing !== null && ! $existing->isRunning()) {
            $previous = is_array($existing->payload) ? $existing->payload : [];
            $merged = array_merge($previous, $safe);
            $keptTitle = trim((string) ($previous['title'] ?? ''));
            if ($keptTitle !== '') {
                $merged['title'] = $keptTitle;
            }
            $existing->forceFill([
                'payload' => $merged,
                'subdomain' => $merged['subdomain'] ?? $existing->subdomain,
                'environment' => $merged['environment'] ?? $existing->environment,
            ])->save();
            $this->resetEvents($existing);
            $token = trim((string) ($payload['kaman_token'] ?? ''));
            if ($token !== '') {
                $this->rememberToken($existing, $token);
            }

            return $this->markRunning($existing, false);
        }

        return $this->start($user, 'Menu Agent', $safe);
    }

    /**
     * @return array{id: ?string, title: string, messages: list<array{role: string, content: string, files: list<array{name: string, mime: string}>}>}|null
     */
    public function conversation(FormWorkflowRun $run): ?array
    {
        if ($run->method_type !== 'Menu Agent') {
            return null;
        }

        $payload = is_array($run->payload) ? $run->payload : [];
        $result = is_array($run->result) ? $run->result : [];
        $id = trim((string) ($payload['conversation_id'] ?? $result['conversation_id'] ?? ''));
        $raw = $result['messages'] ?? $payload['messages'] ?? [];
        $messages = $this->sanitizeStoredMessages(is_array($raw) ? $raw : []);
        if ($messages === []) {
            $reply = trim((string) ($result['reply'] ?? ''));
            if ($reply !== '') {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $reply,
                    'files' => [],
                ];
            }
        }

        return [
            'id' => $id !== '' ? $id : null,
            'title' => $this->displayTitle($run),
            'messages' => $messages,
        ];
    }

    public function displayTitle(FormWorkflowRun $run): string
    {
        $payload = is_array($run->payload) ? $run->payload : [];
        $result = is_array($run->result) ? $run->result : [];
        $title = trim((string) ($payload['title'] ?? $result['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        return (string) $run->method_type.($run->subdomain ? ' · '.$run->subdomain : '');
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{role: string, content: string, files: list<array{name: string, mime: string}>}>
     */
    public function sanitizeStoredMessages(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $files = [];
            foreach ($row['files'] ?? [] as $file) {
                if (! is_array($file)) {
                    continue;
                }
                $name = trim((string) ($file['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $files[] = [
                    'name' => $name,
                    'mime' => (string) ($file['mime'] ?? ''),
                ];
            }
            $out[] = [
                'role' => $role,
                'content' => (string) ($row['content'] ?? ''),
                'files' => $files,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function markPaused(FormWorkflowRun $run, ?array $result = null, ?string $error = null): FormWorkflowRun
    {
        $this->persistEvents($run);
        $this->releaseWorker($run);
        $run->forceFill([
            'status' => FormWorkflowRun::STATUS_PAUSED,
            'paused_at' => now(),
            'result' => $result ?? $run->result,
            'error' => $error,
        ])->save();

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function markFinished(FormWorkflowRun $run, bool $success, ?array $result = null, ?string $error = null): FormWorkflowRun
    {
        $this->clearCommand($run);
        $this->persistEvents($run);
        $this->releaseWorker($run);
        $run->forceFill([
            'status' => $success ? FormWorkflowRun::STATUS_COMPLETED : FormWorkflowRun::STATUS_FAILED,
            'result' => $result ?? $run->result,
            'error' => $success ? null : ($error ?: $run->error),
            'finished_at' => now(),
        ])->save();

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function finishFromResult(FormWorkflowRun $run, array $result): FormWorkflowRun
    {
        if ($result['paused'] ?? false) {
            return $this->markPaused($run, $result, $result['message'] ?? 'Paused');
        }

        return $this->markFinished(
            $run,
            (bool) ($result['success'] ?? false),
            $result,
            $result['error'] ?? null,
        );
    }

    public function claimWorker(FormWorkflowRun $run): bool
    {
        return Cache::add($this->busyKey($run->id), 1, self::TTL_SECONDS);
    }

    public function releaseWorker(FormWorkflowRun $run): void
    {
        Cache::forget($this->busyKey($run->id));
    }

    public function workerGeneration(FormWorkflowRun|int $run): int
    {
        $id = $run instanceof FormWorkflowRun ? (int) $run->id : $run;
        $value = Cache::get($this->genKey($id));

        return is_numeric($value) ? (int) $value : 1;
    }

    public function bumpGeneration(FormWorkflowRun $run): int
    {
        $next = $this->workerGeneration($run) + 1;
        Cache::put($this->genKey($run->id), $next, self::TTL_SECONDS);

        return $next;
    }

    public function isStale(FormWorkflowRun $run, int $seconds = 45): bool
    {
        if (! $run->isRunning()) {
            return false;
        }

        $heartbeat = $run->updated_at;
        if ($heartbeat === null) {
            return true;
        }

        return $heartbeat->lte(now()->subSeconds($seconds));
    }

    public function reclaimIfStale(FormWorkflowRun $run, int $seconds = 45): FormWorkflowRun
    {
        if (! $run->isRunning() || ! $this->isStale($run, $seconds)) {
            return $run;
        }

        $this->requestPause($run);
        $this->bumpGeneration($run);

        return $this->markPaused(
            $run,
            is_array($run->result) ? $run->result : ['paused' => true],
            'Stopped after the live connection dropped. Click Continue to resume.',
        );
    }

    /**
     * @return Collection<int, FormWorkflowRun>
     */
    public function forUser(User $user, int $limit = 40): Collection
    {
        $query = FormWorkflowRun::query()->with(['user:id,name,email']);
        if (! $user->is_admin) {
            $query->where('user_id', $user->id);
        }

        return $query
            ->orderByRaw("CASE WHEN status = 'running' THEN 0 WHEN status = 'paused' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->limit($user->is_admin ? max($limit, 80) : $limit)
            ->get();
    }

    public function destroy(FormWorkflowRun $run): void
    {
        $this->clearCommand($run);
        $this->bumpGeneration($run);
        $this->releaseWorker($run);
        Cache::forget($this->tokenKey($run->id));
        Cache::forget($this->eventsKey($run->id));
        Cache::forget($this->genKey($run->id));
        $run->delete();
    }

    public function canAccess(User $user, FormWorkflowRun $run): bool
    {
        return $user->is_admin || (int) $user->id === (int) $run->user_id;
    }

    public function canDelete(User $user, FormWorkflowRun $run): bool
    {
        return $this->canAccess($user, $run);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(FormWorkflowRun $run, ?User $viewer = null): array
    {
        $payload = is_array($run->payload) ? $run->payload : [];
        $result = is_array($run->result) ? $run->result : [];
        $owner = $run->relationLoaded('user') ? $run->user : null;
        $canDelete = $viewer !== null && $this->canDelete($viewer, $run);

        return [
            'id' => $run->id,
            'method_type' => $run->method_type,
            'title' => $this->displayTitle($run),
            'conversation_id' => (string) ($payload['conversation_id'] ?? $result['conversation_id'] ?? ''),
            'has_conversation' => $run->method_type === 'Menu Agent',
            'subdomain' => $run->subdomain,
            'environment' => $run->environment,
            'status' => $run->status,
            'error' => $run->error,
            'user_id' => $run->user_id,
            'user_name' => $owner?->name,
            'user_email' => $owner?->email,
            'is_mine' => $viewer !== null && (int) $viewer->id === (int) $run->user_id,
            'can_continue' => $run->canContinue(),
            'can_delete' => $canDelete,
            'is_running' => $run->isRunning(),
            'needs_login' => (bool) (is_array($run->result) && ($run->result['needs_login'] ?? false)),
            'pause_requested' => $this->command($run->id) === self::COMMAND_PAUSE,
            'last_event_at' => $run->updated_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'paused_at' => $run->paused_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'pause_url' => route('form.runs.pause', $run),
            'continue_url' => route('form.runs.continue', $run),
            'show_url' => route('form.runs.show', $run),
            'delete_url' => route('form.runs.destroy', $run),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function appendEvent(FormWorkflowRun $run, array $event): void
    {
        try {
            $events = $this->events($run);
            $events[] = $event;
            if (count($events) > 2500) {
                $events = array_slice($events, -2500);
            }
            Cache::put($this->eventsKey($run->id), $events, self::TTL_SECONDS);
            $run->forceFill(['events' => $events])->save();
        } catch (\Throwable) {
            // Logging must never stop the workflow.
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(FormWorkflowRun $run): array
    {
        $cached = Cache::get($this->eventsKey($run->id));
        if (is_array($cached)) {
            return array_values($cached);
        }

        return is_array($run->events) ? array_values($run->events) : [];
    }

    public function persistEvents(FormWorkflowRun $run): void
    {
        try {
            $run->forceFill(['events' => $this->events($run)])->save();
        } catch (\Throwable) {
            // Logging must never stop the workflow.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function safePayload(array $payload): array
    {
        $safe = $payload;
        unset($safe['password'], $safe['haat_json'], $safe['kaman_token']);

        return $safe;
    }

    private function commandKey(int $runId): string
    {
        return 'form-workflow:'.$runId.':cmd';
    }

    private function tokenKey(int $runId): string
    {
        return 'form-workflow:'.$runId.':token';
    }

    private function eventsKey(int $runId): string
    {
        return 'form-workflow:'.$runId.':events';
    }

    private function busyKey(int $runId): string
    {
        return 'form-workflow:'.$runId.':busy';
    }

    private function genKey(int $runId): string
    {
        return 'form-workflow:'.$runId.':gen';
    }
}
