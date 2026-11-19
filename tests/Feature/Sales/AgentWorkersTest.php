<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\Door;
use App\Models\Finance\FinanceClient;
use App\Models\Sales\AgentAppointment;
use App\Models\Sales\AgentLead;
use App\Models\Sales\SalesCatalogItem;
use App\Models\Sales\SalesQuote;
use App\Models\User;
use App\Notifications\SalesDealRequested;
use App\Services\Sales\DealRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Sales agents work as workers (their own leads, meetings and quotes) or as agents admins (everyone's).
 */
class AgentWorkersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake([
            'https://kaman.rest/api/companies' => Http::response(['data' => ['companies' => []]]),
            'https://kaman.rest/api/company-areas' => Http::response(['data' => ['areas' => []]]),
        ]);
    }

    private function worker(): User
    {
        // Only the Sales Agents door, as a worker.
        return User::factory()->create(['is_admin' => false, 'projects' => [], 'door_access' => ['overview' => false]]);
    }

    private function agentsAdmin(): User
    {
        return User::factory()->create(['is_admin' => false, 'projects' => [], 'door_access' => ['overview' => false, 'agents.manage' => true]]);
    }

    public function test_a_worker_only_sees_and_changes_their_own_leads_and_meetings(): void
    {
        $nael = $this->worker();
        $osama = $this->worker();
        $mine = AgentLead::query()->create(['name' => 'Nael lead', 'phone' => '050', 'agent_id' => $nael->id]);
        $theirs = AgentLead::query()->create(['name' => 'Osama lead', 'phone' => '051', 'agent_id' => $osama->id]);
        AgentAppointment::query()->create(['agent_lead_id' => $mine->id, 'agent_id' => $nael->id, 'at' => now()->addDay()]);
        $other = AgentAppointment::query()->create(['agent_lead_id' => $theirs->id, 'agent_id' => $osama->id, 'at' => now()->addDay()]);

        $this->assertFalse($nael->managesAgents());
        $leads = $this->actingAs($nael)->getJson('/api/mobile/v1/agents/leads')->assertOk();
        $this->assertSame(['Nael lead'], array_column($leads->json('leads'), 'name'));
        $this->assertSame([$nael->id], array_column($leads->json('agents'), 'id'));

        $this->putJson('/workspace/agents/leads/'.$theirs->id, ['stage' => 'won'])->assertForbidden();
        $this->postJson('/workspace/agents/leads/'.$theirs->id.'/notes', ['text' => 'x'])->assertForbidden();
        $this->deleteJson('/workspace/agents/leads/'.$theirs->id)->assertForbidden();
        $this->putJson('/workspace/agents/calendar/'.$other->id, ['notes' => 'x'])->assertForbidden();
        $this->postJson('/workspace/agents/calendar', ['lead_id' => $theirs->id, 'at' => now()->addDays(2)->toIso8601String()])->assertForbidden();

        // Their own lead stays theirs: a worker cannot hand it to someone else.
        $this->putJson('/workspace/agents/leads/'.$mine->id, ['stage' => 'waiting', 'agent_id' => $osama->id])->assertOk();
        $this->assertSame($nael->id, $mine->fresh()->agent_id);
        $created = $this->postJson('/workspace/agents/leads', ['name' => 'New', 'phone' => '052', 'agent_id' => $osama->id])->assertCreated();
        $this->assertSame($nael->id, $created->json('lead.agent_id'));
    }

    public function test_contacted_has_three_sub_statuses(): void
    {
        $nael = $this->worker();
        $lead = AgentLead::query()->create(['name' => 'Cafe', 'phone' => '050', 'agent_id' => $nael->id]);

        foreach (['no_answer', 'deciding', 'not_interested'] as $stage) {
            $this->actingAs($nael)->putJson('/workspace/agents/leads/'.$lead->id, ['stage' => $stage])->assertOk()
                ->assertJsonPath('lead.stage.value', $stage)
                ->assertJsonPath('lead.stage.group', 'contacted')
                ->assertJsonPath('lead.stage.group_label', __('sales.lead_stage.contacted'));
        }

        // The phone app or an old page may still send the plain "contacted".
        $this->putJson('/workspace/agents/leads/'.$lead->id, ['stage' => 'contacted'])->assertOk()->assertJsonPath('lead.stage.value', 'deciding');
        $stages = array_column($this->getJson('/api/mobile/v1/agents/leads')->json('stages'), 'value');
        $this->assertSame(['new', 'no_answer', 'deciding', 'not_interested', 'meeting', 'waiting', 'won', 'lost'], $stages);
    }

    public function test_the_leads_report_follows_the_filters_and_who_is_asking(): void
    {
        $nael = $this->worker();
        $osama = $this->worker();
        $abed = $this->agentsAdmin();
        $recent = AgentLead::query()->create(['name' => 'Recent Cafe', 'phone' => '050', 'agent_id' => $nael->id, 'stage' => 'no_answer']);
        $old = AgentLead::query()->create(['name' => 'Old Grill', 'phone' => '051', 'agent_id' => $nael->id, 'stage' => 'won']);
        $old->forceFill(['created_at' => now()->subMonths(3)])->save();
        AgentLead::query()->create(['name' => 'Osama Pizza', 'phone' => '052', 'agent_id' => $osama->id]);

        // A worker's report holds only their own leads, and the board groups with sub-status counts.
        $this->actingAs($nael)->get(route('workspace.agents.leads.report'))
            ->assertOk()
            ->assertSee(__('sales.report.title'))
            ->assertSee('Recent Cafe')
            ->assertSee('Old Grill')
            ->assertDontSee('Osama Pizza')
            ->assertSee(__('sales.lead_stage.contacted').' › '.__('sales.lead_stage.no_answer'))
            ->assertDontSee(__('sales.report.by_agent'))
            ->assertSee('html2pdf', false);

        // Quick pick: the last month leaves out the lead added three months ago.
        $this->get(route('workspace.agents.leads.report', ['period' => 'month']))->assertOk()->assertSee('Recent Cafe')->assertDontSee('Old Grill');
        // A custom range covering only the old lead.
        $day = now()->subMonths(3)->format('Y-m-d');
        $this->get(route('workspace.agents.leads.report', ['period' => 'custom', 'from' => $day, 'to' => $day]))->assertOk()->assertSee('Old Grill')->assertDontSee('Recent Cafe');
        // A worker cannot pull another agent's leads through the agent filter.
        $this->get(route('workspace.agents.leads.report', ['agent' => $osama->id]))->assertOk()->assertDontSee('Osama Pizza');

        // An agents admin gets everyone, broken down by agent, and can narrow to one agent.
        $this->actingAs($abed)->get(route('workspace.agents.leads.report'))->assertOk()->assertSee('Osama Pizza')->assertSee(__('sales.report.by_agent'));
        $this->get(route('workspace.agents.leads.report', ['agent' => $osama->id]))->assertOk()->assertSee('Osama Pizza')->assertDontSee('Recent Cafe');

        // The leads page reads when each lead was added, for the "Added" filter.
        $rows = collect($this->actingAs($nael)->getJson('/api/mobile/v1/agents/leads')->assertOk()->json('leads'))->keyBy('name');
        $this->assertSame($recent->created_at->toIso8601String(), $rows['Recent Cafe']['created_at']);
        auth()->logout();
        $this->get(route('workspace.agents.leads.report'))->assertRedirect();
    }

    public function test_an_agents_admin_sees_and_assigns_everyones_work(): void
    {
        $nael = $this->worker();
        $abed = $this->agentsAdmin();
        $lead = AgentLead::query()->create(['name' => 'Nael lead', 'phone' => '050', 'agent_id' => $nael->id]);

        $this->assertTrue($abed->managesAgents());
        $this->assertFalse($abed->canOpenDoor(Door::Finance));
        $read = $this->actingAs($abed)->getJson('/api/mobile/v1/agents/leads')->assertOk();
        $this->assertSame(['Nael lead'], array_column($read->json('leads'), 'name'));
        $this->putJson('/workspace/agents/leads/'.$lead->id, ['agent_id' => $abed->id])->assertOk();
        $this->assertSame($abed->id, $lead->fresh()->agent_id);
    }

    public function test_quotes_follow_the_same_rule(): void
    {
        $nael = $this->worker();
        $abed = $this->agentsAdmin();
        $client = FinanceClient::query()->create(['company_id' => 'c-1', 'name' => 'Ponte', 'lang' => 'he']);
        $base = SalesCatalogItem::query()->where('kind', 'base')->firstOrFail();
        $this->actingAs($nael)->post(route('workspace.agents.sales.store'), ['finance_client_id' => $client->id, 'lines' => [['kind' => 'base', 'ref_id' => $base->id]]])->assertRedirect();
        $quote = SalesQuote::query()->sole();

        $this->actingAs($this->worker())->get(route('workspace.agents.sales.panel', $quote))->assertForbidden();
        $this->actingAs($abed)->get(route('workspace.agents.sales.panel', $quote))->assertOk();
        $this->actingAs($abed)->get(route('workspace.agents.sales'))->assertOk()->assertSee($quote->title());
    }

    public function test_deal_requests_reach_finance_users_who_are_not_admins(): void
    {
        Notification::fake();
        $suzi = User::factory()->create(['is_admin' => false, 'projects' => [], 'door_access' => ['finance' => true]]);
        $nael = $this->worker();
        $client = FinanceClient::query()->create(['company_id' => 'c-1', 'name' => 'Ponte', 'lang' => 'he']);
        $quote = SalesQuote::query()->forceCreate(['number' => 2001, 'status' => 'signed', 'lang' => 'he', 'finance_client_id' => $client->id, 'agent_id' => $nael->id]);

        app(DealRequests::class)->request($quote, 'Paid', [], $nael);

        Notification::assertSentTo($suzi, SalesDealRequested::class);
        Notification::assertNotSentTo($nael, SalesDealRequested::class);
    }

    public function test_fregmac_is_a_super_admin_like_yamen(): void
    {
        $fregmac = User::factory()->create(['email' => 'fregmac@kaman.rest', 'is_admin' => true]);

        $this->assertTrue($fregmac->managesUsers());
        foreach (Door::cases() as $door) {
            $this->assertTrue($fregmac->canOpenDoor($door), $door->value);
        }
        $this->actingAs($fregmac)->get(route('workspace.users'))->assertOk();
    }

    public function test_yamen_adds_a_user_and_sets_their_password(): void
    {
        $yamen = User::factory()->create(['email' => 'yamen@kaman.rest', 'is_admin' => true]);

        $this->actingAs($yamen)->post(route('workspace.users.store'), [
            'name' => 'Suzi', 'email' => 'Suzi@Kaman.rest', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrorsIn('newUser', 'password');

        $this->post(route('workspace.users.store'), [
            'name' => 'Suzi', 'email' => 'Suzi@Kaman.rest', 'password' => 'Secret-123', 'password_confirmation' => 'Secret-123',
        ])->assertRedirect();
        $suzi = User::query()->where('email', 'suzi@kaman.rest')->sole();
        $this->assertFalse($suzi->is_admin);
        $this->assertTrue(password_verify('Secret-123', $suzi->password));

        $this->put(route('workspace.users.password', $suzi), ['password' => 'Another-456', 'password_confirmation' => 'Another-456'])->assertRedirect();
        $this->assertTrue(password_verify('Another-456', $suzi->fresh()->password));

        // Only the user managers do this.
        $this->actingAs($suzi)->put(route('workspace.users.password', $yamen), ['password' => 'Hacked-789', 'password_confirmation' => 'Hacked-789'])->assertForbidden();
        $this->actingAs($suzi)->post(route('workspace.users.store'), ['name' => 'X', 'email' => 'x@kaman.rest', 'password' => 'Secret-123', 'password_confirmation' => 'Secret-123'])->assertForbidden();
    }
}
