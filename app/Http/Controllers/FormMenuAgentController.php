<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Form\FormMenuAgentMemory;
use App\Services\Form\FormMenuAgentService;
use App\Services\Form\FormWorkflowRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FormMenuAgentController extends Controller
{
    public function chat(
        Request $request,
        FormMenuAgentService $agent,
        FormMenuAgentMemory $memory,
        FormWorkflowRunService $runService,
    ): JsonResponse|StreamedResponse {
        $validated = $request->validate([
            'conversation_id' => 'nullable|uuid',
            'messages' => 'required|array|min:1|max:40',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'nullable|string|max:20000',
            'attachments' => 'nullable|array|max:'.FormMenuAgentService::MAX_ATTACHMENTS,
            'attachments.*' => 'file|max:20480',
        ]);

        $token = trim((string) $request->session()->get('full_ai_kaman_token', ''));
        $baseUrl = rtrim((string) $request->session()->get('full_ai_kaman_base_url', ''), '/');

        if ($token === '' || $baseUrl === '') {
            return response()->json([
                'success' => false,
                'error' => 'Sign in to the restaurant first (subdomain + Login).',
            ], 401);
        }

        $user = $request->user();
        $userId = (int) ($user?->id ?? 0);
        $conversationId = (string) ($validated['conversation_id'] ?? Str::uuid());
        $stored = $memory->get($userId, $conversationId);

        $history = [];
        foreach ($validated['messages'] as $row) {
            $history[] = [
                'role' => $row['role'],
                'content' => (string) ($row['content'] ?? ''),
            ];
        }

        $attachments = $this->storeAttachments($request, $userId, $conversationId);
        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '');
        preg_match('/^([a-z0-9-]+)\.kaman\.(rest|dev)$/i', $host, $hostMatch);
        $subdomain = $hostMatch[1] ?? '';
        $environment = strtolower($hostMatch[2] ?? 'rest');

        return response()->stream(function () use (
            $agent,
            $memory,
            $runService,
            $history,
            $attachments,
            $stored,
            $token,
            $baseUrl,
            $user,
            $userId,
            $conversationId,
            $subdomain,
            $environment,
        ) {
            @ignore_user_abort(true);
            @set_time_limit(600);

            $run = null;
            $previousMessages = $runService->sanitizeStoredMessages($stored['messages'] ?? []);
            if ($user instanceof User) {
                $run = $runService->startMenuAgent($user, $conversationId, [
                    'subdomain' => $subdomain,
                    'environment' => $environment,
                    'conversation_id' => $conversationId,
                    'title' => $this->conversationTitle($history, $attachments),
                    'messages' => $this->messagesForStorage($history, $attachments, null, $previousMessages),
                ]);
                $runService->rememberToken($run, $token);
                if ($previousMessages === []) {
                    $previousMessages = $runService->sanitizeStoredMessages(
                        is_array($run->result) && is_array($run->result['messages'] ?? null)
                            ? $run->result['messages']
                            : []
                    );
                }
            }

            $emit = function (array $event) use ($runService, &$run): void {
                if ($run !== null) {
                    $debug = $this->toDebugEvent($event);
                    if ($debug !== null) {
                        $runService->appendEvent($run, $debug);
                        if (connection_aborted()) {
                            return;
                        }
                        echo 'data: '.json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                    }
                }
                if (connection_aborted()) {
                    return;
                }
                echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            try {
                if ($run !== null) {
                    $emit([
                        'event' => 'run',
                        'run_id' => $run->id,
                        'run' => $runService->toArray($run),
                    ]);
                }

                $result = $agent->run($history, $attachments, $token, $baseUrl, $emit, $stored);
                $messages = $this->messagesForStorage($history, $attachments, $result['reply'], $previousMessages);
                $memory->put($userId, $conversationId, [
                    'attachments' => $result['attachments'],
                    'menu_draft' => $result['menu_draft'],
                    'messages' => $messages,
                ]);
                if ($run !== null) {
                    $run = $runService->markFinished($run, true, [
                        'reply' => $result['reply'],
                        'tool_calls' => $result['tool_calls'],
                        'conversation_id' => $conversationId,
                        'title' => $this->conversationTitle($history, $attachments),
                        'messages' => $messages,
                    ]);
                }
                $emit([
                    'event' => 'done',
                    'success' => true,
                    'reply' => $result['reply'],
                    'tool_calls' => $result['tool_calls'],
                    'conversation_id' => $conversationId,
                    'method_type' => 'Menu Agent',
                    'run' => $run !== null ? $runService->toArray($run) : null,
                ]);
            } catch (\Throwable $e) {
                $failedMessages = $this->messagesForStorage($history, $attachments, $e->getMessage(), $previousMessages);
                $memory->put($userId, $conversationId, [
                    'attachments' => array_values(array_merge($stored['attachments'] ?? [], $attachments)),
                    'menu_draft' => $stored['menu_draft'] ?? null,
                    'messages' => $failedMessages,
                ]);
                if ($run !== null) {
                    $run = $runService->markFinished($run, false, [
                        'conversation_id' => $conversationId,
                        'title' => $this->conversationTitle($history, $attachments),
                        'messages' => $failedMessages,
                    ], $e->getMessage());
                }
                $emit([
                    'event' => 'error',
                    'message' => $e->getMessage(),
                ]);
                $emit([
                    'event' => 'done',
                    'success' => false,
                    'reply' => $e->getMessage(),
                    'conversation_id' => $conversationId,
                    'method_type' => 'Menu Agent',
                    'run' => $run !== null ? $runService->toArray($run) : null,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function toDebugEvent(array $event): ?array
    {
        $type = (string) ($event['event'] ?? '');
        $timestamp = now()->toIso8601String();

        return match ($type) {
            'status' => [
                'event' => 'step',
                'step' => $this->debugStepFromMessage((string) ($event['message'] ?? '')),
                'message' => (string) ($event['message'] ?? ''),
                'data' => ['status' => 'run'],
                'timestamp' => $timestamp,
            ],
            'tool_start' => [
                'event' => 'step',
                'step' => 'http',
                'message' => strtoupper((string) ($event['arguments']['method'] ?? 'HTTP')).' '.((string) ($event['arguments']['path'] ?? '')),
                'data' => ['status' => 'run'],
                'timestamp' => $timestamp,
            ],
            'tool_result' => [
                'event' => 'step',
                'step' => 'http',
                'message' => (string) ($event['summary'] ?? 'HTTP done'),
                'data' => ['status' => ($event['ok'] ?? true) ? 'ok' : 'fail'],
                'timestamp' => $timestamp,
            ],
            'message' => [
                'event' => 'step',
                'step' => 'done',
                'message' => 'Agent finished this turn.',
                'data' => ['status' => 'ok'],
                'timestamp' => $timestamp,
            ],
            'error' => [
                'event' => 'step',
                'step' => 'done',
                'message' => (string) ($event['message'] ?? 'Agent failed'),
                'data' => ['status' => 'fail'],
                'timestamp' => $timestamp,
            ],
            default => null,
        };
    }

    private function debugStepFromMessage(string $message): string
    {
        $lower = mb_strtolower($message);
        if (str_contains($lower, 'ocr') || str_contains($lower, 'reading') || str_contains($lower, 'read ')) {
            return 'read';
        }
        if (str_contains($lower, 'storing') || str_contains($lower, 'duplicate')) {
            return 'store';
        }

        return 'think';
    }

    private function conversationTitle(array $history, array $attachments): string
    {
        foreach ($history as $row) {
            $text = trim((string) ($row['content'] ?? ''));
            if (($row['role'] ?? '') === 'user' && $text !== '') {
                return Str::limit($text, 56, '…');
            }
        }
        $name = trim((string) ($attachments[0]['name'] ?? ''));

        return $name !== '' ? $name : 'Menu Agent';
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array{name: string, path?: string, mime: string}>  $attachments
     * @param  list<array{role: string, content: string, files?: list<array{name: string, mime: string}>}>  $previous
     * @return list<array{role: string, content: string, files?: list<array{name: string, mime: string}>}>
     */
    private function messagesForStorage(array $history, array $attachments, ?string $assistantReply, array $previous): array
    {
        $prevFiles = [];
        foreach ($previous as $i => $row) {
            if (($row['role'] ?? '') === 'user' && ! empty($row['files'])) {
                $prevFiles[$i] = $row['files'];
            }
        }

        $fileMeta = [];
        foreach ($attachments as $file) {
            $fileMeta[] = [
                'name' => (string) ($file['name'] ?? 'file'),
                'mime' => (string) ($file['mime'] ?? ''),
            ];
        }

        $lastUserIndex = null;
        foreach ($history as $i => $row) {
            if (($row['role'] ?? '') === 'user') {
                $lastUserIndex = $i;
            }
        }

        $out = [];
        foreach ($history as $i => $row) {
            $item = [
                'role' => (string) ($row['role'] ?? 'user'),
                'content' => (string) ($row['content'] ?? ''),
            ];
            if ($i === $lastUserIndex && $fileMeta !== []) {
                $item['files'] = $fileMeta;
            } elseif (isset($prevFiles[$i]) && is_array($prevFiles[$i])) {
                $item['files'] = $prevFiles[$i];
            }
            $out[] = $item;
        }

        if ($assistantReply !== null && $assistantReply !== '') {
            $out[] = [
                'role' => 'assistant',
                'content' => $assistantReply,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, path: string, mime: string}>
     */
    private function storeAttachments(Request $request, int $userId, string $conversationId): array
    {
        if (! $request->hasFile('attachments')) {
            return [];
        }

        $dir = storage_path('app/menu-agent/'.$userId.'/'.$conversationId);
        File::ensureDirectoryExists($dir);

        $stored = [];
        foreach ($request->file('attachments') as $file) {
            if ($file === null || ! $file->isValid()) {
                continue;
            }
            $original = $file->getClientOriginalName();
            $ext = $file->getClientOriginalExtension();
            $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: '');
            $filename = Str::uuid().($ext !== '' ? '.'.$ext : '');
            $file->move($dir, $filename);
            $path = $dir.DIRECTORY_SEPARATOR.$filename;
            if ($mime === '') {
                $mime = (string) (mime_content_type($path) ?: '');
            }
            $stored[] = [
                'name' => $original,
                'path' => $path,
                'mime' => $mime,
            ];
        }

        return $stored;
    }
}
