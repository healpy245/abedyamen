<?php

declare(strict_types=1);

namespace App\Services\Malan\Proof;

use App\Data\Malan\BankTransferProofVerificationResult;
use App\Services\Malan\Contracts\BankTransferProofVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Heuristic + optional OpenAI Vision verifier.
 * Hard-rejects clear demo/sample watermarks; never claims forensic certainty about sophisticated forgery.
 */
class OpenAiVisionBankTransferProofVerifier implements BankTransferProofVerifier
{
    /** @var list<string> */
    private const DEMO_REASON_MARKERS = [
        'demo',
        'sample',
        'watermark',
        'test_only',
        'testing_only',
        'for_testing',
        'placeholder',
        'fake',
        'mock',
        'לצורכי בדיקה',
        'בדיקה בלבד',
        'ישראל ישראלי',
    ];

    public function verify(string $absoluteFilePath, array $expectations): BankTransferProofVerificationResult
    {
        $expectedAmount = (float) ($expectations['expected_amount'] ?? 0);
        $expectedDate = (string) ($expectations['expected_date'] ?? '');
        $mime = (string) ($expectations['mime_type'] ?? 'image/jpeg');

        if (! is_file($absoluteFilePath)) {
            return new BankTransferProofVerificationResult(
                status: BankTransferProofVerificationResult::STATUS_REJECTED,
                suspicionReasons: ['file_missing'],
                confidence: 0.0,
            );
        }

        $vision = $this->analyzeWithVision($absoluteFilePath, $mime, $expectations);

        $detectedAmount = isset($vision['detected_amount']) && is_numeric($vision['detected_amount'])
            ? (float) $vision['detected_amount']
            : null;
        $detectedDate = isset($vision['detected_date']) && is_string($vision['detected_date'])
            ? $vision['detected_date']
            : null;
        $reference = isset($vision['reference_number']) && is_string($vision['reference_number'])
            ? trim($vision['reference_number'])
            : null;

        $looksLikeTransfer = (bool) ($vision['looks_like_bank_transfer'] ?? false);
        $beneficiaryMatch = (bool) ($vision['beneficiary_match'] ?? false);
        $isDemoOrFake = (bool) ($vision['is_demo_or_fake'] ?? false);
        $referencePresent = is_string($reference) && $reference !== '';
        $tolerance = (float) config('malan.proof.amount_tolerance', 0.01);
        $amountMatch = $detectedAmount !== null && abs($detectedAmount - $expectedAmount) <= $tolerance;
        $dateMatch = is_string($detectedDate) && $detectedDate === $expectedDate;
        $confidence = isset($vision['confidence']) && is_numeric($vision['confidence'])
            ? (float) $vision['confidence']
            : null;

        $reasons = [];
        if ($isDemoOrFake) {
            $reasons[] = 'demo_or_fake_document';
        }
        if (! $looksLikeTransfer) {
            $reasons[] = 'not_a_bank_transfer_document';
        }
        if (! $amountMatch) {
            $reasons[] = 'amount_mismatch_or_missing';
        }
        if (! $dateMatch) {
            $reasons[] = 'date_mismatch_or_missing';
        }
        if (! $referencePresent) {
            $reasons[] = 'missing_reference_number';
        }
        if (! empty($vision['possible_tampering'])) {
            $reasons[] = 'possible_tampering_signals';
        }
        if (! empty($vision['suspicion_reasons']) && is_array($vision['suspicion_reasons'])) {
            foreach ($vision['suspicion_reasons'] as $reason) {
                if (is_string($reason) && $reason !== '') {
                    $reasons[] = $reason;
                }
            }
        }

        $reasons = array_values(array_unique($reasons));
        if ($this->reasonsIndicateDemoOrFake($reasons) || $this->visionTextIndicatesDemo($vision)) {
            $isDemoOrFake = true;
            if (! in_array('demo_or_fake_document', $reasons, true)) {
                $reasons[] = 'demo_or_fake_document';
            }
        }

        $minConfidence = (float) config('malan.proof.min_confidence_verified', 0.85);

        if ($isDemoOrFake) {
            $status = BankTransferProofVerificationResult::STATUS_REJECTED;
        } elseif (
            $looksLikeTransfer
            && $amountMatch
            && $dateMatch
            && $referencePresent
            && $reasons === []
            && $confidence !== null
            && $confidence >= $minConfidence
        ) {
            $status = BankTransferProofVerificationResult::STATUS_VERIFIED;
        } elseif (! $looksLikeTransfer || ($detectedAmount !== null && ! $amountMatch && abs(($detectedAmount ?? 0) - $expectedAmount) > 1)) {
            $status = BankTransferProofVerificationResult::STATUS_REJECTED;
            if ($reasons === []) {
                $reasons[] = 'failed_basic_checks';
            }
        } else {
            $status = BankTransferProofVerificationResult::STATUS_NEEDS_REVIEW;
            if ($reasons === []) {
                $reasons[] = 'insufficient_confidence';
            }
        }

        return new BankTransferProofVerificationResult(
            status: $status,
            detectedAmount: $detectedAmount,
            detectedDate: $detectedDate,
            referenceNumber: $reference,
            beneficiaryMatch: $beneficiaryMatch,
            amountMatch: $amountMatch,
            dateMatch: $dateMatch,
            referencePresent: $referencePresent,
            suspicionReasons: array_values(array_unique($reasons)),
            confidence: $confidence,
            details: [
                'looks_like_bank_transfer' => $looksLikeTransfer,
                'is_demo_or_fake' => $isDemoOrFake,
                'vision_available' => (bool) ($vision['vision_available'] ?? false),
                'demo_indicators' => $vision['demo_indicators'] ?? [],
                'note' => 'AI verification is probabilistic and does not prove authenticity.',
            ],
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    private function reasonsIndicateDemoOrFake(array $reasons): bool
    {
        foreach ($reasons as $reason) {
            $normalized = mb_strtolower($reason);
            foreach (self::DEMO_REASON_MARKERS as $marker) {
                if (str_contains($normalized, mb_strtolower($marker))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $vision
     */
    private function visionTextIndicatesDemo(array $vision): bool
    {
        $chunks = [];
        if (! empty($vision['demo_indicators']) && is_array($vision['demo_indicators'])) {
            foreach ($vision['demo_indicators'] as $item) {
                if (is_string($item)) {
                    $chunks[] = $item;
                }
            }
        }
        if (! empty($vision['visible_watermark_text']) && is_string($vision['visible_watermark_text'])) {
            $chunks[] = $vision['visible_watermark_text'];
        }
        if (! empty($vision['beneficiary_name']) && is_string($vision['beneficiary_name'])) {
            $chunks[] = $vision['beneficiary_name'];
        }

        $haystack = mb_strtolower(implode(' | ', $chunks));
        if ($haystack === '') {
            return false;
        }

        foreach (['demo', 'לצורכי בדיקה', 'בדיקה בלבד', 'ישראל ישראלי', 'sample only', 'for testing'] as $marker) {
            if (str_contains($haystack, mb_strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $expectations
     * @return array<string, mixed>
     */
    private function analyzeWithVision(string $absoluteFilePath, string $mime, array $expectations): array
    {
        $apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        if (! $apiKey) {
            return [
                'vision_available' => false,
                'looks_like_bank_transfer' => false,
                'is_demo_or_fake' => false,
                'confidence' => 0.0,
                'suspicion_reasons' => ['vision_unavailable'],
            ];
        }

        $bytes = @file_get_contents($absoluteFilePath);
        if ($bytes === false) {
            return [
                'vision_available' => false,
                'looks_like_bank_transfer' => false,
                'is_demo_or_fake' => false,
                'confidence' => 0.0,
                'suspicion_reasons' => ['unreadable_file'],
            ];
        }

        $dataUrl = 'data:'.$mime.';base64,'.base64_encode($bytes);
        $prompt = <<<'PROMPT'
Analyze this image as a possible Israeli bank-transfer proof (העברה בנקאית / מספר אסמכתה).

Return ONLY compact JSON with keys:
- looks_like_bank_transfer (bool)
- detected_amount (number|null)
- detected_date (YYYY-MM-DD|null, Asia/Jerusalem if ambiguous)
- reference_number (string|null for מספר אסמכתה)
- beneficiary_name (string|null)
- beneficiary_match (bool)
- is_demo_or_fake (bool) — true if this is clearly a demo/sample/test/fake document
- demo_indicators (string[]) — concrete signals found (e.g. "DEMO watermark", "לצורכי בדיקה בלבד", "ישראל ישראלי placeholder")
- visible_watermark_text (string|null)
- possible_tampering (bool)
- confidence (0-1)
- suspicion_reasons (string[])

Hard rules for is_demo_or_fake=true (set true if ANY apply):
1) Visible "DEMO" / "SAMPLE" / "TEST" watermark or overlay
2) Hebrew text like "לצורכי בדיקה בלבד" / "בדיקה בלבד" / "לצורכי הדגמה"
3) Obvious placeholder names such as "ישראל ישראלי" / "Israel Israeli" / "John Doe"
4) UI chrome clearly from a mock/demo banking simulator
5) Explicit "for testing purposes only" wording

If is_demo_or_fake=true, also include "demo_or_fake_document" inside suspicion_reasons.
Do not mark a real-looking customer receipt as fake just because quality is imperfect.
PROMPT;

        $prompt .= "\nExpected amount: ".$expectations['expected_amount']
            .'. Expected date: '.$expectations['expected_date'].'. '
            .'Bank name hint: '.($expectations['bank_name'] ?? '').'.';

        try {
            $http = Http::timeout(45)->withToken((string) $apiKey)->acceptJson();
            if (! config('services.openai.verify_ssl', true)) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post('https://api.openai.com/v1/chat/completions', [
                'model' => (string) config('malan.proof.vision_model', 'gpt-4o-mini'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ]],
            ]);

            if (! $response->successful()) {
                Log::warning('Bank proof vision call failed', ['status' => $response->status()]);

                return [
                    'vision_available' => false,
                    'looks_like_bank_transfer' => false,
                    'is_demo_or_fake' => false,
                    'confidence' => 0.0,
                    'suspicion_reasons' => ['vision_http_error'],
                ];
            }

            $content = $response->json('choices.0.message.content');
            $decoded = is_string($content) ? json_decode($content, true) : null;
            if (! is_array($decoded)) {
                return [
                    'vision_available' => true,
                    'looks_like_bank_transfer' => false,
                    'is_demo_or_fake' => false,
                    'confidence' => 0.0,
                    'suspicion_reasons' => ['vision_parse_error'],
                ];
            }

            $decoded['vision_available'] = true;

            return $decoded;
        } catch (Throwable $e) {
            Log::warning('Bank proof vision exception', ['error' => $e->getMessage()]);

            return [
                'vision_available' => false,
                'looks_like_bank_transfer' => false,
                'is_demo_or_fake' => false,
                'confidence' => 0.0,
                'suspicion_reasons' => ['vision_exception'],
            ];
        }
    }
}
