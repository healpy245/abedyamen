<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Sales\AgentAppointment;
use App\Models\Sales\AgentLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentLeadsTest extends TestCase
{
    use RefreshDatabase;

    private const LEADS = '/workspace/agents/leads';

    private const CALENDAR = '/workspace/agents/calendar';

    private const LEADS_READ = '/api/mobile/v1/agents/leads';

    private const CALENDAR_READ = '/api/mobile/v1/agents/calendar';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake([
            'https://kaman.rest/api/companies' => Http::response(['data' => ['companies' => [
                ['id' => 'c-1', 'name' => 'Asado', 'subdomain' => 'asado', 'full_path' => 'https://asado.kaman.rest', 'settings' => []],
            ]]]),
            'https://kaman.rest/api/company-areas' => Http::response(['data' => ['areas' => [
                ['id' => 7, 'name_ar' => 'الطيبة', 'name_en' => 'Taybe', 'name_he' => 'טייבה'],
            ]]]),
        ]);
    }

    private function member(array $doors = []): User
    {
        return User::factory()->create(['is_admin' => false, 'projects' => ['app-development'], 'door_access' => $doors === [] ? null : $doors]);
    }

    private function lead(array $attributes = []): AgentLead
    {
        return AgentLead::query()->create($attributes + ['name' => 'Ponte', 'phone' => '0501234567']);
    }

    private function appointment(array $attributes = []): AgentAppointment
    {
        return AgentAppointment::query()->create($attributes + ['restaurant_id' => 'c-1', 'at' => now()->addDay()]);
    }

    public function test_a_guest_gets_no_leads_or_appointments(): void
    {
        $this->getJson(self::LEADS_READ)->assertUnauthorized();
        $this->getJson(self::CALENDAR_READ)->assertUnauthorized();
        $this->postJson(self::LEADS, ['name' => 'Ponte', 'phone' => '050'])->assertUnauthorized();
        $this->assertSame(0, AgentLead::query()->count());
    }

    public function test_the_leads_sub_door_closes_every_lead_route_and_leaves_the_calendar_open(): void
    {
        $user = $this->member(['agents.leads' => false]);
        $lead = $this->lead();

        $this->actingAs($user)->getJson(self::LEADS_READ)->assertForbidden();
        $this->postJson(self::LEADS, ['name' => 'Blocked', 'phone' => '050'])->assertForbidden();
        $this->postJson(self::LEADS.'/import', ['browser' => 'b1', 'leads' => [['id' => 'l-1', 'name' => 'Blocked']]])->assertForbidden();
        $this->putJson(self::LEADS.'/'.$lead->id, ['stage' => 'won'])->assertForbidden();
        $this->postJson(self::LEADS.'/'.$lead->id.'/notes', ['text' => 'Blocked'])->assertForbidden();
        $this->deleteJson(self::LEADS.'/'.$lead->id)->assertForbidden();

        $this->assertSame('new', $lead->refresh()->stage->value);
        $this->assertSame(1, AgentLead::query()->count());
        $this->getJson(self::CALENDAR_READ)->assertOk();
    }

    public function test_the_calendar_sub_door_closes_every_appointment_route_and_leaves_leads_open(): void
    {
        $user = $this->member(['agents.calendar' => false]);
        $appointment = $this->appointment();

        $this->actingAs($user)->getJson(self::CALENDAR_READ)->assertForbidden();
        $this->postJson(self::CALENDAR, ['restaurant_id' => 'c-1', 'at' => '2026-11-10T08:00:00Z'])->assertForbidden();
        $this->postJson(self::CALENDAR.'/import', ['browser' => 'b1', 'appointments' => [['id' => 'p-1', 'at' => '2026-11-10T08:00:00Z']]])->assertForbidden();
        $this->putJson(self::CALENDAR.'/'.$appointment->id, ['status' => 'done'])->assertForbidden();
        $this->deleteJson(self::CALENDAR.'/'.$appointment->id)->assertForbidden();

        $this->assertSame('scheduled', $appointment->refresh()->status->value);
        $this->assertSame(1, AgentAppointment::query()->count());
        $this->getJson(self::LEADS_READ)->assertOk();
    }

    public function test_a_closed_agents_door_closes_both(): void
    {
        $user = $this->member(['agents' => false]);

        $this->actingAs($user)->getJson(self::LEADS_READ)->assertForbidden();
        $this->getJson(self::CALENDAR_READ)->assertForbidden();
        $this->postJson(self::LEADS, ['name' => 'Blocked', 'phone' => '050'])->assertForbidden();
    }

    public function test_a_chatbot_only_account_is_refused_though_the_door_lets_it_through(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'projects' => ['ai-chatbot']]);

        $this->actingAs($user)->getJson(self::LEADS_READ)->assertForbidden();
        $this->getJson(self::CALENDAR_READ)->assertForbidden();
        $this->postJson(self::LEADS, ['name' => 'Blocked', 'phone' => '050'])->assertForbidden();
        $this->assertSame(0, AgentLead::query()->count());
    }

    public function test_a_lead_saved_by_one_person_reads_back_for_another(): void
    {
        $web = $this->member();
        $app = $this->member();

        $created = $this->actingAs($web)->postJson(self::LEADS, [
            'name' => 'Ponte',
            'contact' => 'Alaa',
            'phone' => '0501234567',
            'city_id' => '7',
        ])->assertCreated();
        $id = $created->json('lead.id');
        $this->assertSame($web->id, $created->json('lead.agent_id'));

        $read = $this->actingAs($app)->getJson(self::LEADS_READ)->assertOk();
        $this->assertSame('Ponte', $read->json('leads.0.name'));
        $this->assertSame('new', $read->json('leads.0.stage.value'));
        $this->assertSame('الطيبة', $read->json('leads.0.city'));
        $this->assertSame($app->id, $read->json('me'));
        $this->assertSame(['7'], array_column($read->json('cities'), 'id'));

        // And the other way: what the second person changes is what the first one reads.
        $this->putJson(self::LEADS.'/'.$id, ['stage' => 'negotiation', 'agent_id' => $app->id])->assertOk();
        $this->postJson(self::LEADS.'/'.$id.'/notes', ['text' => 'Wants a demo'])->assertOk();

        $back = $this->actingAs($web)->getJson(self::LEADS_READ)->assertOk();
        $this->assertSame('negotiation', $back->json('leads.0.stage.value'));
        $this->assertSame($app->name, $back->json('leads.0.agent'));
        $this->assertSame('Wants a demo', $back->json('leads.0.notes.0.text'));
        $this->assertSame($app->name, $back->json('leads.0.notes.0.by'));
    }

    public function test_a_lead_needs_a_name_and_phone_and_a_known_stage(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJson(self::LEADS, ['name' => 'Ponte'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson(self::LEADS, ['phone' => '050'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(self::LEADS, ['name' => 'Ponte', 'phone' => '050', 'stage' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('stage');
    }

    public function test_a_partial_update_keeps_the_other_fields(): void
    {
        $lead = $this->lead(['contact' => 'Alaa']);

        $this->actingAs($this->member())->putJson(self::LEADS.'/'.$lead->id, ['stage' => 'won'])->assertOk();

        $lead->refresh();
        $this->assertSame('won', $lead->stage->value);
        $this->assertSame('Alaa', $lead->contact);
        $this->assertSame('Ponte', $lead->name);
    }

    public function test_an_appointment_on_a_lead_becomes_its_follow_up_and_keeps_its_instant(): void
    {
        $lead = $this->lead();
        $user = $this->member();

        $created = $this->actingAs($user)->postJson(self::CALENDAR, [
            'lead_id' => $lead->id,
            'at' => '2026-11-10T10:00:00+02:00',
            'type' => 'demo',
        ])->assertCreated();

        $this->assertSame('2026-11-10T08:00:00+00:00', $created->json('appointment.at'));
        $this->assertSame('Ponte', $created->json('appointment.title'));
        $this->assertSame('2026-11-10 08:00:00', $lead->refresh()->next_follow_up_at->format('Y-m-d H:i:s'));

        $read = $this->getJson(self::CALENDAR_READ)->assertOk();
        $this->assertSame('demo', $read->json('appointments.0.type.value'));
        $this->assertSame(['id', 'name', 'contact', 'phone'], array_keys($read->json('leads.0')));
        $this->assertSame('Asado', $read->json('restaurants.0.name'));
    }

    public function test_an_appointment_needs_a_lead_or_a_restaurant(): void
    {
        $this->actingAs($this->member())
            ->postJson(self::CALENDAR, ['at' => '2026-11-10T08:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lead_id');
    }

    public function test_deleting_a_lead_takes_its_appointments_with_it(): void
    {
        $lead = $this->lead();
        $this->appointment(['agent_lead_id' => $lead->id, 'restaurant_id' => null]);
        $kept = $this->appointment();

        $this->actingAs($this->member())->deleteJson(self::LEADS.'/'.$lead->id)->assertOk();

        $this->assertSame([$kept->id], AgentAppointment::query()->pluck('id')->all());
    }

    public function test_an_import_comes_in_once_and_belongs_to_whoever_sent_it(): void
    {
        $user = $this->member();
        $payload = ['browser' => 'b1', 'leads' => [[
            'id' => 'l-501',
            'name' => 'Ponte',
            'contact' => 'Alaa',
            'phone' => '0501234567',
            'city_id' => '7',
            'agentId' => 'me',
            'stage' => 'demo',
            'next_follow_up_at' => '2026-11-10T08:00:00.000Z',
            'notes' => [['at' => '2026-10-01T09:00:00.000Z', 'text' => 'First call']],
        ], ['id' => 'l-502', 'name' => '  ']]];

        $this->actingAs($user)->postJson(self::LEADS.'/import', $payload)->assertOk()->assertJson(['imported' => 1]);
        $this->postJson(self::LEADS.'/import', $payload)->assertOk()->assertJson(['imported' => 0]);

        $lead = AgentLead::query()->sole();
        $this->assertSame($user->id, $lead->agent_id);
        $this->assertSame('demo', $lead->stage->value);
        $this->assertSame('2026-10-01 09:00:00', $lead->notes()->sole()->created_at->format('Y-m-d H:i:s'));

        // Every browser numbered its rows from the same start, so the same id elsewhere is another lead.
        $this->postJson(self::LEADS.'/import', ['browser' => 'b2', 'leads' => [['id' => 'l-501', 'name' => 'Other']]])->assertJson(['imported' => 1]);
        $this->assertSame(2, AgentLead::query()->count());
    }

    public function test_imported_appointments_find_their_imported_lead(): void
    {
        $user = $this->member();
        $this->actingAs($user)->postJson(self::LEADS.'/import', ['browser' => 'b1', 'leads' => [['id' => 'l-501', 'name' => 'Ponte']]])->assertOk();
        $payload = ['browser' => 'b1', 'appointments' => [
            ['id' => 'p-1', 'lead_id' => 'l-501', 'restaurant_name' => 'Ponte', 'at' => '2026-11-10T08:00:00.000Z', 'type' => 'call', 'status' => 'done', 'notes' => 'Ok'],
            ['id' => 'p-2', 'lead_id' => 'l-999', 'restaurant_name' => 'Gone', 'at' => '2026-11-11T08:00:00.000Z'],
            ['id' => 'p-3', 'restaurant_id' => 'c-1', 'at' => 'not a date'],
        ]];

        $this->postJson(self::CALENDAR.'/import', $payload)->assertOk()->assertJson(['imported' => 2]);
        $this->postJson(self::CALENDAR.'/import', $payload)->assertOk()->assertJson(['imported' => 0]);

        $read = $this->getJson(self::CALENDAR_READ)->assertOk();
        $this->assertSame('Ponte', $read->json('appointments.0.title'));
        $this->assertSame(AgentLead::query()->sole()->id, $read->json('appointments.0.lead_id'));
        $this->assertSame('done', $read->json('appointments.0.status.value'));
        $this->assertSame('Gone', $read->json('appointments.1.title'));
        $this->assertNull($read->json('appointments.1.lead_id'));
    }

    public function test_the_page_only_tells_the_script_about_the_sub_doors_that_are_open(): void
    {
        $user = $this->member(['agents.calendar' => false]);

        $boot = $this->actingAs($user)->get('/workspace/agents/leads')->assertOk()->getContent();

        $this->assertStringContainsString('"leads":"'.str_replace('/', '\/', url(self::LEADS_READ)).'"', $boot);
        $this->assertStringContainsString('"calendar":null', $boot);
    }
}
