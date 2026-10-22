<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KamanSupportDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_download_the_support_apk(): void
    {
        $this->get(route('downloads.kaman-support'))
            ->assertRedirect(route('login'));
    }

    public function test_signed_in_users_can_download_the_support_apk(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $path = storage_path('framework/testing/kaman-support.apk');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'apk-bytes');
        config(['kaman-support.apk_path' => $path]);

        $user = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($user)
            ->get(route('downloads.kaman-support'))
            ->assertOk()
            ->assertDownload('KamanSupport-1.0.11.apk');

        @unlink($path);
    }
}
