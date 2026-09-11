<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanSupportReport;
use App\Services\Malan\Exceptions\MalanApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Create Malan CRM sales leads (apiClient/createLead) for new sign-ups.
 */
class MalanLeadService
{
    public function __construct(
        protected MalanApiClient $apiClient,
        protected MalanPhoneNormalizer $phoneNormalizer,
        protected MalanConversationContextService $contextService,
        protected MalanConversationMemoryService $memoryService,
    ) {}

    /**
     * @param  array{
     *     full_name?: string,
     *     phone?: string,
     *     city_name?: string|null,
     *     identity?: string|null,
     *     email?: string|null,
     *     additional_phone?: string|null,
     *     with_fiber?: int|bool|null,
     *     note?: string|null,
     *     channel?: string,
     *     metadata?: array<string, mixed>
     * }  $input
     * @return array<string, mixed>
     */
    public function createFromConversation(
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

        $fullName = trim((string) ($input['full_name'] ?? $input['name'] ?? ''));
        if ($fullName === '') {
            return [
                'success' => false,
                'message' => 'لازم الاسم الكامل عشان أسجّل الطلب.',
            ];
        }

        if ($this->looksLikePhoneAsName($fullName)) {
            return [
                'success' => false,
                'error_code' => 'name_is_phone',
                'message' => 'لازم الاسم الكامل — مش رقم التلفون.',
            ];
        }

        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        $normalized = $this->phoneNormalizer->normalize($phoneRaw);
        $phone = $normalized['valid']
            ? (string) $normalized['normalized']
            : (preg_replace('/\D+/', '', $phoneRaw) ?? '');

        if ($phone === '' || strlen($phone) < 5 || strlen($phone) > 20) {
            return [
                'success' => false,
                'message' => 'تأكدلي من رقم التلفون وابعته مرة ثانية.',
            ];
        }

        $cityName = trim((string) ($input['city_name'] ?? $input['city'] ?? ''));
        if ($cityName === '') {
            return [
                'success' => false,
                'message' => 'لازم البلدة/المنطقة عشان أسجّل الطلب.',
            ];
        }

        $windowMinutes = (int) config('malan.leads.duplicate_window_minutes', 30);
        $duplicate = MalanSupportReport::query()
            ->where('chatbot_instance_id', $instance->id)
            ->where('conversation_id', $conversation->id)
            ->where('issue_type', 'new_lead')
            ->where('created_at', '>=', Carbon::now()->subMinutes($windowMinutes))
            ->latest('id')
            ->first();

        if ($duplicate !== null) {
            $this->contextService->setPendingFlow($conversation, $instance, 'new_lead_open');
            $this->markCampaignLeadIfNeeded(
                $conversation,
                isset($duplicate->metadata['malan_lead_id']) ? (int) $duplicate->metadata['malan_lead_id'] : null,
            );

            return [
                'success' => true,
                'duplicate' => true,
                'report_id' => $duplicate->id,
                'lead_id' => $duplicate->metadata['malan_lead_id'] ?? null,
                'message' => 'طلب التسجيل مسجّل مسبقًا لهالمحادثة. مندوب رح يتواصل معك.',
            ];
        }

        try {
            $sourceId = $this->resolveLeadSourceId($conversation);
        } catch (MalanApiException $e) {
            return [
                'success' => false,
                'error_code' => $e->errorCode,
                'message' => $e->userMessage,
            ];
        }

        if ($sourceId <= 0) {
            return [
                'success' => false,
                'error_code' => 'lead_source_not_configured',
                'message' => 'ما قدرت أسجّل الطلب هلق. بحوّل لمندوب يتابع معك.',
            ];
        }

        $withFiber = $this->resolveWithFiber($conversation, $input['with_fiber'] ?? null);

        try {
            $apiResult = $this->apiClient->createLead([
                'full_name' => $fullName,
                'phone' => $phone,
                'leads_sources_id' => $sourceId,
                'city_name' => $cityName,
                'identity' => $input['identity'] ?? null,
                'email' => $input['email'] ?? null,
                'additional_phone' => $input['additional_phone'] ?? null,
                'with_fiber' => $withFiber,
            ]);
        } catch (MalanApiException $e) {
            Log::warning('Malan createLead failed', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
                'error_code' => $e->errorCode,
                'http_status' => $e->httpStatus,
            ]);

            // Duplicate on CRM is still a successful outcome for the customer message.
            // Still mark the campaign contact + keep a local report so ops/analytics are not blank.
            if ($e->errorCode === 'lead_duplicate') {
                $this->contextService->setPendingFlow($conversation, $instance, 'new_lead_open');

                $report = MalanSupportReport::query()
                    ->where('chatbot_instance_id', $instance->id)
                    ->where('conversation_id', $conversation->id)
                    ->where('issue_type', 'new_lead')
                    ->latest('id')
                    ->first();

                if ($report === null) {
                    $report = MalanSupportReport::query()->create([
                        'chatbot_instance_id' => $instance->id,
                        'conversation_id' => $conversation->id,
                        'external_customer_id' => 'lead:'.$phone,
                        'customer_name' => $fullName,
                        'customer_phone_masked' => MalanSensitiveDataMasker::maskPhone($phone),
                        'issue_type' => 'new_lead',
                        'summary' => 'اشتراك جديد — '.$cityName.' (duplicate CRM)',
                        'status' => 'OPEN',
                        'source_channel' => (string) ($input['channel'] ?? 'web'),
                        'metadata' => array_merge([
                            'full_name' => $fullName,
                            'phone_masked' => MalanSensitiveDataMasker::maskPhone($phone),
                            'city_name' => $cityName,
                            'leads_sources_id' => $sourceId,
                            'malan_lead_id' => null,
                            'via' => 'create_malan_lead_duplicate',
                            'crm_duplicate' => true,
                        ], is_array($input['metadata'] ?? null) ? $input['metadata'] : []),
                    ]);
                }

                $this->markCampaignLeadIfNeeded($conversation, null);

                return [
                    'success' => true,
                    'duplicate' => true,
                    'report_id' => $report->id,
                    'lead_id' => $report->metadata['malan_lead_id'] ?? null,
                    'message' => $e->userMessage,
                ];
            }

            return [
                'success' => false,
                'error_code' => $e->errorCode,
                'message' => $e->userMessage,
            ];
        } catch (Throwable $e) {
            Log::error('Malan createLead unexpected failure', [
                'instance_id' => $instance->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'صار خلل مؤقت وما قدرت أسجّل الطلب. بحوّل لمندوب يتابع معك.',
            ];
        }

        $report = MalanSupportReport::query()->create([
            'chatbot_instance_id' => $instance->id,
            'conversation_id' => $conversation->id,
            'external_customer_id' => 'lead:'.$phone,
            'customer_name' => $fullName,
            'customer_phone_masked' => MalanSensitiveDataMasker::maskPhone($phone),
            'issue_type' => 'new_lead',
            'summary' => 'اشتراك جديد — '.$cityName,
            'status' => 'OPEN',
            'source_channel' => (string) ($input['channel'] ?? 'web'),
            'metadata' => array_merge([
                'full_name' => $fullName,
                'phone_masked' => MalanSensitiveDataMasker::maskPhone($phone),
                'city_name' => $cityName,
                'leads_sources_id' => $sourceId,
                'malan_lead_id' => $apiResult['lead_id'] ?? null,
                'malan_http_status' => $apiResult['http_status'] ?? null,
                'via' => 'create_malan_lead',
                'with_fiber' => $withFiber,
            ], is_array($input['metadata'] ?? null) ? $input['metadata'] : []),
        ]);

        $this->contextService->setPendingFlow($conversation, $instance, 'new_lead_open');
        $this->markCampaignLeadIfNeeded(
            $conversation,
            isset($apiResult['lead_id']) ? (int) $apiResult['lead_id'] : null,
        );
        $this->attachLeadNote(
            $conversation,
            $apiResult['lead_id'] ?? null,
            is_string($input['note'] ?? null) ? $input['note'] : null,
        );

        return [
            'success' => true,
            'duplicate' => false,
            'report_id' => $report->id,
            'lead_id' => $apiResult['lead_id'] ?? null,
            'leads_sources_id' => $sourceId,
            'message' => 'تم تسجيل الطلب. مندوب رح يتواصل معك قريب عشان يكمل التسجيل.',
        ];
    }

