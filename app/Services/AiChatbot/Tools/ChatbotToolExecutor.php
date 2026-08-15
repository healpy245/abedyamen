<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\Tools;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotToolExecution;
use App\Services\Malan\Contracts\ChargeSavedPaymentMethod;
use App\Services\Malan\Contracts\CheckPaymentStatus;
use App\Services\Malan\Contracts\CreateOneTimePaymentLink;
use App\Services\Malan\Contracts\RequestServiceReactivation;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\MalanCustomerLookupService;
use App\Services\Malan\MalanPhoneNormalizer;
use App\Services\Malan\MalanSensitiveDataMasker;
use App\Services\Malan\MalanSupportReportService;
use Illuminate\Support\Facades\Log;

class ChatbotToolExecutor
{
    public function __construct(
        protected MalanCustomerLookupService $lookupService,
        protected MalanSupportReportService $supportReportService,
        protected MalanConversationContextService $contextService,
        protected ChargeSavedPaymentMethod $chargeSavedPaymentMethod,
        protected CreateOneTimePaymentLink $createOneTimePaymentLink,
        protected CheckPaymentStatus $checkPaymentStatus,
        protected RequestServiceReactivation $requestServiceReactivation,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $toolName,
        array $arguments,
        string $channel = 'web',
    ): array {
        if (! $instance->hasMalanIntegration()) {
            return $this->persist($instance, $conversation, $toolName, $arguments, [
                'success' => false,
                'message' => 'Tool not available for this chatbot instance.',
            ], false, $channel);
        }

        if ((int) $conversation->instance_id !== (int) $instance->id) {
            return $this->persist($instance, $conversation, $toolName, $arguments, [
                'success' => false,
                'message' => 'Conversation/instance mismatch.',
            ], false, $channel);
        }

        $result = match ($toolName) {
            'lookup_malan_customer' => $this->lookupMalanCustomer($instance, $conversation, $arguments),
            'create_malan_support_report' => $this->createSupportReport($instance, $conversation, $arguments, $channel),
            'set_malan_payment_method_preference' => $this->setPaymentMethod($instance, $conversation, $arguments),
            'charge_malan_saved_payment_method' => $this->chargeSaved($instance, $conversation, $arguments, $channel),
            'create_malan_one_time_payment_link' => $this->createPaymentLink($instance, $conversation, $arguments, $channel),
            'check_malan_payment_status' => $this->checkPayment($arguments),
            'request_malan_service_reactivation' => $this->reactivate($instance, $conversation, $arguments, $channel),
            default => [
                'success' => false,
                'message' => 'Unknown tool.',
            ],
        };

        $success = (bool) ($result['success'] ?? false);

        return $this->persist(
            $instance,
            $conversation,
            $toolName,
            $arguments,
            $result,
            $success,
            $channel,
            isset($result['report_id']) ? (string) $result['report_id'] : ($result['customer']['id'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function lookupMalanCustomer(ChatbotInstance $instance, ChatbotConversation $conversation, array $arguments): array
    {
        $lookupType = (string) ($arguments['lookup_type'] ?? '');
        $value = (string) ($arguments['value'] ?? '');
        $reason = (string) ($arguments['reason'] ?? 'internet_outage');

        if (! in_array($lookupType, ['phone', 'identity'], true) || $value === '') {
            return [
                'success' => false,
                'found' => false,
                'error_code' => 'invalid_arguments',
                'message' => 'تأكدلي من الرقم وابعته مرة ثانية.',
            ];
        }

        if ($lookupType === 'phone') {
            $resolved = $this->resolvePhoneLookupValue($conversation, $value);
            if ($resolved === null) {
                return [
                    'success' => false,
                    'found' => false,
                    'error_code' => 'missing_whatsapp_phone',
                    'message' => 'ما قدرت آخذ رقم الواتساب من المحادثة. ابعتلي الرقم المسجّل عندنا أرقام.',
                ];
            }
            $value = $resolved;
        }

        $existing = $this->contextService->getActive($conversation);
        $forceRefresh = (bool) ($arguments['force_refresh'] ?? false);

        if ($existing !== null && $existing->hasVerifiedCustomer() && ! $forceRefresh) {
            if ($this->looksLikeDifferentIdentifier($existing, $lookupType, $value)) {
                $forceRefresh = true;
            }
        }

        if ($existing !== null && $existing->hasVerifiedCustomer() && ! $forceRefresh) {
            return [
                'success' => true,
                'found' => true,
                'already_verified' => true,
                'customer' => [
                    'id' => $existing->verified_customer_id,
                    'name' => $existing->verified_customer_name,
                    'phone_masked' => $existing->verified_phone_masked,
                    'identity_masked' => $existing->verified_identity_masked,
                    'status' => $existing->customer_status,
                ],
                'financial' => [
                    'debt_amount' => $existing->debt_amount !== null ? (float) $existing->debt_amount : null,
                    'currency' => 'ILS',
                ],
                'message' => 'الحساب متحقق مسبقًا داخل هالمحادثة.',
            ];
        }

        $result = $this->lookupService->lookup($instance, $lookupType, $value);

        if ($result->success && $result->found) {
            $this->contextService->storeLookupResult(
                $conversation,
                $instance,
                $result,
                match ($reason) {
                    'debt_payment' => 'debt_payment',
                    'account_status' => 'account_status',
                    default => 'internet_outage',
                },
            );

            $payload = $result->toToolPayload();
            $payload['bank_transfer'] = [
                'name' => config('malan.bank.name'),
                'branch' => config('malan.bank.branch'),
                'account' => config('malan.bank.account'),
            ];

            if (($result->customer['status'] ?? null) === 'UNKNOWN') {
                $payload['message'] = 'قدرت أوصل للحساب، بس حالة الخط بدها فحص من موظف مختص. رح أطلب منهم يتابعوا معك.';
                $payload['escalate'] = true;
            }

            return $payload;
        }

        return $result->toToolPayload();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createSupportReport(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        array $arguments,
        string $channel,
    ): array {
        // Ignore any customer_id the model may invent.
        unset($arguments['customer_id'], $arguments['external_customer_id']);

        if (! ($arguments['confirmed_by_customer'] ?? false)) {
            return [
                'success' => false,
                'error_code' => 'confirmation_required',
                'message' => 'لازم تعرضي مسودة المهمة وتاخذي موافقة الزبون قبل رفع البلاغ. بعد الموافقة نادِي الأداة مع confirmed_by_customer=true.',
            ];
        }

        return $this->supportReportService->createFromVerifiedContext($instance, $conversation, [
            'issue_type' => (string) ($arguments['issue_type'] ?? 'full_outage'),
            'summary' => (string) ($arguments['summary'] ?? ''),
            'channel' => $channel,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function setPaymentMethod(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        array $arguments,
    ): array {
        $method = (string) ($arguments['payment_method'] ?? '');
        if (! in_array($method, ['bank_transfer', 'visa_saved', 'visa_other'], true)) {
            return ['success' => false, 'message' => 'طريقة الدفع غير صالحة.'];
        }

        $context = $this->contextService->getActive($conversation);
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return ['success' => false, 'message' => 'لازم نفحص الحساب أولًا.'];
        }

        $pendingFlow = match ($method) {
            'bank_transfer' => 'awaiting_bank_transfer_proof',
            'visa_saved' => 'visa_saved_pending',
            'visa_other' => 'visa_other_pending',
        };

        $this->contextService->setPaymentMethod($conversation, $instance, $method, $pendingFlow);

        $payload = [
            'success' => true,
            'payment_method' => $method,
            'pending_flow' => $pendingFlow,
            'debt_amount' => $context->debt_amount !== null ? (float) $context->debt_amount : null,
        ];

        if ($method === 'bank_transfer') {
            $payload['bank'] = [
                'name' => config('malan.bank.name'),
                'branch' => config('malan.bank.branch'),
                'account' => config('malan.bank.account'),
            ];
            $payload['message'] = 'تم تسجيل اختيار التحويل البنكي. أعطي تفاصيل البنك واطلب صورة الإثبات.';
        }

        if ($method === 'visa_saved') {
            $payload['message'] = 'تم تسجيل اختيار البطاقة المسجلة. استخدم أداة الدفع عند التأكيد.';
        }

        if ($method === 'visa_other') {
            $payload['message'] = 'تم تسجيل اختيار بطاقة ثانية. لا تطلب بيانات البطاقة داخل المحادثة.';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function chargeSaved(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        array $arguments,
        string $channel,
    ): array {
        if (! ($arguments['confirmed_by_customer'] ?? false)) {
            return ['success' => false, 'message' => 'لازم تأكيد الزبون قبل محاولة الدفع.'];
        }

        $context = $this->contextService->getActive($conversation);
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return ['success' => false, 'message' => 'لازم نفحص الحساب أولًا.'];
        }

        if ($context->debt_amount === null) {
            return ['success' => false, 'message' => 'مبلغ الدين غير متوفر بشكل موثوق.'];
        }

        return $this->chargeSavedPaymentMethod->charge([
            'confirmed_by_customer' => true,
            'customer_id' => (string) $context->verified_customer_id,
            'amount' => (float) $context->debt_amount,
            'conversation_id' => $conversation->id,
            'channel' => $channel,
            'instance_id' => $instance->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createPaymentLink(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        array $arguments,
        string $channel,
    ): array {
        if (! ($arguments['confirmed_by_customer'] ?? false)) {
            return ['success' => false, 'message' => 'لازم تأكيد الزبون قبل إنشاء رابط الدفع.'];
        }

        $context = $this->contextService->getActive($conversation);
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return ['success' => false, 'message' => 'لازم نفحص الحساب أولًا.'];
        }

        if ($context->debt_amount === null) {
            return ['success' => false, 'message' => 'مبلغ الدين غير متوفر بشكل موثوق.'];
        }

        return $this->createOneTimePaymentLink->create([
            'confirmed_by_customer' => true,
            'customer_id' => (string) $context->verified_customer_id,
            'amount' => (float) $context->debt_amount,
            'conversation_id' => $conversation->id,
            'delivery_channel' => (string) ($arguments['delivery_channel'] ?? $channel),
            'instance_id' => $instance->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function checkPayment(array $arguments): array
    {
        $id = trim((string) ($arguments['payment_attempt_id'] ?? ''));
        if ($id === '') {
            return ['success' => false, 'message' => 'معرف محاولة الدفع مطلوب.'];
        }

        return $this->checkPaymentStatus->check($id);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function reactivate(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        array $arguments,
        string $channel,
    ): array {
        $context = $this->contextService->getActive($conversation);
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return ['success' => false, 'message' => 'لازم نفحص الحساب أولًا.'];
        }

        return $this->requestServiceReactivation->request([
            'customer_id' => (string) $context->verified_customer_id,
            'conversation_id' => $conversation->id,
            'reason' => (string) ($arguments['reason'] ?? 'payment_verified'),
            'channel' => $channel,
            'instance_id' => $instance->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function persist(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        string $toolName,
        array $arguments,
        array $result,
        bool $success,
        string $channel,
        ?string $externalReference = null,
    ): array {
        $safeArgs = MalanSensitiveDataMasker::sanitizeToolArguments($arguments);
        $safeResult = MalanSensitiveDataMasker::sanitizeToolArguments($result);

        ChatbotToolExecution::query()->create([
            'conversation_id' => $conversation->id,
            'chatbot_instance_id' => $instance->id,
            'tool_name' => $toolName,
            'arguments' => $safeArgs,
            'result' => $safeResult,
            'success' => $success,
            'external_reference' => $externalReference,
            'channel' => $channel,
        ]);

        Log::info('Chatbot tool executed', [
            'instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'tool' => $toolName,
            'success' => $success,
            'channel' => $channel,
            'arguments' => $safeArgs,
            'result_summary' => [
                'success' => $safeResult['success'] ?? null,
                'found' => $safeResult['found'] ?? null,
                'error_code' => $safeResult['error_code'] ?? null,
                'integration_pending' => $safeResult['integration_pending'] ?? null,
            ],
        ]);

        return $result;
    }

    /**
     * When the customer means "the WhatsApp number I'm chatting from", resolve to conversation contact phone.
     */
    private function resolvePhoneLookupValue(ChatbotConversation $conversation, string $value): ?string
    {
        $trimmed = trim($value);
        if (! $this->refersToWhatsAppChatPhone($trimmed)) {
            return $trimmed;
        }

        return $this->whatsAppChatPhoneForLookup($conversation);
    }

    private function refersToWhatsAppChatPhone(string $value): bool
    {
        $compact = strtolower(preg_replace('/[\s\-_]+/u', '', $value) ?? $value);
        $sentinels = [
            'whatsappchatphone',
            'whatsapp_phone',
            'whatsappphone',
            'thiswhatsappnumber',
            'currentchatphone',
            'chatchatphone',
            'chatphone',
            'thisnumber',
            'samenumber',
            'mywhatsapp',
            'fromthisnumber',
        ];
        if (in_array($compact, $sentinels, true)) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) >= 9) {
            return false;
        }

        $haystack = mb_strtolower($value);
        $needles = [
            'بحكي منه',
            'بحكي منه',
            'الي بحكي',
            'اللي بحكي',
            'هالرقم',
            'هال رقم',
            'نفس الرقم',
            'الرقم هاد',
            'الرقم هذا',
            'من هالرقم',
            'رقمي هاد',
            'رقمي هذا',
            'الرقم تبعي',
            'رقم שאני',
            'מהמספר שאני',
            'המספר שאני מדבר',
            'מהוואטסאפ',
            'this number',
            'same number',
            'my whatsapp',
            'whatsapp number',
            'number i am',
            "number i'm",
            'chatting from',
            'talking from',
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function whatsAppChatPhoneForLookup(ChatbotConversation $conversation): ?string
    {
        $candidates = [];
        if (is_string($conversation->contact_phone) && trim($conversation->contact_phone) !== '') {
            $candidates[] = trim($conversation->contact_phone);
        }
        if (is_string($conversation->external_chat_id) && trim($conversation->external_chat_id) !== '') {
            $fromChat = preg_replace('/@.*$/', '', trim($conversation->external_chat_id));
            if (is_string($fromChat) && $fromChat !== '') {
                $candidates[] = $fromChat;
            }
        }

        $normalizer = new MalanPhoneNormalizer;
        foreach ($candidates as $candidate) {
            $normalized = $normalizer->normalize($candidate);
            if (($normalized['valid'] ?? false) === true && is_string($normalized['normalized'] ?? null)) {
                return $normalized['normalized'];
            }
        }

        return null;
    }

    private function looksLikeDifferentIdentifier(
        \App\Models\AiChatbot\ChatbotConversationContext $existing,
        string $lookupType,
        string $value,
    ): bool {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return true;
        }

        $masked = $lookupType === 'phone'
            ? (string) ($existing->verified_phone_masked ?? '')
            : (string) ($existing->verified_identity_masked ?? '');
        $maskedDigits = preg_replace('/\D+/', '', $masked) ?? '';
        if ($maskedDigits === '') {
            return true;
        }

        $tailNew = substr($digits, -4);
        $tailMasked = substr($maskedDigits, -4);

        return $tailNew !== '' && $tailMasked !== '' && $tailNew !== $tailMasked;
    }
}
