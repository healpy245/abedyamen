<?php

declare(strict_types=1);

namespace Tests\Unit\Malan;

use App\Services\Malan\Exceptions\MalanApiException;
use App\Services\Malan\MalanApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MalanApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'malan.api.base_url' => 'https://www.malan.app',
            'malan.api.key' => 'test-malan-key',
            'malan.api.timeout' => 5,
            'malan.api.retries' => 0,
        ]);
    }

    public function test_lookup_by_phone_sends_correct_query_and_header(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([[
                'result' => true,
                'data' => [
                    'client' => [
                        'id' => '3119',
                        'client_name' => 'Test',
                        'client_phone' => '0536079841',
                        'status' => 'ACTIVE',
                    ],
                    'financial_summary' => ['balance' => 0],
                ],
            ]], 200),
        ]);

        $result = app(MalanApiClient::class)->getClient('phone', '0536079841');

        $this->assertTrue($result->success);
        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-Key', 'test-malan-key')
                && $request['phone'] === '0536079841'
                && ($request['identity'] === '' || $request['identity'] === null)
                && ! str_contains($request->url(), 'test-malan-key');
        });
    }

    public function test_lookup_by_identity_does_not_send_phone_value(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([[
                'result' => true,
                'data' => [
                    'client' => [
                        'id' => '3119',
                        'status' => 'ACTIVE',
                        'client_identity' => '*****3153',
                    ],
                    'financial_summary' => [],
                ],
            ]], 200),
        ]);

        app(MalanApiClient::class)->getClient('identity', '123456782');

        Http::assertSent(function ($request) {
            return $request['identity'] === '123456782'
                && ($request['phone'] === '' || $request['phone'] === null);
        });
    }

    public function test_api_key_not_written_to_logs(): void
    {
        Log::spy();

        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([[
                'result' => true,
                'data' => [
                    'client' => ['id' => '1', 'status' => 'ACTIVE', 'client_phone' => '0536079841'],
                    'financial_summary' => [],
                ],
            ]], 200),
        ]);

        app(MalanApiClient::class)->getClient('phone', '0536079841');

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            $encoded = json_encode([$message, $context]);

            return is_string($encoded) && ! str_contains($encoded, 'test-malan-key');
        });
    }

    #[DataProvider('errorStatusProvider')]
    public function test_http_errors_map_to_exceptions(int $status, string $errorCode): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response(['error' => 'x'], $status),
        ]);

        try {
            app(MalanApiClient::class)->getClient('phone', '0536079841');
            $this->fail('Expected MalanApiException');
        } catch (MalanApiException $e) {
            $this->assertSame($errorCode, $e->errorCode);
            $this->assertStringNotContainsString((string) $status, $e->userMessage);
        }
    }

    public function test_http_404_maps_to_not_found(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([
                'result' => false,
                'error' => 'Client not found.',
            ], 404),
        ]);

        try {
            app(MalanApiClient::class)->getClient('phone', '053046830');
            $this->fail('Expected MalanApiException');
        } catch (MalanApiException $e) {
            $this->assertSame('not_found', $e->errorCode);
            $this->assertStringContainsString('مش مسجّل', $e->userMessage);
        }
    }

    public function test_result_false_not_found_body_maps_to_not_found_even_on_200(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([
                'result' => false,
                'error' => 'Client not found.',
            ], 200),
        ]);

        try {
            app(MalanApiClient::class)->getClient('phone', '053046830');
            $this->fail('Expected MalanApiException');
        } catch (MalanApiException $e) {
            $this->assertSame('not_found', $e->errorCode);
        }
    }

    public function test_409_multi_match_stays_conflict_not_not_found(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([
                'result' => false,
                'error' => 'More than one client matched the provided data.',
            ], 409),
        ]);

        try {
            app(MalanApiClient::class)->getClient('phone', '0533046830');
            $this->fail('Expected MalanApiException');
        } catch (MalanApiException $e) {
            $this->assertSame('conflict', $e->errorCode);
            $this->assertStringContainsString('أكثر من حساب', $e->userMessage);
        }
    }

    /**
     * @return array<string, array{0:int,1:string}>
     */
    public static function errorStatusProvider(): array
    {
        return [
            '400' => [400, 'invalid_input'],
            '401' => [401, 'unauthorized'],
            '404' => [404, 'not_found'],
            '409' => [409, 'conflict'],
            '405' => [405, 'method_not_allowed'],
            '429' => [429, 'rate_limited'],
            '500' => [500, 'server_error'],
        ];
    }

    public function test_timeout_maps_to_timeout_exception(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $this->expectException(MalanApiException::class);
        try {
            app(MalanApiClient::class)->getClient('phone', '0536079841');
        } catch (MalanApiException $e) {
            $this->assertSame('timeout', $e->errorCode);
            throw $e;
        }
    }
}
