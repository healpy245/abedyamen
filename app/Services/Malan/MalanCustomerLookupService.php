<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Data\Malan\MalanCustomerLookupResult;
use App\Models\AiChatbot\ChatbotInstance;
use App\Services\Malan\Exceptions\MalanApiException;
use Illuminate\Support\Facades\Log;

class MalanCustomerLookupService
{
    public function __construct(
        protected MalanApiClient $apiClient,
        protected MalanPhoneNormalizer $phoneNormalizer,
        protected MalanIdentityValidator $identityValidator,
    ) {}

    /**
     * @param  'phone'|'identity'  $lookupType
     */
    public function lookup(ChatbotInstance $instance, string $lookupType, string $value): MalanCustomerLookupResult
    {
        if (! $instance->hasMalanIntegration()) {
            return new MalanCustomerLookupResult(
                success: false,
                found: false,
                error_code: 'integration_disabled',
                user_message: 'هالأداة مش متاحة لهالبوت.',
            );
        }

        try {
            $normalized = $this->normalize($lookupType, $value);

            return $this->apiClient->getClient($lookupType, $normalized);
        } catch (MalanApiException $e) {
            Log::warning('Malan lookup failed', [
                'instance_id' => $instance->id,
                'lookup_type' => $lookupType,
                'error_code' => $e->errorCode,
                'http_status' => $e->httpStatus,
                'value_masked' => $lookupType === 'identity'
                    ? MalanSensitiveDataMasker::maskIdentity($value)
                    : MalanSensitiveDataMasker::maskPhone($value),
            ]);

            $meta = [];
            if ($e->errorCode === 'conflict' && $lookupType === 'phone') {
                $meta['needs_second_identifier'] = 'identity';
                $meta['instruction'] = 'Ask once for the line-owner identity number. Do not ask for phone again. Do not claim the number is unregistered.';
            }
            if ($e->errorCode === 'conflict' && $lookupType === 'identity') {
                $meta['needs_human_escalation'] = true;
                $meta['instruction'] = 'Do NOT ask for identity again. Escalate to a human agent.';
            }
            if ($e->errorCode === 'not_found') {
                $meta['instruction'] = 'Tell the customer this number/ID does not appear registered. Do NOT invent multiple accounts. Offer to retry with another registered phone or identity.';
            }

            return new MalanCustomerLookupResult(
                success: false,
                found: false,
                error_code: $e->errorCode,
                user_message: $e->userMessage,
                meta: $meta,
            );
        }
    }

    /**
     * @param  'phone'|'identity'  $lookupType
     *
     * @throws MalanApiException
     */
    public function normalize(string $lookupType, string $value): string
    {
        if ($lookupType === 'phone') {
            $result = $this->phoneNormalizer->normalize($value);
            if (! $result['valid'] || $result['normalized'] === null) {
                throw MalanApiException::invalidInput(
                    'Invalid phone: '.($result['error'] ?? 'unknown'),
                    'هالرقم مش مكتمل أو بصيغة غلط. ابعت رقم تلفون مسجّل من 10 خانات مثل 05XXXXXXXX، أو رقم الهوية.',
                );
            }

            return $result['normalized'];
        }

        if ($lookupType === 'identity') {
            $result = $this->identityValidator->normalizeAndValidate($value);
            if (! $result['valid'] || $result['normalized'] === null) {
                throw MalanApiException::invalidInput(
                    'Invalid identity: '.($result['error'] ?? 'unknown'),
                    'تأكدلي من رقم الهوية وابعته مرة ثانية.',
                );
            }

            return $result['normalized'];
        }

        throw MalanApiException::invalidInput(
            'Unsupported lookup type.',
            'تأكدلي من الرقم وابعته مرة ثانية.',
        );
    }
}