    private function markCampaignLeadIfNeeded(ChatbotConversation $conversation, ?int $leadId): void
    {
        if (! $conversation->isCampaignLeadBot()) {
            return;
        }

        try {
            app(\App\Services\Malan\Campaigns\MalanCampaignService::class)
                ->markLeadCreated($conversation, $leadId);
        } catch (Throwable) {
            // Analytics update must not fail the customer-facing lead create.
        }
    }

    /**
     * @throws MalanApiException
     */
    private function resolveLeadSourceId(ChatbotConversation $conversation): int
    {
        // Campaign leads must always map to the campaign CRM source (66 by default).
        if ($conversation->isCampaignLeadBot()) {
            $campaignSourceId = (int) config('malan.leads.campaign_source_id', 66);

            return $campaignSourceId > 0 ? $campaignSourceId : 66;
        }

        $configured = (int) config('malan.leads.default_source_id', 0);
        if ($configured > 0) {
            return $configured;
        }

        $cacheSeconds = max(60, (int) config('malan.leads.source_cache_seconds', 3600));
        $preferredTitle = mb_strtolower(trim((string) config('malan.leads.preferred_source_title', '')));

        /** @var list<array{id: int, title: string}> $sources */
        $sources = Cache::remember('malan.lead_sources.v1', $cacheSeconds, function (): array {
            $result = $this->apiClient->getLeadSources();

            return $result['sources'];
        });

        if ($sources === []) {
            return 0;
        }

        if ($preferredTitle !== '') {
            foreach ($sources as $source) {
                if (mb_strtolower($source['title']) === $preferredTitle) {
                    return $source['id'];
                }
            }
            foreach ($sources as $source) {
                if (str_contains(mb_strtolower($source['title']), $preferredTitle)) {
                    return $source['id'];
                }
            }
        }

        return $sources[0]['id'];
    }

