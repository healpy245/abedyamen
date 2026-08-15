<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanSupportReport;
use Carbon\Carbon;
use RuntimeException;

class MalanSupportReportService
{
    /**
     * @param  array{
     *     issue_type?: string,
     *     summary?: string,
     *     channel?: string,
     *     metadata?: array<string, mixed>
     * }  $input
     * @return array{success: bool, report_id?: int, duplicate?: bool, message: string}
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

        $contextService = app(MalanConversationContextService::class);
        $context = $contextService->getActive($conversation);

        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return [
                'success' => false,
                'message' => 'لازم نفحص الحساب أولًا قبل رفع بلاغ.',
            ];
        }

        if ((int) $context->chatbot_instance_id !== (int) $instance->id) {
            throw new RuntimeException('Conversation context instance mismatch.');
        }

        $windowMinutes = (int) config('malan.support_report_duplicate_window_minutes', 30);
        $duplicate = MalanSupportReport::query()
            ->where('chatbot_instance_id', $instance->id)
            ->where('conversation_id', $conversation->id)
            ->where('external_customer_id', $context->verified_customer_id)
            ->where('created_at', '>=', Carbon::now()->subMinutes($windowMinutes))
            ->latest('id')
            ->first();

        if ($duplicate !== null) {
            return [
                'success' => true,
                'duplicate' => true,
                'report_id' => $duplicate->id,
                'message' => 'بلاغ الدعم مسجّل مسبقًا لهالمحادثة.',
            ];
        }

        $summary = trim((string) ($input['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'بلاغ انقطاع إنترنت كامل بعد التحقق من الحساب.';
        }

        $report = MalanSupportReport::query()->create([
            'chatbot_instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'external_customer_id' => $context->verified_customer_id,
            'customer_name' => $context->verified_customer_name,
            'customer_phone_masked' => $context->verified_phone_masked,
            'issue_type' => (string) ($input['issue_type'] ?? 'full_outage'),
            'summary' => $summary,
            'status' => 'OPEN',
            'source_channel' => (string) ($input['channel'] ?? 'web'),
            'metadata' => array_merge([
                'customer_status' => $context->customer_status,
                'debt_amount' => $context->debt_amount,
            ], is_array($input['metadata'] ?? null) ? $input['metadata'] : []),
        ]);

        $contextService->setPendingFlow($conversation, $instance, 'support_report_open');

        return [
            'success' => true,
            'duplicate' => false,
            'report_id' => $report->id,
            'message' => 'تم رفع بلاغ الدعم بنجاح.',
        ];
    }
}
