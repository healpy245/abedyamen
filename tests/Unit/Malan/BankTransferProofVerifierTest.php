<?php

declare(strict_types=1);

namespace Tests\Unit\Malan;

use App\Data\Malan\BankTransferProofVerificationResult;
use App\Services\Malan\Proof\OpenAiVisionBankTransferProofVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BankTransferProofVerifierTest extends TestCase
{
    public function test_missing_reference_and_wrong_date_become_needs_review_or_rejected(): void
    {
        config(['services.openai.api_key' => 'vision-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'looks_like_bank_transfer' => true,
                            'detected_amount' => 318,
                            'detected_date' => '2020-01-01',
                            'reference_number' => null,
                            'beneficiary_match' => true,
                            'possible_tampering' => false,
                            'confidence' => 0.7,
                            'suspicion_reasons' => [],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($tmp, 'fake-image-bytes');

        $result = app(OpenAiVisionBankTransferProofVerifier::class)->verify($tmp, [
            'expected_amount' => 318.0,
            'expected_date' => '2026-08-04',
            'mime_type' => 'image/jpeg',
            'bank_name' => 'בנק הפועלים',
        ]);

        @unlink($tmp);

        $this->assertContains($result->status, [
            BankTransferProofVerificationResult::STATUS_NEEDS_REVIEW,
            BankTransferProofVerificationResult::STATUS_REJECTED,
        ]);
        $this->assertFalse($result->referencePresent);
        $this->assertFalse($result->dateMatch);
        $this->assertContains('missing_reference_number', $result->suspicionReasons);
    }

    public function test_demo_watermark_is_hard_rejected_even_if_amount_and_date_match(): void
    {
        config(['services.openai.api_key' => 'vision-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'looks_like_bank_transfer' => true,
                            'detected_amount' => 318,
                            'detected_date' => '2026-08-12',
                            'reference_number' => '812345678901',
                            'beneficiary_name' => 'ישראל ישראלי',
                            'beneficiary_match' => false,
                            'is_demo_or_fake' => true,
                            'demo_indicators' => ['DEMO watermark', 'לצורכי בדיקה בלבד', 'ישראל ישראלי placeholder'],
                            'visible_watermark_text' => 'DEMO',
                            'possible_tampering' => true,
                            'confidence' => 0.95,
                            'suspicion_reasons' => ['demo_or_fake_document'],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($tmp, 'fake-image-bytes');

        $result = app(OpenAiVisionBankTransferProofVerifier::class)->verify($tmp, [
            'expected_amount' => 318.0,
            'expected_date' => '2026-08-12',
            'mime_type' => 'image/jpeg',
            'bank_name' => 'בנק לאומי',
        ]);

        @unlink($tmp);

        $this->assertSame(BankTransferProofVerificationResult::STATUS_REJECTED, $result->status);
        $this->assertContains('demo_or_fake_document', $result->suspicionReasons);
        $this->assertTrue((bool) ($result->details['is_demo_or_fake'] ?? false));
    }

    public function test_demo_indicators_force_reject_even_if_model_forgets_is_demo_flag(): void
    {
        config(['services.openai.api_key' => 'vision-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'looks_like_bank_transfer' => true,
                            'detected_amount' => 318,
                            'detected_date' => '2026-08-12',
                            'reference_number' => '812345678901',
                            'beneficiary_match' => true,
                            'is_demo_or_fake' => false,
                            'demo_indicators' => ['DEMO watermark'],
                            'visible_watermark_text' => 'DEMO — לצורכי בדיקה בלבד',
                            'possible_tampering' => false,
                            'confidence' => 0.99,
                            'suspicion_reasons' => [],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($tmp, 'fake-image-bytes');

        $result = app(OpenAiVisionBankTransferProofVerifier::class)->verify($tmp, [
            'expected_amount' => 318.0,
            'expected_date' => '2026-08-12',
            'mime_type' => 'image/jpeg',
        ]);

        @unlink($tmp);

        $this->assertSame(BankTransferProofVerificationResult::STATUS_REJECTED, $result->status);
        $this->assertContains('demo_or_fake_document', $result->suspicionReasons);
    }
}
