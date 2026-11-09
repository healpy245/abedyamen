<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOpsAccess;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * /ops/* can migrate, seed, and clear caches on production. Nobody gets in without the key.
 */
class OpsRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-ops-key';

    private const SENDS_AND_WRITES = [
        '/ops/clear',
        '/ops/migrate',
        '/ops/seed',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['ops.key' => self::KEY]);
    }

    /** @return array<string, array{string}> */
    public static function writeRoutes(): array
    {
        return array_combine(self::SENDS_AND_WRITES, array_map(fn (string $u) => [$u], self::SENDS_AND_WRITES));
    }

    /** @return array<string, array{string}> */
    public static function oldUrls(): array
    {
        $urls = [
            '/ops/clear/483275634',
            '/ops/migrate/483275634',
            '/ops/seed/483275636',
        ];

        return array_combine($urls, array_map(fn (string $u) => [$u], $urls));
    }

    /** @dataProvider writeRoutes */
    public function test_a_guest_cannot_post_to_any_ops_route(string $url): void
    {
        $this->postJson($url)->assertUnauthorized();
    }

    /** @dataProvider oldUrls */
    public function test_the_old_numbered_urls_do_nothing(string $url): void
    {
        Artisan::spy();

        $this->getJson($url)->assertNotFound();

        Artisan::shouldNotHaveReceived('call');
    }

    /** @dataProvider writeRoutes */
    public function test_a_wrong_key_is_refused(string $url): void
    {
        $this->postJson($url, [], ['X-Ops-Key' => 'wrong'])->assertUnauthorized();
    }

    /** @dataProvider writeRoutes */
    public function test_the_key_in_the_address_is_not_accepted(string $url): void
    {
        $this->postJson($url.'?X-Ops-Key='.self::KEY.'&key='.self::KEY)->assertUnauthorized();
    }

    /** @dataProvider writeRoutes */
    public function test_with_no_key_on_the_server_even_an_empty_header_is_refused(string $url): void
    {
        config(['ops.key' => '']);

        $this->postJson($url, [], ['X-Ops-Key' => ''])->assertStatus(503);
    }

    /** @dataProvider writeRoutes */
    public function test_a_signed_in_admin_cannot_write_without_the_key(string $url): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson($url)
            ->assertUnauthorized();
    }

    public function test_a_refused_guest_does_not_run_the_command(): void
    {
        Artisan::spy();

        $this->postJson('/ops/migrate')->assertUnauthorized();
        $this->postJson('/ops/seed')->assertUnauthorized();
        $this->postJson('/ops/clear')->assertUnauthorized();

        Artisan::shouldNotHaveReceived('call');
    }

    public function test_the_key_runs_the_command(): void
    {
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        $this->postJson('/ops/migrate', [], ['X-Ops-Key' => self::KEY])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_the_key_gets_through_with_csrf_switched_on(): void
    {
        $this->app->instance('csrf.enforce', true);

        $status = $this->post('/ops/clear', [], ['X-Ops-Key' => self::KEY, 'Accept' => 'application/json'])->status();

        $this->assertNotSame(419, $status);
    }

    public function test_every_ops_route_is_guarded_and_none_answer_get(): void
    {
        $opsRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'ops/'));

        $this->assertCount(3, $opsRoutes);

        foreach ($opsRoutes as $route) {
            $middleware = Route::gatherRouteMiddleware($route);

            $this->assertContains(EnsureOpsAccess::class, $middleware, $route->uri());
            $this->assertNotContains(ValidateCsrfToken::class, $middleware, $route->uri());
            $this->assertNotContains(\App\Http\Middleware\ValidateCsrfToken::class, $middleware, $route->uri());
            $this->assertNotContains('GET', $route->methods());
        }
    }

    public function test_no_green_api_token_is_left_in_routes(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '#waInstance\d+/[A-Za-z]+/[0-9a-f]{20,}#',
            (string) file_get_contents(base_path('routes/web.php')),
        );
    }
}
