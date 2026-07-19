<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Services\KamanAgents\KamanAgentsApiClient;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class WhatsAppStaffAgentService
{
    private const TASK_TYPE_ISSUE = 'REPORT_PROBLEM';

    public function __construct(
        private readonly KamanAgentsApiClient $agentsApi,
    ) {}

    public function handleMessage(string $incomingMessage, bool $isCeo): string
    {
        $message = trim($incomingMessage);
        if ($message === '') {
            return 'ابعتلي رسالة وبحاول أساعدك.';
        }

        if (!$this->agentsApi->isConfigured()) {
            return 'وكيل الإدارة مو مضبوط حالياً. تواصل مع فريق التطوير.';
        }

        try {
            $intent = $this->classifyIntent($message, $isCeo);

            return match ($intent['intent']) {
                'create_task' => $this->handleCreateTask($intent),
                'query_schedule' => $isCeo
                    ? $this->handleQuerySchedule($intent)
                    : 'هالسؤال بس للـ CEO. إذا بدك تبعت مهمة لعامل، اكتبلي مين والمهمة.',
                'query_pending_tasks' => $isCeo
                    ? $this->handleQueryPendingTasks()
                    : 'هالسؤال بس للـ CEO. إذا بدك تبعت مهمة لعامل، اكتبلي مين والمهمة.',
                'greeting' => $this->greetingReply($isCeo),
                default => $this->unknownReply($isCeo),
            };
        } catch (\Throwable $e) {
            Log::error('WhatsApp staff agent failed', [
                'message' => $message,
                'is_ceo' => $isCeo,
                'error' => $e->getMessage(),
            ]);

            return 'صار في مشكلة بمعالجة الطلب. جرب مرة ثانية أو صغّر السؤال شوي.';
        }
    }

    /**
     * @return array{
     *   intent:string,
     *   worker_name:?string,
     *   task_title:?string,
     *   task_description:?string,
     *   client_name:?string,
     *   schedule_worker_name:?string,
     *   schedule_date:?string
     * }
     */
    private function classifyIntent(string $message, bool $isCeo): array
    {
        $systemPrompt = <<<'PROMPT'
You classify WhatsApp messages from KAMAN management staff into structured JSON.

Staff may write in Arabic (Levantine), Hebrew, or English.

Intents:
- create_task: sender wants to assign work to a worker/agent (examples: "ودي مهمه لاسامه عشان يفحص مشكلة مطعم الماري", "ابعث مهمه لعبد قله يفحص كل التعديلات الجديده")
- query_schedule: CEO asks what a worker's schedule/plan is for a day (example: "شو برنامج احمد اليوم")
- query_pending_tasks: CEO asks which open/incomplete tasks exist and for which workers (example: "شو في مهمات لسه ما تساوت وعند اي عامل")
- greeting: hello / small talk only
- unknown: anything else

For create_task extract:
- worker_name: target worker first name or nickname as written (e.g. osama, اسامه, abed, عبد)
- task_title: short Hebrew or Arabic title (max 120 chars)
- task_description: clear instructions for the worker (max 500 chars)
- client_name: restaurant/client name if mentioned (nullable)

For query_schedule extract:
- schedule_worker_name: worker name as written
- schedule_date: ISO date YYYY-MM-DD if explicit, else "today"

Return JSON only:
{
  "intent": "create_task|query_schedule|query_pending_tasks|greeting|unknown",
  "worker_name": null,
  "task_title": null,
  "task_description": null,
  "client_name": null,
  "schedule_worker_name": null,
  "schedule_date": null
}
PROMPT;

        if (!$isCeo) {
            $systemPrompt .= "\n\nThe sender is a coworker (not CEO). Only create_task and greeting are allowed; map schedule/task-status questions to unknown.";
        }

        $raw = $this->chatWithOpenAi([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message],
        ], [
            'temperature' => 0.1,
            'max_tokens' => 400,
            'response_format' => ['type' => 'json_object'],
        ]);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['intent' => 'unknown'] + $this->emptyIntentFields();
        }

        return [
            'intent' => (string) ($decoded['intent'] ?? 'unknown'),
            'worker_name' => $this->nullableString($decoded['worker_name'] ?? null),
            'task_title' => $this->nullableString($decoded['task_title'] ?? null),
            'task_description' => $this->nullableString($decoded['task_description'] ?? null),
            'client_name' => $this->nullableString($decoded['client_name'] ?? null),
            'schedule_worker_name' => $this->nullableString($decoded['schedule_worker_name'] ?? null),
            'schedule_date' => $this->nullableString($decoded['schedule_date'] ?? null),
        ];
    }

    /**
     * @param  array{
     *   intent:string,
     *   worker_name:?string,
     *   task_title:?string,
     *   task_description:?string,
     *   client_name:?string,
     *   schedule_worker_name:?string,
     *   schedule_date:?string
     * }  $intent
     */
    private function handleCreateTask(array $intent): string
    {
        $workerName = $intent['worker_name'] ?? '';
        if ($workerName === '') {
            return 'مين العامل اللي بدك تبعتله المهمة؟ اكتبلي اسمه.';
        }

        $users = $this->loadUsersForMatching();
        $worker = $this->matchUserByName($users, $workerName);
        if ($worker === null) {
            return 'ما لقيت عامل باسم "' . $workerName . '". تأكد من الاسم وجرب مرة ثانية.';
        }

        $title = trim((string) ($intent['task_title'] ?? ''));
        $description = trim((string) ($intent['task_description'] ?? ''));
        if ($title === '') {
            $title = 'مهمة من الإدارة';
        }
        if ($description === '') {
            $description = $title;
        }

        $clientId = null;
        $clientName = $intent['client_name'] ?? null;
        if ($clientName !== null && $clientName !== '') {
            $client = $this->matchClientByName($this->agentsApi->getAllClients(), $clientName);
            $clientId = is_array($client) ? (string) ($client['id'] ?? '') : null;
            if ($clientId === '') {
                $clientId = null;
            }
        }

        $task = $this->agentsApi->createTask(
            self::TASK_TYPE_ISSUE,
            mb_substr($title, 0, 200),
            mb_substr($description, 0, 5000),
            (string) $worker['id'],
            $clientId,
        );

        $workerLabel = (string) ($worker['fullName'] ?? $worker['username'] ?? $workerName);
        $taskTitle = (string) ($task['title'] ?? $title);

        return "تم ✅\n"
            . 'بعتت مهمة لـ ' . $workerLabel . ":\n"
            . '• ' . $taskTitle;
    }

    /**
     * @param  array{
     *   intent:string,
     *   worker_name:?string,
     *   task_title:?string,
     *   task_description:?string,
     *   client_name:?string,
     *   schedule_worker_name:?string,
     *   schedule_date:?string
     * }  $intent
     */
    private function handleQuerySchedule(array $intent): string
    {
        $workerName = $intent['schedule_worker_name'] ?? $intent['worker_name'] ?? '';
        if ($workerName === '') {
            return 'لمين بدك تشوف البرنامج؟ اكتب اسم العامل.';
        }

        $users = $this->loadUsersForMatching();
        $worker = $this->matchUserByName($users, $workerName);
        if ($worker === null) {
            return 'ما لقيت عامل باسم "' . $workerName . '".';
        }

        $date = $this->resolveScheduleDate($intent['schedule_date'] ?? 'today');
        $workerId = (string) $worker['id'];
        $workerLabel = (string) ($worker['fullName'] ?? $worker['username'] ?? $workerName);

        $appointments = [];
        foreach ($this->agentsApi->getAllClients() as $client) {
            if ((string) ($client['userId'] ?? '') !== $workerId) {
                continue;
            }

            $scheduledAt = $client['scheduledDate'] ?? null;
            if (!is_string($scheduledAt) || $scheduledAt === '') {
                continue;
            }

            $scheduled = Carbon::parse($scheduledAt)->timezone('Asia/Jerusalem');
            if (!$scheduled->isSameDay($date)) {
                continue;
            }

            $restName = trim((string) ($client['restName'] ?? $client['fullName'] ?? 'לקוח'));
            $time = $scheduled->format('H:i');
            $status = trim((string) ($client['status'] ?? ''));
            $appointments[] = $time . ' — ' . $restName . ($status !== '' ? ' (' . $status . ')' : '');
        }

        $dateLabel = $date->format('d/m/Y');
        if ($appointments === []) {
            return 'برنامج ' . $workerLabel . ' يوم ' . $dateLabel . ":\nما في مواعيد مسجّلة.";
        }

        return 'برنامج ' . $workerLabel . ' يوم ' . $dateLabel . ":\n" . implode("\n", $appointments);
    }

    private function handleQueryPendingTasks(): string
    {
        $users = $this->loadUsersForMatching();
        $usersById = [];
        foreach ($users as $user) {
            $usersById[(string) ($user['id'] ?? '')] = $user;
        }

        $openStatuses = ['OPEN', 'IN_PROGRESS'];
        $grouped = [];

        foreach ($this->agentsApi->getIncomingTasks() as $task) {
            $status = (string) ($task['status'] ?? '');
            if (!in_array($status, $openStatuses, true)) {
                continue;
            }

            $toUser = is_array($task['toUser'] ?? null) ? $task['toUser'] : null;
            $toUserId = (string) ($task['toUserId'] ?? ($toUser['id'] ?? ''));
            if ($toUserId === '') {
                continue;
            }

            $workerLabel = (string) (
                $toUser['fullName']
                ?? $usersById[$toUserId]['fullName']
                ?? $usersById[$toUserId]['username']
                ?? 'عامل'
            );

            $title = trim((string) ($task['title'] ?? 'مهمة بدون عنوان'));
            $grouped[$workerLabel][] = '• ' . $title;
        }

        if ($grouped === []) {
            return 'ما في مهمات مفتوحة حالياً عند أي عامل ✅';
        }

        $lines = ['مهمات لسه ما انعملت:'];
        foreach ($grouped as $worker => $tasks) {
            $lines[] = '';
            $lines[] = $worker . ':';
            array_push($lines, ...$tasks);
        }

        return implode("\n", $lines);
    }

    private function greetingReply(bool $isCeo): string
    {
        if ($isCeo) {
            return "أهلاً 👋\n"
                . "بحكيلي:\n"
                . "• مهمة لعامل (مثال: ودي مهمه لاسامه يفحص مطعم الماري)\n"
                . "• برنامج عامل (مثال: شو برنامج احمد اليوم)\n"
                . "• مهمات مفتوحة (مثال: شو في مهمات لسه ما تساوت؟)";
        }

        return "أهلاً 👋\n"
            . 'ابعتلي مهمة لعامل، مثال: "ابعث مهمه لعبد قله يفحص التعديلات الجديده".';
    }

    private function unknownReply(bool $isCeo): string
    {
        return $isCeo
            ? 'ما فهمت الطلب. جرب: مهمة لعامل، برنامج عامل اليوم، أو المهمات المفتوحة.'
            : 'ما فهمت الطلب. اكتبلي مهمة لعامل مع اسمه وشو المطلوب منه.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadUsersForMatching(): array
    {
        try {
            return $this->agentsApi->getAssignableUsers();
        } catch (\Throwable) {
            return $this->agentsApi->getAllUsers();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $users
     * @return array<string, mixed>|null
     */
    private function matchUserByName(array $users, string $needle): ?array
    {
        $needleNorm = $this->normalizeName($needle);
        if ($needleNorm === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($users as $user) {
            $candidates = array_filter([
                $user['fullName'] ?? null,
                $user['username'] ?? null,
            ], static fn ($value) => is_string($value) && trim($value) !== '');

            foreach ($candidates as $candidate) {
                $candidateNorm = $this->normalizeName($candidate);
                if ($candidateNorm === '') {
                    continue;
                }

                $score = 0;
                if ($candidateNorm === $needleNorm) {
                    $score = 100;
                } elseif (str_contains($candidateNorm, $needleNorm) || str_contains($needleNorm, $candidateNorm)) {
                    $score = 80;
                } else {
                    similar_text($needleNorm, $candidateNorm, $percent);
                    $score = (int) $percent;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $user;
                }
            }
        }

        return $bestScore >= 55 ? $best : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $clients
     * @return array<string, mixed>|null
     */
    private function matchClientByName(array $clients, string $needle): ?array
    {
        $needleNorm = $this->normalizeName($needle);
        if ($needleNorm === '') {
            return null;
        }

        foreach ($clients as $client) {
            foreach (['restName', 'fullName'] as $field) {
                $value = $client[$field] ?? null;
                if (!is_string($value)) {
                    continue;
                }

                $candidateNorm = $this->normalizeName($value);
                if ($candidateNorm === $needleNorm || str_contains($candidateNorm, $needleNorm)) {
                    return $client;
                }
            }
        }

        return null;
    }

    private function resolveScheduleDate(?string $value): Carbon
    {
        $timezone = 'Asia/Jerusalem';
        $value = mb_strtolower(trim((string) $value));

        if ($value === '' || $value === 'today' || str_contains($value, 'اليوم') || str_contains($value, 'היום')) {
            return Carbon::now($timezone)->startOfDay();
        }

        try {
            return Carbon::parse($value, $timezone)->startOfDay();
        } catch (\Throwable) {
            return Carbon::now($timezone)->startOfDay();
        }
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        $aliases = [
            'اسامه' => 'osama',
            'اسامة' => 'osama',
            'أوسامة' => 'osama',
            'عبد' => 'abed',
            'عبدالله' => 'abed',
            'احمد' => 'ahmad',
            'أحمد' => 'ahmad',
        ];

        return $aliases[$value] ?? $value;
    }

  /**
     * @param  array<int, array{role:string,content:string}>  $messages
     * @param  array<string, mixed>  $options
     */
    private function chatWithOpenAi(array $messages, array $options = []): string
    {
        $apiKey = trim((string) (config('openai.api_key') ?: env('OPENAI_API_KEY', '')));
        $baseUrl = (string) (config('openai.base_uri') ?: 'https://api.openai.com/v1');
        $model = (string) ($options['model'] ?? config('openai.default_model', 'gpt-4o-mini'));

        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is missing.');
        }

        $http = Http::withToken($apiKey)
            ->timeout((int) config('openai.request_timeout', 30))
            ->acceptJson();

        if (config('openai.organization')) {
            $http = $http->withHeaders(['OpenAI-Organization' => config('openai.organization')]);
        }

        if (!filter_var(config('openai.ssl_verify', config('services.openai.verify_ssl', true)), FILTER_VALIDATE_BOOLEAN)) {
            $http = $http->withoutVerifying();
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? 400,
        ];

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        $endpoint = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.openai.com/v1', '/') . '/chat/completions';

        try {
            $response = $http->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('OpenAI connection failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->successful()) {
            throw new RuntimeException('OpenAI request failed with status ' . $response->status());
        }

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? trim($content) : '';
    }

    /**
     * @return array{
     *   worker_name:?string,
     *   task_title:?string,
     *   task_description:?string,
     *   client_name:?string,
     *   schedule_worker_name:?string,
     *   schedule_date:?string
     * }
     */
    private function emptyIntentFields(): array
    {
        return [
            'worker_name' => null,
            'task_title' => null,
            'task_description' => null,
            'client_name' => null,
            'schedule_worker_name' => null,
            'schedule_date' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
