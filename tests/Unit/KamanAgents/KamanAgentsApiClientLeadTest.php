<?php

declare(strict_types=1);

namespace Tests\Unit\KamanAgents;

use App\Services\KamanAgents\KamanAgentsApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KamanAgentsApiClientLeadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.kaman_agents.base_url' => 'https://kamanagents-production.up.railway.app',
            'services.kaman_agents.username' => 'yamen',
            'services.kaman_agents.password' => 'yamen123',
        ]);
    }

    public function test_create_lead_logs_in_and_posts_payload(): void
    {
        Http::fake([
            'kamanagents-production.up.railway.app/api/auth/login' => Http::response([
                'accessToken' => 'lead-token',
            ], 200),
            'kamanagents-production.up.railway.app/api/leads*' => Http::response([
                'id' => 'abc',
            ], 201),
        ]);

        $lead = app(KamanAgentsApiClient::class)->createLead([
            'fullName' => 'Yamen Test',
            'restName' => 'unknown',
            'source' => 'whatsapp',
            'city' => 'unknown',
            'phoneNumber' => '0533046830',
            'notes' => 'الزبون مهتم بنظام كاشير',
        ]);

        $this->assertSame('abc', $lead['id'] ?? null);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://kamanagents-production.up.railway.app/api/auth/login'
                && $request['username'] === 'yamen'
                && $request['password'] === 'yamen123';
        });
        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/api/leads/')
                && $request->hasHeader('Authorization', 'Bearer lead-token')
                && $request['fullName'] === 'Yamen Test'
                && $request['phoneNumber'] === '0533046830';
        });
    }

    public function test_update_lead_patches_existing_record(): void
    {
        Http::fake([
            'kamanagents-production.up.railway.app/api/auth/login' => Http::response([
                'accessToken' => 'lead-token',
            ], 200),
            'kamanagents-production.up.railway.app/api/leads/*' => Http::response([
                'id' => 'lead-aseed',
            ], 200),
        ]);

        $lead = app(KamanAgentsApiClient::class)->updateLead('lead-aseed', [
            'fullName' => 'أسيد',
            'restName' => 'كانون',
            'source' => 'whatsapp',
            'city' => 'بيت حنينا',
            'phoneNumber' => '0587816661',
            'notes' => 'شغله قعدة جوا وتيك أواي وتوصيل.',
        ]);

        $this->assertSame('lead-aseed', $lead['id'] ?? null);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/api/leads/lead-aseed')
                && $request['restName'] === 'كانون'
                && $request['phoneNumber'] === '0587816661';
        });
    }
}