    private function looksLikePhoneAsName(string $fullName): bool
    {
        if (($this->phoneNormalizer->normalize($fullName)['valid'] ?? false) === true) {
            return true;
        }

        return (bool) preg_match('/\d{7,}/', $fullName);
    }

    private function resolveWithFiber(ChatbotConversation $conversation, mixed $provided): int
    {
        $inferred = $this->memoryService->inferCampaignHasFiber($conversation);
        if ($inferred === true) {
            return 1;
        }
        if ($inferred === false) {
            return 0;
        }

        if (is_bool($provided)) {
            return $provided ? 1 : 0;
        }

        return ((int) $provided) === 1 ? 1 : 0;
    }

    private function attachLeadNote(ChatbotConversation $conversation, mixed $leadId, ?string $extraNote): void
    {
        if (! is_scalar($leadId) || (string) $leadId === '') {
            return;
        }

        $parts = [];
        $composed = $this->memoryService->composeLeadNote($conversation);
        if (is_string($composed) && trim($composed) !== '') {
            $parts[] = trim($composed);
        }
        if (is_string($extraNote) && trim($extraNote) !== '') {
            $parts[] = trim($extraNote);
        }

        if ($parts === []) {
            return;
        }

        try {
            $this->apiClient->createLeadNote($leadId, implode("\n", $parts));
        } catch (Throwable $e) {
            Log::warning('Malan createLeadNote failed after lead create', [
                'conversation_id' => $conversation->id,
                'lead_id' => $leadId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
