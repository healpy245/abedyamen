<?php

declare(strict_types=1);

namespace Tests\Feature\KamanApp;

use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KamanAppWebDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_download_the_client_apk(): void
    {
        $this->get(route('downloads.kaman-app'))
            ->assertRedirect(route('login'));
    }

    public function test_other_workspace_users_cannot_download_the_client_apk(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'ahmad@kaman.rest')->firstOrFail();

        $this->actingAs($user)
            ->get(route('downloads.kaman-app'))
            ->assertForbidden();
    }

    public function test_yamen_can_download_the_client_apk(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $path = storage_path('framework/testing/kaman-app-web.apk');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'apk-bytes');
        config(['kaman-app.apk_path' => $path]);

        $user = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($user)
            ->get(route('downloads.kaman-app'))
            ->assertOk()
            ->assertDownload('Kaman-3.0.16.apk');

        @unlink($path);
    }

    public function test_only_yamen_sees_the_client_download_button(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $ahmad = User::query()->where('email', 'ahmad@kaman.rest')->firstOrFail();

        $this->actingAs($yamen)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(route('downloads.kaman-app'), false);

        $this->actingAs($ahmad)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee(route('downloads.kaman-app'), false);
    }
}
