<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\ServiceStatus;
use App\Enums\Sales\QuoteStatus;
use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\AppDevelopmentTicketType;
use App\Enums\AppDevelopmentTaskStatus;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\StorageItem;
use App\Models\Sales\SalesQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake([
            'https://kaman.rest/api/companies' => Http::response(['data' => ['companies' => [
                ['id' => 'c-1', 'name' => 'Asado', 'subdomain' => 'asado', 'full_path' => 'https://asado.kaman.rest', 'settings' => ['lang' => 'ar']],
            ]]]),
            'https://kaman.rest/api/company-areas' => Http::response(['data' => ['areas' => []]]),
        ]);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['projects' => ['app-development']]);
    }

    public function test_a_guest_gets_401_and_no_data(): void
    {
        $this->getJson('/api/mobile/v1/me')->assertUnauthorized();
        $this->getJson('/api/mobile/v1/finance/clients')->assertUnauthorized();
    }

    public function test_me_lists_doors_and_reflects_locks(): void
    {
        $response = $this->actingAs($this->member())->getJson('/api/mobile/v1/me')->assertOk();

        $doors = collect($response->json('doors'))->keyBy('id');
        $this->assertTrue($doors['development']['open']);
        $this->assertFalse($doors['finance']['open']);
        $this->assertFalse($doors['support']['open']);
        $this->assertFalse(collect($doors['development']['subs'])->firstWhere('key', 'reports')['open']);
    }

    public function test_every_door_route_is_listed_in_the_door_rules(): void
    {
        // A mobile route missing from Door::routeRules() would be open to every signed-in user.
        // The restaurant view is behind any of four doors, which its controller checks.
        $ungated = ['mobile.me', 'mobile.flash', 'mobile.users', 'mobile.restaurants.show'];
        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (! str_starts_with($name, 'mobile.') || in_array($name, $ungated, true)) {
                continue;
            }
            $covered = collect(\App\Enums\Door::routeRules())->contains(
                fn (array $rule): bool => collect($rule[0])->contains(fn (string $pattern): bool => \Illuminate\Support\Str::is($pattern, $name)),
            );
            $this->assertTrue($covered, $name.' is not behind a door');
        }
    }

    public function test_finance_stays_locked_for_a_member_without_the_door(): void
    {
        $client = FinanceClient::query()->create(['company_id' => 'c-1', 'name' => 'Asado']);
        $user = $this->member();

        foreach (['dashboard', 'clients', 'clients/'.$client->id, 'deals', 'storage'] as $path) {
            $this->actingAs($user)->getJson('/api/mobile/v1/finance/'.$path)->assertForbidden();
        }
    }

    public function test_a_sub_door_switch_closes_its_route(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'door_access' => ['finance.storage' => false]]);

        $this->actingAs($user)->getJson('/api/mobile/v1/finance/storage')->assertForbidden();
        $this->actingAs($user)->getJson('/api/mobile/v1/finance/dashboard')->assertOk();
    }

    public function test_finance_reads_return_the_shapes_the_app_uses(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = FinanceClient::query()->create([
            'company_id' => 'c-1',
            'name' => 'Asado',
            'service_status' => ServiceStatus::Active,
            'payment_status' => PaymentStatus::Failed,
            'monthly_fee' => 250,
            'card_brand' => 'visa',
            'card_last4' => '4242',
        ]);
        StorageItem::query()->create(['name' => 'Printer', 'category' => 'hardware', 'unit_price' => 900, 'track_stock' => true, 'stock' => 1, 'low_stock' => 2]);

        $this->actingAs($admin)->getJson('/api/mobile/v1/finance/dashboard')
            ->assertOk()
            ->assertJsonPath('failed', 1)
            ->assertJsonStructure(['low_stock' => [['id', 'name', 'stock', 'low']], 'trend', 'payment_mix']);

        $this->actingAs($admin)->getJson('/api/mobile/v1/finance/clients')
            ->assertOk()
            ->assertJsonPath('clients.0.payment_status.value', 'failed')
            ->assertJsonPath('totals.failed', 1);

        $this->actingAs($admin)->getJson('/api/mobile/v1/finance/clients/'.$client->id)
            ->assertOk()
            ->assertJsonPath('card.last4', '4242')
            ->assertJsonPath('billing_lines.0.amount', 250);

        $this->actingAs($admin)->getJson('/api/mobile/v1/finance/storage')
            ->assertOk()
            ->assertJsonStructure(['items', 'stats' => ['items', 'units', 'value', 'low'], 'categories', 'movements']);

        $this->actingAs($admin)->getJson('/api/mobile/v1/finance/deals')->assertOk();
    }

    public function test_an_agent_only_reads_their_own_quotes(): void
    {
        $client = FinanceClient::query()->create(['company_id' => 'c-1', 'name' => 'Asado']);
        $agent = $this->member();
        $other = $this->member();
        $quote = SalesQuote::query()->create(['number' => 1001, 'finance_client_id' => $client->id, 'agent_id' => $other->id, 'status' => QuoteStatus::Draft, 'lang' => 'ar']);

        $this->actingAs($agent)->getJson('/api/mobile/v1/agents/sales')->assertOk()->assertJsonCount(0, 'quotes');
        $this->actingAs($agent)->getJson('/api/mobile/v1/agents/sales/'.$quote->id)->assertForbidden();

        $this->actingAs($other)->getJson('/api/mobile/v1/agents/sales')->assertOk()->assertJsonCount(1, 'quotes');
        $this->actingAs($other)->getJson('/api/mobile/v1/agents/sales/'.$quote->id)->assertOk()->assertJsonPath('status.value', 'draft');
        $this->actingAs($other)->getJson('/api/mobile/v1/agents/packages')->assertOk();
    }

    public function test_development_reads(): void
    {
        $user = $this->member();
        AppDevelopmentMember::query()->create(['user_id' => $user->id, 'role' => AppDevelopmentRole::Developer]);
        $ticket = AppDevelopmentTicket::query()->create([
            'ticket_number' => 'KMN-1',
            'title' => 'Printer stops',
            'description' => 'It stops',
            'type' => AppDevelopmentTicketType::Bug,
            'priority' => AppDevelopmentTicketPriority::High,
            'status' => AppDevelopmentTicketStatus::Open,
            'created_by' => $user->id,
        ]);
        $task = AppDevelopmentTask::query()->create([
            'ticket_id' => $ticket->id,
            'title' => 'Find the cause',
            'assignee_id' => $user->id,
            'priority' => AppDevelopmentTicketPriority::Normal,
            'status' => AppDevelopmentTaskStatus::Todo,
            'created_by' => $user->id,
        ]);

        $ticket->activities()->create(['user_id' => $user->id, 'event_type' => 'created']);

        $this->actingAs($user)->getJson('/api/mobile/v1/development/tickets')->assertOk()->assertJsonPath('tickets.0.number', 'KMN-1');
        $this->actingAs($user)->getJson('/api/mobile/v1/development/tickets/KMN-1')->assertOk()->assertJsonPath('tasks.0.id', $task->id);
        $this->actingAs($user)->getJson('/api/mobile/v1/development/tasks')->assertOk()->assertJsonPath('tasks.0.running', false);
        $this->actingAs($user)->getJson('/api/mobile/v1/development/tasks/'.$task->id)->assertOk();
        $this->actingAs($user)->getJson('/api/mobile/v1/development/releases')->assertOk();
        $this->actingAs($user)->getJson('/api/mobile/v1/development/options')->assertOk();
        // Reports are admin-only by default.
        $this->actingAs($user)->getJson('/api/mobile/v1/development/reports')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/api/mobile/v1/development/reports')->assertOk()->assertJsonPath('available', true)->assertJsonStructure(['activity' => [['title', 'detail', 'when', 'tone']]]);
    }

    public function test_the_restaurant_view_returns_every_section_and_keeps_the_webs_locks(): void
    {
        $client = FinanceClient::query()->create(['name' => 'Asado', 'company_id' => 'c-1', 'monthly_fee' => 250]);
        \App\Models\KamanSupport\SupportTicket::query()->forceCreate(['public_id' => 'T-1', 'restaurant' => 'asado', 'summary' => 'Printer', 'status' => 'open']);
        $admin = $this->member(['name' => 'yamen']);

        $view = $this->actingAs($admin)->getJson('/api/mobile/v1/restaurants/c-1')->assertOk();
        $view->assertJsonPath('restaurant.name', 'Asado')
            ->assertJsonPath('links.enter', null)
            ->assertJsonPath('finance.locked', false)
            ->assertJsonPath('finance.client.id', $client->id)
            ->assertJsonPath('support.locked', false)
            ->assertJsonPath('support.slug', 'asado')
            ->assertJsonPath('support.tickets.0.id', 'T-1');
        $this->assertArrayNotHasKey('card', $view->json('finance.client'));

        // The Support door asks by slug, and gets the same restaurant.
        $this->getJson('/api/mobile/v1/restaurants/asado')->assertOk()->assertJsonPath('restaurant.id', 'c-1');
        $this->getJson('/api/mobile/v1/restaurants/nowhere')->assertNotFound();

        $closed = $this->member(['door_access' => ['overview' => false, 'support' => false, 'agents' => false, 'finance' => false]]);
        $this->actingAs($closed)->getJson('/api/mobile/v1/restaurants/c-1')->assertForbidden();

        $noFinance = $this->member(['door_access' => ['finance' => false]]);
        $locked = $this->actingAs($noFinance)->getJson('/api/mobile/v1/restaurants/c-1?surface=finance')->assertOk();
        $locked->assertJsonPath('finance.locked', true);
        $this->assertArrayNotHasKey('client', $locked->json('finance'));
    }

    public function test_the_restaurant_view_opens_a_finance_client_that_is_no_company(): void
    {
        $client = FinanceClient::query()->create(['name' => 'Corner Cafe', 'company_id' => '']);

        $this->actingAs($this->member(['name' => 'yamen']))->getJson('/api/mobile/v1/restaurants/fin-'.$client->id)
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Corner Cafe')
            ->assertJsonPath('finance.client.id', $client->id);
    }

    public function test_the_app_has_no_chatbots_door_and_users_keep_their_gate(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertNotContains('chatbots', collect($this->actingAs($admin)->getJson('/api/mobile/v1/me')->json('doors'))->pluck('id')->all());
        $this->actingAs($admin)->getJson('/api/mobile/v1/chatbots')->assertNotFound();

        $this->actingAs($this->member())->getJson('/api/mobile/v1/users')->assertForbidden();
        $this->actingAs(User::factory()->create(['email' => 'yamen@kaman.rest']))->getJson('/api/mobile/v1/users')->assertOk();
    }

    public function test_mowahada_can_reach_me_but_no_door(): void
    {
        $user = User::factory()->create(['email' => 'mowahada@workspace.com', 'projects' => ['ai-chatbot']]);

        $this->actingAs($user)->getJson('/api/mobile/v1/me')->assertOk();
        $this->assertSame([], collect($this->actingAs($user)->getJson('/api/mobile/v1/me')->json('doors'))->where('open', true)->all());
        $this->actingAs($user)->getJson('/api/mobile/v1/finance/clients')->assertForbidden();
    }

    public function test_flash_reports_what_a_refused_form_said_and_only_once(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->withSession(['_flash' => ['new' => [], 'old' => ['error']], 'error' => 'Refused'])
            ->getJson('/api/mobile/v1/flash')
            ->assertOk()
            ->assertJsonPath('error', 'Refused');
    }

    public function test_support_feeds_follow_their_sub_doors(): void
    {
        $user = User::factory()->create(['projects' => ['kaman-support']]);

        $this->actingAs($user)->getJson('/kaman-support/board/feed')->assertOk()->assertJsonStructure(['restaurants', 'labels' => ['title', 'count']]);
        $this->actingAs($user)->getJson('/kaman-support/logs/feed')->assertOk()->assertJsonStructure(['issues', 'choices', 'labels' => ['issues']]);
        $this->actingAs($user)->getJson('/kaman-support/inbox/feed')->assertOk()->assertJsonStructure(['tickets', 'stats' => ['total', 'open'], 'labels' => ['lead']]);

        $closed = User::factory()->create(['projects' => ['kaman-support'], 'door_access' => ['support.logs' => false, 'support.tickets' => false]]);
        $this->actingAs($closed)->getJson('/kaman-support/logs/feed')->assertForbidden();
        $this->actingAs($closed)->getJson('/kaman-support/inbox/feed')->assertForbidden();
        $this->actingAs($closed)->getJson('/kaman-support/board/feed')->assertOk();

        $this->actingAs($this->member())->getJson('/kaman-support/board/feed')->assertForbidden();
        $connections = User::factory()->create(['email' => 'connections@kaman.rest', 'projects' => ['kaman-support']]);
        $this->actingAs($connections)->getJson('/kaman-support/board/feed')->assertOk();
        $this->actingAs($connections)->getJson('/kaman-support/inbox/feed')->assertForbidden();
    }

    public function test_overview_and_restaurants(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->getJson('/api/mobile/v1/overview')->assertOk()->assertJsonPath('stats.restaurants', 1);
        $this->actingAs($admin)->getJson('/api/mobile/v1/restaurants')->assertOk()->assertJsonPath('restaurants.0.name', 'Asado');
        // The web dashboard reads the same summary.
        $this->actingAs($admin)->get('/workspace/overview/dashboard')->assertOk();
    }
}
