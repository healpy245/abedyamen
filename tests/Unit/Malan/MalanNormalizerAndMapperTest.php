<?php

declare(strict_types=1);

namespace Tests\Unit\Malan;

use App\Services\Malan\Exceptions\MalanApiException;
use App\Services\Malan\MalanCustomerResponseMapper;
use App\Services\Malan\MalanIdentityValidator;
use App\Services\Malan\MalanPhoneNormalizer;
use App\Services\Malan\MalanSensitiveDataMasker;
use PHPUnit\Framework\TestCase;

class MalanNormalizerAndMapperTest extends TestCase
{
    public function test_phone_normalizes_plus972_to_israeli_local(): void
    {
        $result = (new MalanPhoneNormalizer)->normalize('+972536079841');

        $this->assertTrue($result['valid']);
        $this->assertSame('0536079841', $result['normalized']);
    }

    public function test_phone_rejects_non_numeric_after_cleanup(): void
    {
        $result = (new MalanPhoneNormalizer)->normalize('05AB6079841');

        $this->assertFalse($result['valid']);
    }

    public function test_identity_checksum_accepts_valid_id(): void
    {
        // Known valid Israeli ID checksum pattern (padded): 123456782
        $result = (new MalanIdentityValidator)->normalizeAndValidate('123456782');

        $this->assertTrue($result['valid']);
        $this->assertSame('123456782', $result['normalized']);
    }

    public function test_identity_checksum_rejects_invalid_id(): void
    {
        $result = (new MalanIdentityValidator)->normalizeAndValidate('123456789');

        $this->assertFalse($result['valid']);
        $this->assertSame('checksum_failed', $result['error']);
    }

    public function test_masking_hides_phone_and_identity_middle(): void
    {
        $this->assertSame('053***9841', MalanSensitiveDataMasker::maskPhone('0536079841'));
        $this->assertSame('*****6782', MalanSensitiveDataMasker::maskIdentity('123456782'));
    }

    public function test_mapper_parses_array_envelope_active(): void
    {
        $mapper = new MalanCustomerResponseMapper;
        $result = $mapper->mapSuccessfulPayload([[
            'result' => true,
            'data' => [
                'client' => [
                    'id' => '3119',
                    'client_name' => 'Test User',
                    'client_phone' => '0536079841',
                    'client_identity' => '*****3153',
                    'status' => 'ACTIVE',
                    'city' => 'כפר קאסם',
                ],
                'financial_summary' => [
                    'balance' => 0,
                ],
                'internet_services' => [
                    ['package_name' => 'Summer Time'],
                ],
            ],
        ]]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->found);
        $this->assertSame('ACTIVE', $result->customer['status']);
        $this->assertNull($result->financial['debt_amount']);
        $this->assertSame('Summer Time', $result->service['package_name']);
        $this->assertSame('053***9841', $result->customer['phone_masked']);
    }

    public function test_mapper_parses_object_envelope_debt_disconnected(): void
    {
        $mapper = new MalanCustomerResponseMapper;
        $result = $mapper->mapSuccessfulPayload([
            'result' => true,
            'data' => [
                'client' => [
                    'id' => '3119',
                    'client_name' => 'מאמון טהה',
                    'client_phone' => '0536079841',
                    'client_identity' => '*****3153',
                    'status' => 'DEBT_DISCONNECTED',
                ],
                'financial_summary' => [
                    'balance' => -318,
                ],
            ],
        ]);

        $this->assertSame('DEBT_DISCONNECTED', $result->customer['status']);
        $this->assertSame(-318.0, $result->financial['balance_raw']);
        $this->assertSame(318.0, $result->financial['debt_amount']);
    }

    public function test_mapper_handles_positive_and_string_balance(): void
    {
        $mapper = new MalanCustomerResponseMapper;
        $result = $mapper->mapSuccessfulPayload([
            'result' => true,
            'data' => [
                'client' => [
                    'id' => '1',
                    'status' => 'DEBT_DISCONNECTED',
                ],
                'financial_summary' => [
                    'balance' => '250.5',
                ],
            ],
        ]);

        $this->assertSame(250.5, $result->financial['debt_amount']);
    }

    public function test_mapper_missing_balance_keeps_debt_null(): void
    {
        $mapper = new MalanCustomerResponseMapper;
        $result = $mapper->mapSuccessfulPayload([
            'result' => true,
            'data' => [
                'client' => [
                    'id' => '1',
                    'status' => 'DEBT_DISCONNECTED',
                ],
                'financial_summary' => [],
            ],
        ]);

        $this->assertNull($result->financial['debt_amount']);
    }

    public function test_mapper_unknown_status_is_mapped_to_unknown(): void
    {
        $mapper = new MalanCustomerResponseMapper;
        $result = $mapper->mapSuccessfulPayload([
            'result' => true,
            'data' => [
                'client' => [
                    'id' => '1',
                    'status' => 'SOMETHING_NEW',
                ],
                'financial_summary' => [],
            ],
        ]);

        $this->assertSame('UNKNOWN', $result->customer['status']);
    }

    public function test_mapper_rejects_invalid_envelope(): void
    {
        $this->expectException(MalanApiException::class);

        (new MalanCustomerResponseMapper)->mapSuccessfulPayload(['nope' => true]);
    }
}
