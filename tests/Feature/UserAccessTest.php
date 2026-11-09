<?php

namespace Tests\Feature;

use App\Enums\Door;
use App\Models\Finance\FinanceClient;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Users management: yamen turns doors and sub doors on or off, and a locked one refuses its page and its data.
 */
class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What every seeded account could open before users management existed.
     *
     * @var array<string, array{doors: list<string>, closed?: list<string>}>
     */
    private const STANDARD_ACCESS = [
        'yamen@kaman.rest' => ['doors' => ['overview', 'support', 'development', 'agents', 'finance', 'form']],
        'burhan@kaman.rest' => ['doors' => ['overview', 'development', 'agents', 'finance', 'form']],
        'ahmad@kaman.rest' => ['doors' => ['overview', 'agents', 'form']],
        'mohamed@kaman.rest' => ['doors' => ['overview', 'agents', 'form']],
        'abedjaber@kaman.rest' => ['doors' => ['overview', 'development', 'agents'], 'closed' => ['development.reports']],
        'ahmadessa@kaman.rest' => ['doors' => ['overview', 'development', 'agents', 'form'], 'closed' => ['development.reports']],
        'moaz@kaman.rest' => ['doors' => ['overview', 'support', 'agents']],
        'connections@kaman.rest' => ['doors' => ['overview', 'support', 'agents'], 'closed' => ['support.logs', 'support.menu', 'support.tickets']],
    ];

    private User $yamen;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake();
        Cache::put('kaman.rest.companies.v2', [[
            'id' => 'company-1', 'name' => 'Hidden Grill House', 'subdomain' => 'hidden-grill', 'url' => 'https://hidden-grill.kaman.rest',
            'color' => '#f58220', 'lang' => 'he', 'address' => '', 'logo' => '', 'hours' => '', 'cityId' => '',
        ]], 600);

        $this->seed(WorkspaceUserSeeder::class);
        $this->yamen = $this->user('yamen@kaman.rest');
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function set(User $user, string $door, ?string $subDoor, bool $on): void
    {
        $this->actingAs($this->yamen)
            ->putJson(route('workspace.users.access', $user), ['door' => $door, 'sub_door' => $subDoor, 'on' => $on])
            ->assertOk();
        $user->refresh();
        // RestaurantSupport keeps its result for one request; these tests send many in one app.
        $this->app->forgetScopedInstances();
    }

    private function ticket(string $summary): SupportTicket
    {
        return SupportTicket::query()->create([
            'public_id' => 'KAM-'.strtoupper(fake()->bothify('??####')),
            'restaurant' => 'hidden-grill',
            'restaurant_name' => 'Hidden Grill House',
            'summary' => $summary,
            'status' => 'open',
        ]);
    }

    public function test_only_yamen_opens_the_management_page(): void
    {
        $this->actingAs($this->yamen)->get(route('workspace.users'))->assertOk();

        foreach (['burhan@kaman.rest', 'ahmad@kaman.rest', 'moaz@kaman.rest'] as $email) {
            $user = $this->user($email);
            $this->actingAs($user)->get(route('workspace.users'))->assertForbidden();
            $this->actingAs($user)
                ->putJson(route('workspace.users.access', $this->user('ahmad@kaman.rest')), ['door' => 'finance', 'on' => true])
                ->assertForbidden();
        }

        $this->assertNull($this->user('ahmad@kaman.rest')->door_access);

        auth()->logout();
        $this->get(route('workspace.users'))->assertRedirect(route('login'));
    }

    public function test_the_sidebar_link_to_it_shows_for_yamen_alone(): void
    {
        $this->actingAs($this->yamen)->get(route('workspace.overview'))->assertSee(route('workspace.users'), false);
        $this->actingAs($this->user('burhan@kaman.rest'))->get(route('workspace.overview'))->assertDontSee(route('workspace.users'), false);
    }

    public function test_the_page_lists_every_user_with_their_access(): void
    {
        $response = $this->actingAs($this->yamen)->get(route('workspace.users'))->assertOk();

        foreach (User::query()->get() as $user) {
            $response->assertSee($user->email, false);
        }
        foreach (Door::cases() as $door) {
            $response->assertSee('data-ua-chip="'.$door->value.'"', false);
            foreach ($door->subDoors() as $sub) {
                $response->assertSee($sub['label'], false);
            }
        }
    }

    public function test_saving_a_door_takes_effect_for_that_user(): void
    {
        $ahmad = $this->user('ahmad@kaman.rest');
        $this->actingAs($ahmad)->get(route('workspace.finance.dashboard'))->assertForbidden();

        $this->set($ahmad, 'finance', null, true);
        $this->assertSame(['finance' => true], $ahmad->door_access);
        $this->actingAs($ahmad)->get(route('workspace.finance.dashboard'))->assertOk();

        $this->set($ahmad, 'finance', null, false);
        // Back to the standard access: nothing is left stored for this user.
        $this->assertNull($ahmad->door_access);
        $this->actingAs($ahmad)->get(route('workspace.finance.dashboard'))->assertForbidden();
    }

    public function test_saving_a_sub_door_takes_effect_only_for_that_sub_door(): void
    {
        $ahmad = $this->user('ahmad@kaman.rest');
        $this->set($ahmad, 'finance', null, true);
        $this->set($ahmad, 'finance', 'clients', false);

        $this->actingAs($ahmad)->get(route('workspace.finance.dashboard'))->assertOk();
        $this->actingAs($ahmad)->get(route('workspace.finance.storage'))->assertOk();
        $this->actingAs($ahmad)->get(route('workspace.finance.clients'))->assertForbidden();

        // The sub door keeps its position while the door is closed and comes back with it.
        $this->set($ahmad, 'finance', null, false);
        $this->assertFalse($ahmad->canOpenSubDoor(Door::Finance, 'storage'));
        $this->set($ahmad, 'finance', null, true);
        $this->assertTrue($ahmad->canOpenSubDoor(Door::Finance, 'storage'));
        $this->assertFalse($ahmad->canOpenSubDoor(Door::Finance, 'clients'));
    }

    public function test_the_save_response_reports_what_was_stored(): void
    {
        $ahmad = $this->user('ahmad@kaman.rest');

        $this->actingAs($this->yamen)
            ->putJson(route('workspace.users.access', $ahmad), ['door' => 'finance', 'on' => true])
            ->assertOk()
            ->assertJsonPath('row.doors.finance.on', true)
            ->assertJsonPath('row.doors.finance.openCount', 4);

        $this->actingAs($this->yamen)
            ->putJson(route('workspace.users.access', $ahmad), ['door' => 'finance', 'sub_door' => 'nope', 'on' => true])
            ->assertStatus(422);
        $this->actingAs($this->yamen)
            ->putJson(route('workspace.users.access', $ahmad), ['door' => 'nope', 'on' => true])
            ->assertStatus(422);
    }

    public function test_a_locked_door_refuses_its_page_and_its_data_endpoints(): void
    {
        $burhan = $this->user('burhan@kaman.rest');
        $client = FinanceClient::query()->create(['company_id' => 'company-1', 'name' => 'Very Secret Client', 'lang' => 'he']);
        $this->actingAs($burhan)->get(route('workspace.finance.clients.show', ['client' => $client, 'embed' => 1]))->assertOk()->assertSee('Very Secret Client', false);

        $this->set($burhan, 'finance', null, false);

        $this->actingAs($burhan)->get(route('workspace.finance.clients'))
            ->assertForbidden()
            ->assertSee('door-lock__card', false)
            ->assertDontSee('Very Secret Client', false);
        $this->actingAs($burhan)->get(route('workspace.finance.clients.show', $client))
            ->assertForbidden()
            ->assertDontSee('Very Secret Client', false);
        $this->actingAs($burhan)->getJson(route('workspace.finance.clients.show', $client))
            ->assertForbidden()
            ->assertJsonMissing(['name' => 'Very Secret Client']);
        $this->actingAs($burhan)->patch(route('workspace.finance.clients.update', $client), ['name' => 'Changed'])->assertForbidden();
        $this->assertSame('Very Secret Client', $client->refresh()->name);
    }

    public function test_a_locked_sub_door_refuses_its_page_and_its_data_endpoints(): void
    {
        $ticket = $this->ticket('Printer on fire');
        $mohamed = $this->user('mohamed@kaman.rest');
        $this->set($mohamed, 'support', null, true);
        $this->set($mohamed, 'support', 'tickets', false);

        $this->actingAs($mohamed)->get(route('kaman-support.live'))->assertOk()->assertDontSee('Printer on fire', false);

        $this->actingAs($mohamed)->get(route('kaman-support.inbox'))
            ->assertForbidden()
            ->assertSee('door-lock__card', false)
            ->assertDontSee('Printer on fire', false);
        $this->actingAs($mohamed)->getJson(route('kaman-support.api.tickets.index'))->assertForbidden();
        $this->actingAs($mohamed)->getJson(route('kaman-support.api.tickets.show', $ticket))->assertForbidden();
        $this->actingAs($mohamed)->post(route('kaman-support.close', $ticket))->assertForbidden();
        $this->assertSame('open', $ticket->refresh()->status->value);

        $this->actingAs($mohamed)->get(route('kaman-support.restaurant.card', 'hidden-grill'))
            ->assertOk()
            ->assertDontSee('Printer on fire', false);

        $this->set($mohamed, 'support', 'live', false);
        $this->actingAs($mohamed)->getJson(route('kaman-support.live.feed'))->assertForbidden();
        $this->actingAs($mohamed)->getJson(route('kaman-support.restaurant.card', 'hidden-grill'))->assertForbidden();
    }

    public function test_pages_sharing_a_route_lock_per_sub_door(): void
    {
        $ahmad = $this->user('ahmad@kaman.rest');
        $this->set($ahmad, 'overview', 'restaurants', false);
        $this->set($ahmad, 'agents', 'calendar', false);

        $this->actingAs($ahmad)->get(route('workspace.overview', ['page' => 'restaurants']))
            ->assertForbidden()
            ->assertDontSee('Hidden Grill House', false);
        $this->actingAs($ahmad)->get(route('workspace.overview', ['page' => 'dashboard']))
            ->assertOk()
            ->assertDontSee('Hidden Grill House', false)
            ->assertDontSee('data-ag-nav="restaurants"', false);

        $this->actingAs($ahmad)->get(route('workspace.agents', ['page' => 'calendar']))->assertForbidden();
        $this->actingAs($ahmad)->get(route('workspace.agents', ['page' => 'leads']))
            ->assertOk()
            ->assertDontSee('data-ag-nav="calendar"', false);
    }

    public function test_locked_tabs_and_doors_show_the_lock_and_keep_their_link(): void
    {
        $ahmad = $this->user('ahmad@kaman.rest');
        $this->set($ahmad, 'agents', 'sales', false);

        $this->actingAs($ahmad)->get(route('workspace.agents', ['page' => 'leads']))
            ->assertSee('ws-lockmark', false)
            ->assertSee(route('workspace.agents.sales'), false)
            ->assertSee('is-locked', false);
    }

    public function test_the_restaurant_card_follows_its_switches_and_the_doors_behind_it(): void
    {
        $this->ticket('Oven alarm');
        FinanceClient::query()->create(['company_id' => 'company-1', 'name' => 'Card Client', 'lang' => 'he']);
        $ahmad = $this->user('ahmad@kaman.rest');
        $client = FinanceClient::query()->where('company_id', 'company-1')->firstOrFail();

        // Everyone in the workspace keeps today's read-only sections until yamen says otherwise.
        $this->actingAs($ahmad)->get(route('workspace.overview', ['page' => 'restaurants']))->assertOk()->assertSee('Oven alarm', false);
        $this->actingAs($ahmad)->get(route('workspace.restaurant.finance', $client))->assertOk();

        $this->set($ahmad, 'overview', 'card_support', false);
        $this->set($ahmad, 'overview', 'card_finance', false);

        $this->actingAs($ahmad)->get(route('workspace.overview', ['page' => 'restaurants']))
            ->assertOk()
            ->assertDontSee('Oven alarm', false)
            ->assertDontSee('"clientId"', false);
        $this->actingAs($ahmad)->get(route('workspace.restaurant.finance', $client))->assertForbidden();

        // Taking the Finance door from someone who had it takes the card's Finance section with it.
        $burhan = $this->user('burhan@kaman.rest');
        $this->assertTrue($burhan->canSeeRestaurantSection(Door::Finance));
        $this->set($burhan, 'finance', null, false);
        $this->assertFalse($burhan->canSeeRestaurantSection(Door::Finance));
        $this->actingAs($burhan)->get(route('workspace.restaurant.finance', $client))->assertForbidden();
    }

    public function test_every_door_and_sub_door_of_every_user_stays_as_it_was(): void
    {
        foreach (self::STANDARD_ACCESS as $email => $expected) {
            $user = $this->user($email);
            $this->assertNull($user->door_access, $email);

            foreach (Door::cases() as $door) {
                $shouldOpen = in_array($door->value, $expected['doors'], true);
                $this->assertSame($shouldOpen, $user->canOpenDoor($door), "{$email} door {$door->value}");

                foreach (array_keys($door->subDoors()) as $subDoor) {
                    $closed = in_array($door->value.'.'.$subDoor, $expected['closed'] ?? [], true);
                    $this->assertSame($shouldOpen && ! $closed, $user->canOpenSubDoor($door, $subDoor), "{$email} {$door->value}.{$subDoor}");
                }
            }
        }

        // Burhan is an admin without POS support, and an admin who is not yamen still needs that grant.
        $this->assertFalse($this->user('burhan@kaman.rest')->canAccessProject('kaman-support'));
    }

    public function test_moaz_has_only_customer_support_and_only_kaman_cashier_there(): void
    {
        $password = $this->user('moaz@kaman.rest')->password;
        (require database_path('migrations/2026_11_03_090000_open_support_and_development_for_moaz.php'))->up();
        (require database_path('migrations/2026_11_03_140000_limit_moaz_to_kaman_cashier_support.php'))->up();
        $moaz = $this->user('moaz@kaman.rest');

        $this->assertSame($password, $moaz->password);
        $this->assertFalse((bool) $moaz->is_admin);
        $this->assertNull($moaz->appDevelopmentRole());
        foreach (Door::cases() as $door) {
            $this->assertSame($door === Door::Support, $moaz->canOpenDoor($door), $door->value);
        }
        foreach (Door::Support->subDoors() as $key => $sub) {
            $this->assertTrue($moaz->canOpenSubDoor(Door::Support, $key), $key);
        }
        foreach (['workspace.overview', 'workspace.agents', 'workspace.finance.dashboard', 'app-development.index', 'workspace.users'] as $route) {
            $this->actingAs($moaz)->get(route($route))->assertForbidden();
        }

        Cache::put('kaman.rest.companies.v2', [
            ['id' => 'company-1', 'name' => 'Cashier Place', 'subdomain' => 'cashier-place', 'url' => 'https://x', 'color' => '', 'lang' => 'he', 'address' => 'Secret Street 5', 'logo' => '', 'hours' => 'Secret hours', 'cityId' => ''],
            ['id' => 'company-2', 'name' => 'Lite Place', 'subdomain' => 'lite-place', 'url' => 'https://y', 'color' => '', 'lang' => 'he', 'address' => '', 'logo' => '', 'hours' => '', 'cityId' => ''],
        ], 600);
        $sessions = app(\App\Services\KamanSupport\SupportDeviceSessionService::class);
        $beat = fn (string $id, string $restaurant, array $extra) => $sessions->heartbeat([
            'deviceId' => $id, 'restaurant' => $restaurant, 'restaurantName' => $restaurant, 'platform' => 'android', 'appState' => 'open',
            'logs' => [['id' => 'log-'.$id, 'level' => 'error', 'source' => 'api', 'message' => 'Failure from '.$id]],
            ...$extra,
        ]);
        $mall = $beat('reports-mall', 'cashier-place', ['appVersion' => '1.3.9', 'product' => 'mall']);
        $old = $beat('old-cashier', 'old-place', ['appVersion' => '1.0.6+12']);
        $lite = $beat('reports-lite', 'lite-place', ['appVersion' => '1.0.7', 'product' => 'lite']);
        $oldLite = $beat('old-lite', 'old-lite-place', ['appVersion' => '1.3.15+38']);

        $make = fn (string $public, string $restaurant, string $device, string $summary) => SupportTicket::query()->create([
            'public_id' => $public, 'restaurant' => $restaurant, 'restaurant_name' => $restaurant, 'device_id' => $device, 'summary' => $summary, 'status' => 'open',
        ]);
        $cashierTicket = $make('KAM-CASH01', 'cashier-place', 'reports-mall', 'Cashier problem');
        $liteTicket = $make('KAM-LITE01', 'lite-place', 'reports-lite', 'Lite problem');

        $this->actingAs($moaz);
        $this->app->forgetScopedInstances();

        $this->get(route('kaman-support.index'))->assertOk()
            ->assertSee('cashier-place', false)->assertSee('old-place', false)
            ->assertSee('Cashier Place', false)
            ->assertDontSee('Lite Place', false)->assertDontSee('lite-place', false)
            ->assertDontSee('old-lite-place', false)
            ->assertDontSee('Secret Street 5', false)->assertDontSee('Secret hours', false)
            ->assertDontSee('Lite problem', false);
        $this->getJson(route('kaman-support.board.feed'))->assertOk()->assertDontSee('lite-place', false);

        $this->get(route('kaman-support.logs'))->assertOk()
            ->assertSee('Failure from reports-mall', false)->assertSee('Failure from old-cashier', false)
            ->assertDontSee('Failure from reports-lite', false)->assertDontSee('Failure from old-lite', false)
            ->assertDontSee('name="restaurants[]" value="lite-place"', false);

        $this->get(route('kaman-support.inbox'))->assertOk()->assertSee('Cashier problem', false)->assertDontSee('Lite problem', false);
        $this->getJson(route('kaman-support.inbox.feed'))->assertOk()->assertDontSee('Lite problem', false)->assertJsonPath('stats.total', 1);
        $this->getJson(route('kaman-support.api.tickets.index'))->assertOk()->assertDontSee('Lite problem', false);

        // Another product's records stay closed even by their address.
        $this->getJson(route('kaman-support.show', $cashierTicket))->assertOk();
        $this->getJson(route('kaman-support.show', $liteTicket))->assertNotFound();
        $this->post(route('kaman-support.close', $liteTicket))->assertNotFound();
        $this->getJson(route('kaman-support.api.tickets.show', $liteTicket))->assertNotFound();
        $this->get(route('kaman-support.device.feed', $mall))->assertOk();
        $this->get(route('kaman-support.device.feed', $old))->assertOk();
        $this->get(route('kaman-support.device.feed', $lite))->assertNotFound();
        $this->get(route('kaman-support.device.feed', $oldLite))->assertNotFound();
        $this->post(route('kaman-support.device.commands', $lite), ['name' => 'retry_failed_prints'])->assertNotFound();
        $this->assertSame([], $this->getJson(route('kaman-support.restaurant.card', 'lite-place'))->assertOk()->json('card.devices'));
        $this->assertSame([], $this->getJson(route('kaman-support.restaurant.card', 'lite-place'))->json('card.tickets'));

        // Everyone else still sees every product.
        $this->actingAs($this->yamen);
        $this->app->forgetScopedInstances();
        $this->get(route('kaman-support.inbox'))->assertSee('Lite problem', false);
        $this->get(route('kaman-support.logs'))->assertSee('Failure from reports-lite', false);
    }

    public function test_only_yamen_moaz_and_ibrahim_stay_on_and_the_rest_can_be_turned_back_on(): void
    {
        $before = $this->user('ahmad@kaman.rest')->only(['password', 'projects', 'door_access', 'is_admin']);
        $ahmad = $this->user('ahmad@kaman.rest');
        $this->actingAs($ahmad);
        (require database_path('migrations/2026_11_03_120000_add_disabled_at_and_turn_off_other_users.php'))->up();
        $ahmad->refresh();

        // Someone already signed in is signed out on their next request.
        $this->get(route('form.index'))->assertRedirect(route('login'));
        $this->assertGuest();

        foreach (['yamen@kaman.rest', 'moaz@kaman.rest'] as $email) {
            $this->assertFalse($this->user($email)->isTurnedOff(), $email);
        }
        $this->assertSame(2, User::query()->whereNull('disabled_at')->count());

        $this->assertTrue($ahmad->isTurnedOff());
        $this->assertSame($before, $ahmad->only(['password', 'projects', 'door_access', 'is_admin']));

        $this->post('/login', ['email' => 'ahmad@kaman.rest', 'password' => 'Ahmad123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'moaz@kaman.rest', 'password' => 'Moaz123@']);
        $this->assertAuthenticatedAs($this->user('moaz@kaman.rest'));
        $this->post('/logout');

        // Yamen sees it on the page and cannot change the doors of a turned off account.
        $this->actingAs($this->yamen)->get(route('workspace.users'))->assertOk()->assertSee(__('access.turned_off'));
        $this->actingAs($this->yamen)->putJson(route('workspace.users.access', $ahmad), ['door' => 'form', 'on' => false])->assertStatus(422);

        // Others cannot use the switch, and Yamen's own account cannot be turned off.
        $this->actingAs($this->user('moaz@kaman.rest'))->putJson(route('workspace.users.account', $ahmad), ['on' => true])->assertForbidden();
        $this->actingAs($this->yamen)->putJson(route('workspace.users.account', $this->yamen), ['on' => false])->assertStatus(422);
        $this->assertFalse($this->yamen->refresh()->isTurnedOff());

        $this->actingAs($this->yamen)->putJson(route('workspace.users.account', $ahmad), ['on' => true])
            ->assertOk()
            ->assertJsonPath('row.turnedOff', false)
            ->assertJsonPath('row.doors.form.on', true);
        $this->post('/logout');
        $this->post('/login', ['email' => 'ahmad@kaman.rest', 'password' => 'Ahmad123']);
        $this->assertAuthenticatedAs($this->user('ahmad@kaman.rest'));
    }

    public function test_the_finance_section_opens_for_someone_with_finance_but_not_overview(): void
    {
        $burhan = $this->user('burhan@kaman.rest');
        $client = FinanceClient::query()->create(['company_id' => 'company-1', 'name' => 'Card Client', 'lang' => 'he']);
        $this->set($burhan, 'overview', null, false);

        $this->assertTrue($burhan->canSeeRestaurantSection(Door::Finance));
        $this->actingAs($burhan)->get(route('workspace.restaurant.finance', $client))->assertOk();

        $this->set($burhan, 'overview', 'card_finance', false);
        $this->assertFalse($burhan->canSeeRestaurantSection(Door::Finance));
    }

    public function test_existing_access_rules_keep_working(): void
    {
        $connections = $this->user('connections@kaman.rest');
        $this->actingAs($connections)->get(route('kaman-support.live'))->assertOk();
        $this->actingAs($connections)->get(route('kaman-support.inbox'))->assertForbidden();
    }

    public function test_access_fixed_by_the_account_cannot_be_changed(): void
    {
        foreach ([
            ['yamen@kaman.rest', 'finance', null],
            ['yamen@kaman.rest', 'support', 'logs'],
            ['connections@kaman.rest', 'support', 'tickets'],
        ] as [$email, $door, $subDoor]) {
            $this->actingAs($this->yamen)
                ->putJson(route('workspace.users.access', $this->user($email)), ['door' => $door, 'sub_door' => $subDoor, 'on' => $email !== 'yamen@kaman.rest'])
                ->assertStatus(422);
            $this->assertNull($this->user($email)->door_access, $email);
        }

        // What is left on those accounts can still be changed.
        $connections = $this->user('connections@kaman.rest');
        $this->set($connections, 'support', 'live', false);
        $this->actingAs($connections)->get(route('kaman-support.live'))->assertForbidden();
    }

    public function test_reseeding_keeps_what_yamen_changed(): void
    {
        $ahmad = $this->user('ahmad@kaman.rest');
        $this->set($ahmad, 'finance', null, true);

        $this->seed(WorkspaceUserSeeder::class);

        $this->assertSame(['finance' => true], $ahmad->refresh()->door_access);
    }

    public function test_someone_whose_first_page_is_locked_signs_in_to_a_door_they_can_open(): void
    {
        $ahmad = $this->user('ahmad@kaman.rest');
        $this->set($ahmad, 'overview', null, false);

        $this->assertSame(route('workspace.agents', ['page' => 'leads']), $ahmad->afterLoginUrl());
        $this->actingAs($ahmad)->get(route('design.modern-light'))->assertForbidden();
    }

    public function test_every_door_route_has_a_rule_so_no_page_is_left_unlocked(): void
    {
        $prefixes = ['workspace/finance', 'workspace/agents', 'kaman-support', 'api/kaman-support/v1', 'app-development'];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('web', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if ($uri !== $prefix && ! str_starts_with($uri, $prefix.'/')) {
                    continue;
                }
                $request = Request::create('/'.$uri);
                $request->setRouteResolver(fn () => $route);
                $route->bind($request);
                $this->assertNotNull(Door::forRequest($request), "{$uri} has no door rule");
                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked);
    }

    public function test_the_locked_page_and_new_text_exist_in_every_language(): void
    {
        $english = array_keys(require lang_path('en/access.php'));

        foreach (['ar' => 'rtl', 'he' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
            $this->assertSame($english, array_keys(require lang_path($locale.'/access.php')), $locale);

            $this->actingAs($this->user('ahmad@kaman.rest'))
                ->withSession(['locale' => $locale])
                ->get(route('workspace.finance.dashboard'))
                ->assertForbidden()
                ->assertSee('dir="'.$dir.'"', false)
                ->assertSee(__('access.locked_body', [], $locale));
        }
    }
}
