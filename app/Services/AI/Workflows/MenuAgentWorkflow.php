<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Services\Form\FormMenuAgentService;
use App\Support\KamanUrl;

final class MenuAgentWorkflow extends AbstractFormWorkflow
{
    public function __construct(
        private readonly FormMenuAgentService $agent,
    ) {
    }

    public function run(array $payload, ?callable $onProgress = null): array
    {
        $token = trim((string) ($payload['kaman_token'] ?? ''));
        $baseUrl = trim((string) ($payload['kaman_base_url'] ?? ''));
        if ($baseUrl === '' && trim((string) ($payload['subdomain'] ?? '')) !== '') {
            $baseUrl = KamanUrl::managerApi(
                (string) $payload['subdomain'],
                KamanUrl::tldFromEnvironment($payload['environment'] ?? null),
            );
        }
        if ($token === '' || $baseUrl === '') {
            return [
                'success' => false,
                'paused' => true,
                'needs_login' => true,
                'message' => 'Restaurant session expired. Sign in, then Continue.',
                'error' => 'Restaurant session expired. Sign in, then Continue.',
            ];
        }

        $history = [];
        foreach ($payload['messages'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $history[] = [
                'role' => $role,
                'content' => (string) ($row['content'] ?? ''),
            ];
        }
        if ($history === []) {
            $title = trim((string) ($payload['title'] ?? ''));
            if ($title !== '') {
                $history[] = ['role' => 'user', 'content' => $title];
            }
        }

        $emit = static function (array $event) use ($onProgress): void {
            if ($onProgress === null) {
                return;
            }
            $message = (string) ($event['message'] ?? $event['reply'] ?? $event['summary'] ?? '');
            $onProgress((string) ($event['event'] ?? 'status'), $message, $event);
        };

        $result = $this->agent->run($history, [], $token, $baseUrl, $emit, [
            'menu_draft' => is_array($payload['menu_draft'] ?? null) ? $payload['menu_draft'] : null,
            'source_url' => $payload['source_url'] ?? null,
            'title' => (string) ($payload['title'] ?? ''),
            'run_id' => $payload['run_id'] ?? null,
            'worker_generation' => $payload['worker_generation'] ?? null,
        ]);

        $reply = (string) ($result['reply'] ?? '');
        $ok = $reply !== '' && ! str_starts_with($reply, 'The menu link could not');
        $messages = $history;
        if ($reply !== '') {
            $messages[] = ['role' => 'assistant', 'content' => $reply];
        }

        return [
            'success' => $ok,
            'reply' => $reply,
            'error' => $ok ? null : $reply,
            'conversation_id' => $payload['conversation_id'] ?? null,
            'title' => $payload['title'] ?? null,
            'messages' => $messages,
            'tool_calls' => $result['tool_calls'] ?? [],
        ];
    }
}
