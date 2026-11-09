<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\FormWorkflowPausedException;
use App\Models\FormWorkflowRun;
use App\Models\User;
use App\Services\Form\FormMenuAgentMemory;
use App\Services\Form\FormMenuAgentService;
use App\Services\Form\FormWorkflowRunService;
use App\Services\Form\KamanChatCredentials;
use App\Services\Haat\KamanMenuWriter;
use App\Services\Form\MenuVoiceTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FormMenuAgentController extends Controller
{
    public function transcribe(Request $request, MenuVoiceTranscriber $voices): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|max:20480',
        ]);

        $file = $request->file('audio');
        $path = $file?->getRealPath();
        $binary = is_string($path) ? file_get_contents($path) : false;
        $text = is_string($binary)
            ? $voices->transcribe($binary, (string) ($file?->getClientOriginalName() ?: 'voice.m4a'))
            : null;
        if ($text === null || $text === '') {
            return response()->json([
                'success' => false,
                'error' => 'Could not transcribe that recording.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'text' => $text,
        ]);
    }

    public function chat(
        Request $request,
        FormMenuAgentService $agent,
        FormMenuAgentMemory $memory,
        FormWorkflowRunService $runService,
        KamanChatCredentials $credentials,
        KamanMenuWriter $writer,
    ): JsonResponse|StreamedResponse {
        $validated = $request->validate([
            'conversation_id' => 'nullable|uuid',
            'messages' => 'required|array|min:1|max:40',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'nullable|string|max:20000',
            'attachments' => 'nullable|array|max:'.FormMenuAgentService::MAX_ATTACHMENTS,
            'attachments.*' => 'file|max:20480',
            'attachment_roles' => 'nullable|array|max:'.FormMenuAgentService::MAX_ATTACHMENTS,
            'attachment_roles.*' => 'nullable|string|in:menu,meal_photo',
        ]);

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

        // The password leaves here. Nothing below, stored or sent to the model, sees it.
        $scrubbed = $credentials->scrubHistory($history);
        $history = $scrubbed['history'];
        $found = $scrubbed['credentials'];
        $redactedUserText = $found !== null && $scrubbed['in_latest'] ? $found['text'] : null;
        unset($scrubbed);

        $token = trim((string) $request->session()->get('full_ai_kaman_token', ''));
        $baseUrl = rtrim((string) $request->session()->get('full_ai_kaman_base_url', ''), '/');
        $remembered = is_array($stored['kaman'] ?? null) ? $stored['kaman'] : null;

        if ($found !== null && ($redactedUserText !== null || ($remembered['base_url'] ?? null) !== $found['base_url'])) {
            try {
                $token = $writer->login($found['base_url'], $found['email'], $found['password']);
            } catch (FormWorkflowPausedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Could not sign in to '.$found['subdomain'].': '.$credentials->hide($e->getMessage(), $found['password']),
                    'redacted_message' => $redactedUserText,
                ], 401);
            }
            $baseUrl = $found['base_url'];
            $memory->rememberRestaurant($userId, $conversationId, ['token' => $token, 'base_url' => $baseUrl]);
        } elseif ($remembered !== null) {
            $token = (string) ($remembered['token'] ?? '');
            $baseUrl = (string) ($remembered['base_url'] ?? '');
        }
        unset($found);

        if ($token === '' || $baseUrl === '') {
            return response()->json([
                'success' => false,
                'error' => 'Sign in to the restaurant first (subdomain + Login).',
            ], 401);
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
            $redactedUserText,
        ) {
            @ignore_user_abort(true);
            @set_time_limit(1800);

            $run = null;
            $previousMessages = $runService->sanitizeStoredMessages($stored['messages'] ?? []);
            $userText = '';
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if (($history[$i]['role'] ?? '') === 'user') {
                    $userText = (string) ($history[$i]['content'] ?? '');
                    break;
                }
            }
            $sourceUrl = $agent->findMenuUrlInHistory($history);
            if ($sourceUrl === null && $user instanceof User && $agent->wantsLinkedMenuWork($userText)) {
                $sourceUrl = $this->recentMenuUrl($user, $agent);
            }
            $title = $this->conversationTitle($history, $attachments);
            $runPayload = [
                'subdomain' => $subdomain,
                'environment' => $environment,
                'kaman_base_url' => $baseUrl,
                'conversation_id' => $conversationId,
                'title' => $title,
                'messages' => $this->messagesForStorage($history, $attachments, null, $previousMessages),
            ];
            if ($sourceUrl !== null) {
                $runPayload['source_url'] = $sourceUrl;
            }
            if ($user instanceof User) {
                $run = $runService->startMenuAgent($user, $conversationId, $runPayload);
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
                if ($redactedUserText !== null) {
                    $emit([
                        'event' => 'user_message_redacted',
                        'content' => $redactedUserText,
                    ]);
                }
                if ($run !== null) {
                    $emit([
                        'event' => 'run',
                        'run_id' => $run->id,
                        'run' => $runService->toArray($run),
                    ]);
                }

                $agentMemory = array_merge($stored, [
                    'source_url' => $sourceUrl,
                    'title' => $title,
                    'run_id' => $run?->id,
                    'worker_generation' => $run !== null ? $runService->workerGeneration($run) : null,
                    'has_new_menu_files' => array_filter($attachments, static fn (array $file): bool => ($file['kind'] ?? '') !== 'meal_photo') !== [],
                ]);
                if ($run !== null && $agent->shouldCopyLinkedMenu($history, $agentMemory) && $runService->startDetached($run)) {
                    $reply = 'Translating the menu into Arabic, English, and Hebrew and uploading the meal photos. Watch the debugger — Continue resumes it if this chat disconnects.';
                    $messages = $this->messagesForStorage($history, $attachments, $reply, $previousMessages);
                    $memory->put($userId, $conversationId, [
                        'attachments' => array_values(array_merge($stored['attachments'] ?? [], $attachments)),
                        'menu_draft' => $stored['menu_draft'] ?? null,
                        'source_url' => $sourceUrl,
                        'messages' => $messages,
                    ]);
                    $emit([
                        'event' => 'message',
                        'reply' => $reply,
                    ]);
                    $emit([
                        'event' => 'done',
                        'success' => true,
                        'reply' => $reply,
                        'conversation_id' => $conversationId,
                        'method_type' => 'Menu Agent',
                        'run' => $runService->toArray($run->refresh()),
                    ]);

                    return;
                }

                $result = $agent->run($history, $attachments, $token, $baseUrl, $emit, $agentMemory);
                $messages = $this->messagesForStorage($history, $attachments, $result['reply'], $previousMessages);
                $memory->put($userId, $conversationId, [
                    'attachments' => $result['attachments'],
                    'menu_draft' => $result['menu_draft'],
                    'source_url' => $sourceUrl,
                    'messages' => $messages,
                ]);
                $succeeded = empty($result['failed']);
                if ($run !== null) {
                    $run = $runService->markFinished($run, $succeeded, [
                        'reply' => $result['reply'],
                        'tool_calls' => $result['tool_calls'],
                        'conversation_id' => $conversationId,
                        'title' => $this->conversationTitle($history, $attachments),
                        'messages' => $messages,
                    ], $succeeded ? null : $result['reply']);
                }
                $emit([
                    'event' => 'done',
                    'success' => $succeeded,
                    'reply' => $result['reply'],
                    'tool_calls' => $result['tool_calls'],
                    'conversation_id' => $conversationId,
                    'method_type' => 'Menu Agent',
                    'run' => $run !== null ? $runService->toArray($run) : null,
                ]);
            } catch (FormWorkflowPausedException $e) {
                $pausedReply = 'Paused. Click Continue to resume the translations and photos.';
                $pausedMessages = $this->messagesForStorage($history, $attachments, $pausedReply, $previousMessages);
                $memory->put($userId, $conversationId, [
                    'attachments' => array_values(array_merge($stored['attachments'] ?? [], $attachments)),
                    'menu_draft' => $stored['menu_draft'] ?? null,
                    'source_url' => $sourceUrl,
                    'messages' => $pausedMessages,
                ]);
                if ($run !== null) {
                    $run = $runService->markPaused($run, [
                        'conversation_id' => $conversationId,
                        'title' => $title,
                        'messages' => $pausedMessages,
                        'paused' => true,
                    ], $pausedReply);
                }
                $emit([
                    'event' => 'message',
                    'reply' => $pausedReply,
                ]);
                $emit([
                    'event' => 'done',
                    'success' => false,
                    'reply' => $pausedReply,
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

    private function recentMenuUrl(User $user, FormMenuAgentService $agent): ?string
    {
        $runs = FormWorkflowRun::query()
            ->where('user_id', $user->id)
            ->where('method_type', 'Menu Agent')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        foreach ($runs as $run) {
            $payload = is_array($run->payload) ? $run->payload : [];
            $blob = trim((string) ($payload['source_url'] ?? ''));
            if ($blob !== '') {
                $url = $agent->findMenuUrlInHistory([['role' => 'user', 'content' => $blob]]);
                if ($url !== null) {
                    return $url;
                }
            }
            $url = $agent->findMenuUrlInHistory(is_array($payload['messages'] ?? null) ? $payload['messages'] : []);
            if ($url !== null) {
                return $url;
            }
            $title = trim((string) ($payload['title'] ?? ''));
            if ($title !== '') {
                $url = $agent->findMenuUrlInHistory([['role' => 'user', 'content' => $title]]);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
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

        $roles = $request->input('attachment_roles', []);
        $stored = [];
        foreach ($request->file('attachments') as $index => $file) {
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
            $role = is_array($roles) ? (string) ($roles[$index] ?? 'menu') : 'menu';
            $stored[] = [
                'name' => $original,
                'path' => $path,
                'mime' => $mime,
                'kind' => $role === 'meal_photo' ? 'meal_photo' : 'menu',
            ];
        }

        return $stored;
    }
}
