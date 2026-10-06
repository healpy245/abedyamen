<?php

declare(strict_types=1);

namespace Tests\Feature\KamanApp;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KamanAppApkDownloadTest extends TestCase
{
    public function test_guests_cannot_download_the_apk(): void
    {
        $this->getJson('/api/kaman-app/v1/apk')->assertUnauthorized();
    }

    public function test_any_signed_in_customer_can_download_the_apk(): void
    {
        $path = storage_path('framework/testing/kaman-app.apk');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'apk-bytes');
        config(['kaman-app.apk_path' => $path]);

        Http::fake([
            'https://kaman.rest/api/auth/customer-info*' => Http::response([
                'success' => true,
                'data' => ['customer' => ['email' => 'other@kaman.rest']],
            ]),
        ]);

        $this->withToken('other-customer-token')
            ->get('/api/kaman-app/v1/apk')
            ->assertOk()
            ->assertDownload('Kaman-3.0.16.apk');

        @unlink($path);
    }

    public function test_yamen_access_token_downloads_the_apk(): void
    {
        $path = storage_path('framework/testing/kaman-app.apk');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'apk-bytes');
        config(['kaman-app.apk_path' => $path]);

        Http::fake([
            'https://kaman.rest/api/auth/customer-info*' => Http::response([
                'success' => true,
                'data' => ['customer' => ['email' => 'yamen@kaman.rest']],
            ]),
        ]);

        $this->withToken('yamen-customer-token')
            ->get('/api/kaman-app/v1/apk')
            ->assertOk()
            ->assertDownload('Kaman-3.0.16.apk');

        @unlink($path);
    }
}
