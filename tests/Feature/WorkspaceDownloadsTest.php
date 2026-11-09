<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspaceDownloadsTest extends TestCase
{
    use RefreshDatabase;

    private string $apk;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake([
            'https://kaman.rest/api/*' => Http::response(['data' => ['companies' => [], 'areas' => []]]),
        ]);
        $this->apk = tempnam(sys_get_temp_dir(), 'apk');
        file_put_contents($this->apk, 'apk-bytes');
        config(['kaman-workspace.apk_path' => $this->apk]);
    }

    protected function tearDown(): void
    {
        @unlink($this->apk);

        parent::tearDown();
    }

    public function test_a_guest_cannot_download_the_workspace_app(): void
    {
        $this->get('/downloads/kaman-workspace')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_downloads_it_under_its_release_name(): void
    {
        $response = $this->actingAs(User::factory()->create(['projects' => ['app-development']]))
            ->get('/downloads/kaman-workspace')
            ->assertOk();

        $this->assertStringContainsString(config('kaman-workspace.download_name'), (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/vnd.android.package-archive', $response->headers->get('Content-Type'));
    }

    public function test_it_is_a_404_until_the_app_is_published(): void
    {
        @unlink($this->apk);

        $this->actingAs(User::factory()->create(['projects' => ['app-development']]))
            ->get('/downloads/kaman-workspace')
            ->assertNotFound();
    }

    public function test_the_sidebar_offers_all_three_downloads(): void
    {
        $page = $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/workspace/overview/dashboard')->assertOk();

        $page->assertSee(route('downloads.kaman-workspace'), false);
        $page->assertSee(asset('downloads/kaman-pos.apk'), false);
        $page->assertSee(asset('downloads/kaman-pos-setup.exe'), false);
        $page->assertSee(__('app.downloads'));
    }

    public function test_the_sidebar_leaves_the_app_out_until_it_is_published(): void
    {
        @unlink($this->apk);

        $page = $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/workspace/overview/dashboard')->assertOk();

        $page->assertDontSee(route('downloads.kaman-workspace'), false);
        $page->assertSee(asset('downloads/kaman-pos.apk'), false);
    }

    public function test_the_login_page_links_stay_on_the_shared_pos_version(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(asset('downloads/kaman-pos.apk').'?v='.config('kaman-workspace.pos_version').'.'.config('kaman-workspace.pos_build'), false)
            ->assertSee(asset('downloads/kaman-pos-setup.exe').'?v='.config('kaman-workspace.pos_windows_version').'.'.config('kaman-workspace.pos_windows_build'), false);
    }

    public function test_every_language_has_the_labels(): void
    {
        foreach (['en', 'ar', 'he'] as $locale) {
            foreach (['downloads', 'download_workspace_app', 'download_pos_android', 'download_pos_windows'] as $key) {
                $this->assertNotSame('app.'.$key, __('app.'.$key, [], $locale), $locale.' '.$key);
            }
        }
    }
}
