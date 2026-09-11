<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanSupportReport;
use App\Services\Malan\Exceptions\MalanApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Create Malan CRM tasks (apiClient/createTask) after customer confirmation.
 */
class MalanTaskService
{
    public const DEPARTMENT_ACCOUNTING = 'accounting';

    public const DEPARTMENT_TECHNICAL = 'technical';

    public function __construct(
        protected MalanApiClient $apiClient,
        protected MalanConversationContextService $contextService,
    ) {}

    /**
     * @param  array{
     *     department?: string,
     *     title?: string,
     *     subject?: string,
     *     summary?: string,
     *     status?: string|null,
     *     channel?: string,
     *     metadata?: array<string, mixed>
     * }  $input
     * @return array<string, mixed>
     */
    public function createFromVerifiedContext(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        array $input = [],
    ): array {
        if (! $instance->hasMalanIntegration()) {
            return [
                'success' => false,
                'message' => 'تكامل ملان غير مفعّل لهذا البوت.',
            ];
        }

        $context = $this->contextService->getActive($conversation);
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return [
                'success' => false,
                'message' => 'لازم نفحص الحساب أولًا قبل رفع المهمة.',
            ];
        }

        if ((int) $context->chatbot_instance_id !== (int) $instance->id) {
            throw new RuntimeException('Conversation context instance mismatch.');
        }

        $department = (string) ($input['department'] ?? self::DEPARTMENT_ACCOUNTING);
        if (! in_array($department, [self::DEPARTMENT_ACCOUNTING, self::DEPARTMENT_TECHNICAL], true)) {
            return [
                'success' => false,
                'message' => 'قسم المهمة غير معروف.',
            ];
        }

        $toUserId = $this->assigneeUserId($department);
        if ($toUserId <= 0) {
            return [
                'success' => false,
                'error_code' => 'assignee_not_configured',
                'message' => 'ما في مستخدم مخصّص لهذا القسم بالمهمة. بحوّل لموظف يتابع.',
            ];
        }

        $subject = trim((string) ($input['subject'] ?? $input['summary'] ?? ''));
        if ($subject === '') {
            return [
                'success' => false,
                'message' => 'نص المهمة فاضي. لازم ملخّص متفق عليه مع الزبون.',
            ];
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = $department === self::DEPARTMENT_ACCOUNTING
                ? 'متابعة محاسبة — ניתוק חוב'
                : 'بلاغ دعم فني — انقطاع نت';
        }

        $status = trim((string) ($input['status'] ?? config('malan.tasks.default_status', 'non_urgent')));
        if (! in_array($status, ['urgent', 'non_urgent'], true)) {
            $status = 'non_urgent';
        }

        $windowMinutes = (int) config('malan.support_report_duplicate_window_minutes', 30);
        $duplicate = MalanSupportReport::query()
            ->where('chatbot_instance_id', $instance->id)
            ->where('conversation_id', $conversation->id)
            ->where('external_customer_id', $context->verified_customer_id)
            ->where('issue_type', $department)
            ->where('created_at', '>=', Carbon::now()->subMinutes($windowMinutes))
            ->latest('id')
            ->first();

        if ($duplicate !== null) {
            return [
                'success' => true,
                'duplicate' => true,
                'report_id' => $duplicate->id,
                'task_id' => $duplicate->metadata['malan_task_id'] ?? null,
                'department' => $department,
                'message' => 'المهمة مسجّلة مسبقًا لهالمحادثة.',
            ];
        }

        $clientId = is_numeric($context->verified_customer_id)
            ? (int) $context->verified_customer_id
            : null;

        try {
            $apiResult = $this->apiClient->createTask([
                'title' => $title,
                'subject' => $subject,
                'to_user_id' => $toUserId,
                'status' => $status,
                'client_id' => $clientId,
            ]);
        } catch (MalanApiException $e) {
            Log::warning('Malan createTask failed', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
                'department' => $department,
                'error_code' => $e->errorCode,
                'http_status' => $e->httpStatus,
            ]);

            return [
                'success' => false,
                'error_code' => $e->errorCode,
                'message' => $e->userMessage,
            ];
        } catch (Throwable $e) {
            Log::error('Malan createTask unexpected failure', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'صار خلل مؤقت وما قدرت أرفع المهمة. بحوّل لموظف يتابع معك.',
            ];
        }

        $report = MalanSupportReport::query()->create([
            'chatbot_instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'external_customer_id' => $context->verified_customer_id,
            'customer_name' => $context->verified_customer_name,
            'customer_phone_masked' => $context->verified_phone_masked,
            'issue_type' => $department,
            'summary' => $subject,
            'status' => 'OPEN',
            'source_channel' => (string) ($input['channel'] ?? 'web'),
            'metadata' => array_merge([
                'customer_status' => $context->customer_status,
                'debt_amount' => $context->debt_amount,
                'department' => $department,
                'to_user_id' => $toUserId,
                'task_title' => $title,
                'task_status' => $status,
                'malan_task_id' => $apiResult['task_id'] ?? null,
                'malan_http_status' => $apiResult['http_status'] ?? null,
                'via' => 'create_malan_task',
            ], is_array($input['metadata'] ?? null) ? $input['metadata'] : []),
        ]);

        $this->contextService->setPendingFlow(
            $conversation,
            $instance,
            $department === self::DEPARTMENT_ACCOUNTING ? 'accounting_task_open' : 'support_report_open',
        );

        return [
            'success' => true,
            'duplicate' => false,
            'report_id' => $report->id,
            'task_id' => $apiResult['task_id'] ?? null,
            'department' => $department,
            'to_user_id' => $toUserId,
            'message' => $department === self::DEPARTMENT_ACCOUNTING
                ? 'تم رفع المهمة للمحاسبة بنجاح.'
                : 'تم رفع المهمة للدعم الفني بنجاح.',
        ];
    }

    private function assigneeUserId(string $department): int
    {
        return match ($department) {
            self::DEPARTMENT_ACCOUNTING => (int) config('malan.tasks.accounting_user_id', 147),
            self::DEPARTMENT_TECHNICAL => (int) config('malan.tasks.technical_user_id', 147),
            default => 0,
        };
    }
}
