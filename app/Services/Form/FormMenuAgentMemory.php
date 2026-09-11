<?php

declare(strict_types=1);

namespace App\Services\Form;

use Illuminate\Support\Facades\Cache;

final class FormMenuAgentMemory
{
    /**
     * @return array{attachments: list<array{name: string, path: string, mime: string}>, menu_draft: ?array, messages: list<array{role: string, content: string, files: list<array{name: string, mime: string}>}>}
     */
    public function get(int $userId, string $conversationId): array
    {
        $state = Cache::get($this->key($userId, $conversationId));
        if (! is_array($state)) {
            return [
                'attachments' => [],
                'menu_draft' => null,
                'messages' => [],
            ];
        }

        $attachments = [];
        foreach ($state['attachments'] ?? [] as $row) {
            if (! is_array($row) || empty($row['path']) || ! is_file((string) $row['path'])) {
                continue;
            }
            $attachments[] = [
                'name' => (string) ($row['name'] ?? 'file'),
                'path' => (string) $row['path'],
                'mime' => (string) ($row['mime'] ?? ''),
            ];
        }

        $draft = $state['menu_draft'] ?? null;
        $messages = [];
        foreach ($state['messages'] ?? [] as $row) {
            if (! is_array($row) || ! in_array($row['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }
            $files = [];
            foreach ($row['files'] ?? [] as $file) {
                if (! is_array($file) || trim((string) ($file['name'] ?? '')) === '') {
                    continue;
                }
                $files[] = [
                    'name' => (string) $file['name'],
                    'mime' => (string) ($file['mime'] ?? ''),
                ];
            }
            $messages[] = [
                'role' => (string) $row['role'],
                'content' => (string) ($row['content'] ?? ''),
                'files' => $files,
            ];
        }

        return [
            'attachments' => $attachments,
            'menu_draft' => is_array($draft) ? $draft : null,
            'messages' => $messages,
        ];
    }

    /**
     * @param  array{attachments?: list<array{name: string, path: string, mime: string}>, menu_draft?: ?array, messages?: list<array<string, mixed>>}  $state
     */
    public function put(int $userId, string $conversationId, array $state): void
    {
        Cache::put($this->key($userId, $conversationId), [
            'attachments' => array_values($state['attachments'] ?? []),
            'menu_draft' => $state['menu_draft'] ?? null,
            'messages' => array_values($state['messages'] ?? []),
        ], now()->addHours(8));
    }

    private function key(int $userId, string $conversationId): string
    {
        return 'form-menu-agent:'.$userId.':'.$conversationId;
    }
}
