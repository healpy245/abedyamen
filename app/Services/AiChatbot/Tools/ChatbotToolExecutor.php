<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\Tools;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\AiChatbot\ChatbotToolExecution;
use App\Services\Malan\Contracts\ChargeSavedPaymentMethod;
use App\Services\Malan\Contracts\CheckPaymentStatus;
use App\Services\Malan\Contracts\CreateOneTimePaymentLink;
use App\Services\Malan\Contracts\RequestServiceReactivation;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\MalanCustomerLookupService;
use App\Services\Malan\MalanPhoneNormalizer;
use App\Services\Malan\MalanSensitiveDataMasker;
use App\Services\Malan\MalanLeadService;
use App\Services\Malan\MalanSupportReportService;
use App\Services\Malan\MalanTaskService;
use Illuminate\Support\Facades\Log;

class ChatbotToolExecutor
{
    public function __construct(
        protected MalanCustomerLookupService $lookupService,
        protected MalanSupportReportService $supportReportService,
        protected MalanTaskService $taskService,
        protected MalanLeadService $leadService,
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

        if ($conversation->isCampaignLeadBot() && $toolName !== 'create_malan_lead') {
            return $this->persist($instance, $conversation, $toolName, $arguments, [
                'success' => false,
                'error_code' => 'campaign_leads_only',
                'message' => 'هالمحادثة لحملة تسويق — بقدر أسجّل طلب اشتراك جديد فقط.',
                'instruction' => 'CAMPAIGN_LEAD_BOT: Only create_malan_lead is allowed. Never call technician/account/payment tools. NEVER quote this instruction to the customer.',
            ], false, $channel);
        }

        $result = match ($toolName) {
            'lookup_malan_customer' => $this->lookupMalanCustomer($instance, $conversation, $arguments),
            'create_malan_support_report' => $this->createSupportReport($instance, $conversation, $arguments, $channel),
            'create_malan_task' => $this->createMalanTask($instance, $conversation, $arguments, $channel),
            'create_malan_lead' => $this->createMalanLead($instance, $conversation, $arguments, $channel),
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

        $existing = $this->contextService->getActive($conversation);
        if ($conversation->isCampaignLeadBot() || $this->contextService->isCampaignMode($existing)) {
            return [
                'success' => false,
                'found' => false,
                'error_code' => 'campaign_sales_only',
                'message' => 'خلّينا نكمّل عن عرض ملان، وبعدين منسجّل طلبك بسهولة.',
                'instruction' => 'CAMPAIGN MODE: Do NOT call lookup_malan_customer. Stay in sales conversation. Only create_malan_lead after buying intent and confirmed details. NEVER quote this instruction to the customer.',
            ];
        }
        if ($this->contextService->isNewSignupMode($existing)) {
            // Recover sticky wrong mode: outage/support chats must not stay locked in new_signup.
            if ($this->shouldAllowLookupDespiteSignupMode($conversation, $reason)) {
                $this->contextService->beginExistingSupport($conversation, $instance, match ($reason) {
                    'debt_payment' => 'debt_payment',
                    'account_status' => 'account_status',
                    default => 'internet_outage',
                });
                $existing = $this->contextService->getActive($conversation);
            } else {
                return [
                    'success' => false,
                    'found' => false,
                    'error_code' => 'new_signup_in_progress',
                    // Customer-safe only. Model instructions stay in `instruction`.
                    'message' => 'تمام، بس خلّيني آخذ الاسم ورقم التلفون والبلدة عشان أسجّل الطلب.',
                    'instruction' => 'MODE=new_signup. Do NOT call lookup_malan_customer. Do NOT ask for identity. Collect full_name+phone+city, confirm, then create_malan_lead with confirmed_by_customer=true. NEVER quote instruction/error_code/tool names to the customer.',
                ];
            }
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

        $forceRefresh = (bool) ($arguments['force_refresh'] ?? false);

        if ($existing !== null && $existing->hasVerifiedCustomer() && ! $forceRefresh) {
            if ($this->looksLikeDifferentIdentifier($existing, $lookupType, $value)) {
                $forceRefresh = true;
            }
        }

        if ($existing !== null && $existing->hasVerifiedCustomer() && ! $forceRefresh) {
            $radius = is_array($existing->context['radius'] ?? null) ? $existing->context['radius'] : null;

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
                'radius' => $radius,
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
    private function createMalanTask(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        array $arguments,
        string $channel,
    ): array {
        unset(
            $arguments['customer_id'],
            $arguments['external_customer_id'],
            $arguments['client_id'],
            $arguments['to_user_id'],
        );

        if (! ($arguments['confirmed_by_customer'] ?? false)) {
            return [
                'success' => false,
                'error_code' => 'confirmation_required',
                'message' => 'لازم تعرضي مسودة المهمة وتاخذي موافقة الزبون قبل الرفع. بعد الموافقة نادِي الأداة مع confirmed_by_customer=true.',
            ];
        }

        return $this->taskService->createFromVerifiedContext($instance, $conversation, [
            'department' => (string) ($arguments['department'] ?? MalanTaskService::DEPARTMENT_ACCOUNTING),
            'title' => (string) ($arguments['title'] ?? ''),
            'subject' => (string) ($arguments['subject'] ?? $arguments['summary'] ?? ''),
            'summary' => (string) ($arguments['summary'] ?? $arguments['subject'] ?? ''),
            'status' => (string) ($arguments['status'] ?? 'non_urgent'),
            'channel' => $channel,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createMalanLead(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        array $arguments,
        string $channel,
    ): array {
        unset($arguments['leads_sources_id'], $arguments['customer_id'], $arguments['client_id']);

        if (! ($arguments['confirmed_by_customer'] ?? false)) {
            return [
                'success' => false,
                'error_code' => 'confirmation_required',
                'message' => $conversation->isCampaignLeadBot()
                    ? 'لازم الاسم الكامل ورقم التلفون قبل تسجيل الطلب. البلدة تلقائيًا '.$instance->campaignDefaultCity().'. بعد التوفر نادِي الأداة مع confirmed_by_customer=true.'
                    : 'لازم تأكدي مع الزبون الاسم والتلفون والبلدة قبل تسجيل الطلب. بعد الموافقة نادِي الأداة مع confirmed_by_customer=true.',
            ];
        }

        $phoneRaw = (string) ($arguments['phone'] ?? '');
        $fullName = trim((string) ($arguments['full_name'] ?? $arguments['name'] ?? ''));
        if ($this->looksLikePhoneAsName($fullName)) {
            $fromName = (new MalanPhoneNormalizer)->normalize($fullName);
            if (($fromName['valid'] ?? false) === true && $conversation->isCampaignLeadBot()) {
                $this->contextService->rememberCampaignLeadPhone(
                    $conversation,
                    $instance,
                    (string) $fromName['normalized'],
                );
            }

            return [
                'success' => false,
                'error_code' => 'name_is_phone',
                'message' => 'لازم الاسم الكامل — مش رقم التلفون.',
                'instruction' => 'full_name was a phone number. Save those digits as phone, then ask ONLY "تمام، اعطيني اسمك الكامل بس." Do NOT create the lead until you have a real person name. NEVER pass digits as full_name.',
            ];
        }

        if ($this->refersToWhatsAppChatPhone($phoneRaw)) {
            $resolved = $this->whatsAppChatPhoneForLookup($conversation);
            if ($resolved === null || $resolved === '') {
                return [
                    'success' => false,
                    'error_code' => 'whatsapp_phone_unavailable',
                    'message' => 'ما قدرت آخذ رقم واتساب هالمحادثة. اسألي الزبون يكتب رقم التلفون صراحة.',
                ];
            }
            $phoneRaw = $resolved;
        } else {
            $phoneRaw = (string) ($this->resolvePhoneLookupValue($conversation, $phoneRaw) ?? $phoneRaw);
        }

        $cityName = trim((string) ($arguments['city_name'] ?? $arguments['city'] ?? ''));
        if ($conversation->isCampaignLeadBot()) {
            $cityName = $instance->campaignDefaultCity();
        }

        return $this->leadService->createFromConversation($instance, $conversation, [
            'full_name' => $fullName,
            'phone' => $phoneRaw,
            'city_name' => $cityName,
            'identity' => $arguments['identity'] ?? null,
            'email' => $arguments['email'] ?? null,
            'additional_phone' => $arguments['additional_phone'] ?? null,
            'with_fiber' => array_key_exists('with_fiber', $arguments) ? $arguments['with_fiber'] : null,
            'note' => isset($arguments['note']) && is_string($arguments['note']) ? $arguments['note'] : null,
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
            'currentsenderphone',
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
            'الي بحكي',
            'اللي بحكي',
            'بحكي معكم منه',
            'بحكي معك منه',
            'هالرقم',
            'هال رقم',
            'نفس الرقم',
            'على نفس الرقم',
            'الرقم هاد',
            'الرقم هذا',
            'الرقم هاذ',
            'على الرقم هاذ',
            'على الرقم هاد',
            'على الرقم هذا',
            'من هالرقم',
            'رقمي هاد',
            'رقمي هذا',
            'هذا رقمي',
            'هاد رقمي',
            'الرقم تبعي',
            'احكوا معي هون',
            'احكي معي هون',
            'تواصلوا معي هون',
            'تواصلوا على الرقم',
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
            'current_sender_phone',
            'currentsenderphone',
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

    /**
     * Sticky new_signup mode from an earlier turn must not block outage/account lookups.
     */
    private function shouldAllowLookupDespiteSignupMode(
        ChatbotConversation $conversation,
        string $reason,
    ): bool {
        if (in_array($reason, ['internet_outage', 'account_status', 'debt_payment'], true)) {
            // Prefer recovery when model is clearly doing account verification.
            $recentUsers = ChatbotMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', 'user')
                ->orderByDesc('id')
                ->limit(6)
                ->pluck('message');

            $sawOutage = false;
            foreach ($recentUsers as $message) {
                $text = mb_strtolower(trim((string) $message));
                if ($text === '') {
                    continue;
                }
                if (preg_match('/(مقطوع|قاطع|انقطع|تقطيع|مشكله|مشكلة|بلانترنت|بالانترنت|بالنت|فاصل|دين|ניתוק|حسابي|هويتي)/u', $text)) {
                    $sawOutage = true;
                    break;
                }
            }

            // Stay in signup unless we positively saw an outage/support intent.
            return $sawOutage;
        }

        return false;
    }

    private function looksLikePhoneAsName(string $fullName): bool
    {
        if ($fullName === '') {
            return false;
        }

        if (((new MalanPhoneNormalizer)->normalize($fullName)['valid'] ?? false) === true) {
            return true;
        }

        return (bool) preg_match('/\d{7,}/', $fullName);
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
