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

    public function test_create_task_posts_json_with_api_key_and_assignee(): void
    {
        Http::fake([
            'www.malan.app/apiClient/createTask' => Http::response([
                'result' => true,
                'data' => ['task_id' => 901],
            ], 200),
        ]);

        $result = app(MalanApiClient::class)->createTask([
            'title' => 'متابعة محاسبة',
            'subject' => 'زبون ניתוק חוב يطلب متابعة',
            'to_user_id' => 147,
            'status' => 'urgent',
            'client_id' => 630,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(901, $result['task_id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://www.malan.app/apiClient/createTask'
                && $request->hasHeader('X-API-Key', 'test-malan-key')
                && $request['to_user_id'] === 147
                && $request['client_id'] === 630
                && $request['status'] === 'urgent'
                && ! str_contains($request->url(), 'test-malan-key');
        });
    }

    public function test_create_lead_posts_json_and_maps_201(): void
    {
        Http::fake([
            'www.malan.app/apiClient/createLead' => Http::response([
                'result' => true,
                'message' => 'Lead created successfully.',
                'data' => [
                    'lead_id' => 1234,
                    'leads_sources_id' => 1,
                    'statuses_id' => 4,
                ],
            ], 201),
        ]);

        $result = app(MalanApiClient::class)->createLead([
            'full_name' => 'Test Lead',
            'phone' => '0500000000',
            'leads_sources_id' => 1,
            'city_name' => 'كفرقاسم',
            'with_fiber' => 0,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1234, $result['lead_id']);
        $this->assertSame(201, $result['http_status']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://www.malan.app/apiClient/createLead'
                && $request->hasHeader('X-API-Key', 'test-malan-key')
                && $request['full_name'] === 'Test Lead'
                && $request['phone'] === '0500000000'
                && $request['leads_sources_id'] === 1
                && $request['city_name'] === 'كفرقاسم'
                && $request['with_fiber'] === 0;
        });
    }

    public function test_create_lead_note_posts_json(): void
    {
        Http::fake([
            'www.malan.app/apiClient/createLeadNote' => Http::response([
                'result' => true,
                'message' => 'Note created successfully.',
            ], 201),
        ]);

        $result = app(MalanApiClient::class)->createLeadNote(4720, 'يفضل مكالمة الساعة 16:00');

        $this->assertTrue($result['success']);
        $this->assertSame(201, $result['http_status']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://www.malan.app/apiClient/createLeadNote'
                && $request->hasHeader('X-API-Key', 'test-malan-key')
                && $request['lead_id'] === 4720
                && $request['note'] === 'يفضل مكالمة الساعة 16:00';
        });
    }

    public function test_get_lead_sources_parses_list(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getLeadSources' => Http::response([
                'result' => true,
                'data' => [
                    'sources' => [
                        ['id' => 3, 'title' => 'WhatsApp'],
                        ['id' => 1, 'title' => 'Website'],
                    ],
                    'count' => 2,
                ],
            ], 200),
        ]);

        $result = app(MalanApiClient::class)->getLeadSources();

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(3, $result['sources'][0]['id']);
    }
}
