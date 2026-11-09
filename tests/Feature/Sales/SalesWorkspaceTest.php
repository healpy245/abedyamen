<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\Finance\HardwareStatus;
use App\Enums\Finance\InvoiceType;
use App\Enums\Sales\LeadStage;
use App\Enums\Sales\QuoteStatus;
use App\Models\KamanWhatsappLine;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\StorageItem;
use App\Models\Sales\AgentLead;
use App\Models\Sales\SalesCatalogItem;
use App\Models\Sales\SalesQuote;
use App\Models\User;
use App\Notifications\SalesDealRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SalesWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private FinanceClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake([
            'https://kaman.rest/api/companies' => Http::response(['data' => ['companies' => []]]),
            'https://kaman.rest/api/company-areas' => Http::response(['data' => ['areas' => []]]),
        ]);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->agent = User::factory()->create(['is_admin' => false]);
        $this->client = FinanceClient::query()->create(['company_id' => 'c-ponte', 'name' => 'Ponte', 'lang' => 'ar', 'contact_name' => 'עלאא חג יחיא', 'phone' => '0501234567']);
    }

    private function base(): SalesCatalogItem
    {
        return SalesCatalogItem::query()->where('kind', 'base')->firstOrFail();
    }

    private function feature(string $name): SalesCatalogItem
    {
        return SalesCatalogItem::query()->where('kind', 'feature')->where('name', $name)->firstOrFail();
    }

    private function quoteFor(User $user, array $lines): SalesQuote
    {
        $this->actingAs($user)->post(route('workspace.agents.sales.store'), [
            'finance_client_id' => $this->client->id,
            'lines' => $lines,
        ])->assertRedirect();

        return SalesQuote::query()->latest('id')->firstOrFail();
    }

    public function test_price_list_is_seeded_and_both_pages_open(): void
    {
        $this->assertSame(7, SalesCatalogItem::query()->where('kind', 'package')->count());
        $this->assertSame(11, SalesCatalogItem::query()->where('kind', 'feature')->count());
        $this->assertSame(300.0, (float) $this->base()->price);
        $this->assertSame(0, SalesCatalogItem::query()->whereIn('kind', ['base', 'feature'])->where(fn ($q) => $q->whereNull('name_ar')->orWhereNull('name_en'))->count());

        $this->actingAs($this->agent)->get(route('workspace.agents.sales'))->assertOk()->assertSee(__('sales.monthly_offer'))->assertSee(__('sales.devices_offer'));
        $this->actingAs($this->agent)->get(route('workspace.agents.packages'))->assertOk()->assertSee($this->base()->localName())->assertDontSee('Kaman 1');
        $this->actingAs($this->agent)->get(route('workspace.agents.packages', ['tab' => 'equipment']))->assertOk()->assertSee(StorageItem::query()->where('name', 'מעמד 2.5 ק"ג לטאבלט קופה')->firstOrFail()->localName());
        $this->actingAs($this->agent)->get(route('workspace.agents.packages', ['tab' => 'features']))->assertOk()->assertSee($this->feature('נוכחות עובדים')->localName());
    }

    public function test_agent_builds_quote_with_base_features_and_devices(): void
    {
        $printer = StorageItem::query()->create(['name' => 'מדפסת בונים וחשבון', 'unit_price' => 900, 'stock' => 5, 'track_stock' => true]);

        $quote = $this->quoteFor($this->agent, [
            ['kind' => 'base', 'ref_id' => $this->base()->id],
            ['kind' => 'feature', 'ref_id' => $this->feature('תפריט דיגיטלי')->id],
            ['kind' => 'equipment', 'ref_id' => $printer->id, 'quantity' => 2],
        ]);

        $this->assertSame(SalesQuote::FIRST_NUMBER, $quote->number);
        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertSame($this->agent->id, $quote->agent_id);
        $this->assertSame(479.0, (float) $quote->monthly_total);
        $this->assertSame(1800.0, (float) $quote->one_time_total);
        $this->assertCount(3, $quote->items);
        // Contact and document language come from the client's Finance record.
        $this->assertSame('עלאא חג יחיא', $quote->contact_name);
        $this->assertSame('ar', $quote->lang);
    }

    private function requestClose(SalesQuote $quote): void
    {
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.request', $quote), [
            'deal_note' => 'Standing order signed, devices paid by bank transfer.',
            'files' => [UploadedFile::fake()->image('standing-order.jpg')],
        ])->assertRedirect(route('workspace.agents.sales', ['open' => $quote->id]));
    }

    public function test_close_deal_request_waits_for_finance_then_starts_billing(): void
    {
        Storage::fake('local');
        $nexgo = StorageItem::query()->create(['name' => 'מכשיר סליקה - Nexgo N86', 'unit_price' => 1500, 'stock' => 3, 'track_stock' => true]);
        $quote = $this->quoteFor($this->agent, [
            ['kind' => 'base', 'ref_id' => $this->base()->id],
            ['kind' => 'equipment', 'ref_id' => $nexgo->id, 'quantity' => 1],
        ]);

        $this->requestClose($quote);

        $quote->refresh();
        $this->assertSame(QuoteStatus::Pending, $quote->status);
        $this->assertCount(1, $quote->files);
        Storage::disk('local')->assertExists($quote->files->first()->path);
        // Nothing reaches billing until Finance confirms.
        $this->assertNull($this->client->fresh()->billing_items);
        $this->assertSame(0, FinanceInvoice::query()->count());

        $notice = $this->admin->unreadNotifications()->firstOrFail();
        $this->assertSame(SalesDealRequested::class, $notice->type);
        $this->actingAs($this->admin)->get(route('workspace.finance.deals'))
            ->assertOk()
            ->assertSee('Ponte')
            ->assertSee('Standing order signed')
            ->assertSee(__('finance.clients.method_no'))
            ->assertSee('data-fd-image', false)
            ->assertSee(__('finance.deals.next_billing'))
            ->assertSee('₪354', false)
            ->assertSee('name="next_billing_date"', false)
            ->assertSee(__('finance.deals.notice', ['agent' => $this->agent->name, 'client' => 'Ponte']), false)
            ->assertSee('fin-nav-count', false);
        $this->actingAs($this->admin)->get(route('workspace.finance.notifications.open', $notice))
            ->assertRedirect(route('workspace.finance.deals', ['open' => $quote->id]));
        $this->assertNotNull($notice->fresh()->read_at);
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.request', $quote), ['deal_note' => 'again'])->assertSessionHas('error');
        $this->actingAs($this->admin)->get(route('workspace.agents.sales.file', $quote->files->first()))->assertOk();

        $this->actingAs($this->agent)->post(route('workspace.finance.deals.confirm', $quote))->assertForbidden();
        // Without a date the monthly billing cannot start.
        $this->actingAs($this->admin)->post(route('workspace.finance.deals.confirm', $quote))->assertSessionHasErrors('next_billing_date');
        $this->actingAs($this->admin)
            ->post(route('workspace.finance.deals.confirm', $quote), ['next_billing_date' => '2026-12-15'])
            ->assertRedirect(route('workspace.finance.deals'));
        $this->assertSame('2026-12-15', $this->client->fresh()->next_billing_date->toDateString());

        $quote->refresh();
        $this->assertSame(QuoteStatus::Sold, $quote->status);
        $this->assertSame($this->admin->id, $quote->confirmed_by);
        $this->assertEquals([['name' => 'מנוי בסיס קמאן', 'amount' => 300]], $this->client->fresh()->billing_items);
        $invoice = FinanceInvoice::query()->findOrFail($quote->finance_invoice_id);
        $this->assertSame(InvoiceType::TaxInvoiceReceipt, $invoice->type);
        $this->assertSame(1500.0, (float) $invoice->subtotal);
        $this->assertSame(0.0, (float) $quote->devices_due);
        $this->assertSame(2, $nexgo->fresh()->stock);

        // Confirming twice never bills twice.
        $this->actingAs($this->admin)->post(route('workspace.finance.deals.confirm', $quote))->assertSessionHas('error');
        $this->assertSame(1, FinanceInvoice::query()->count());
    }

    public function test_partly_paid_devices_stay_owed_until_finance_continues_the_payment(): void
    {
        Storage::fake('local');
        $nexgo = StorageItem::query()->create(['name' => 'Nexgo N62', 'unit_price' => 1500, 'stock' => 3, 'track_stock' => true]);
        $quote = $this->quoteFor($this->agent, [['kind' => 'equipment', 'ref_id' => $nexgo->id, 'quantity' => 1]]);
        $this->requestClose($quote);

        // ₪1,500 + 18% VAT = ₪1,770. The client paid ₪1,000, so ₪770 is left.
        $this->actingAs($this->admin)->post(route('workspace.finance.deals.confirm', $quote), [
            'device_payment' => 'partial',
            'devices_left' => 770,
        ])->assertRedirect(route('workspace.finance.deals'));

        $quote->refresh();
        $this->assertSame(QuoteStatus::Sold, $quote->status);
        $this->assertSame(1770.0, (float) $quote->devices_total);
        $this->assertSame(770.0, (float) $quote->devices_due);
        $this->assertSame(2, $nexgo->fresh()->stock);
        $first = FinanceInvoice::query()->findOrFail($quote->finance_invoice_id);
        $this->assertSame(InvoiceType::TaxInvoiceReceipt, $first->type);
        $this->assertSame(1000.0, (float) $first->total);
        $this->assertSame($quote->id, $first->sales_quote_id);
        $this->assertSame(HardwareStatus::Partial, $this->client->fresh()->hardware_status);

        // The balance shows in the Finance client modal and in the restaurant modal of the other doors.
        $this->actingAs($this->admin)->get(route('workspace.finance.clients.show', ['client' => $this->client, 'tab' => 'invoices']))
            ->assertOk()
            ->assertSee(__('finance.devices.continue'))
            ->assertSee('₪770', false);
        $this->assertSame(770.0, app(\App\Services\Finance\RestaurantFinance::class)->byCompany()['c-ponte']['devicesDue']);

        $this->actingAs($this->admin)->post(route('workspace.finance.deals.device-payment', $quote), ['amount' => 1000])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post(route('workspace.finance.deals.device-payment', $quote), ['amount' => 770])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $this->client->id, 'tab' => 'invoices']));

        $this->assertSame(0.0, (float) $quote->fresh()->devices_due);
        $second = FinanceInvoice::query()->where('sales_quote_id', $quote->id)->latest('id')->firstOrFail();
        $this->assertSame(770.0, (float) $second->total);
        $this->assertSame(HardwareStatus::Paid, $this->client->fresh()->hardware_status);
        $this->assertSame(2, $nexgo->fresh()->stock);
    }

    public function test_request_needs_a_note_and_evidence(): void
    {
        $quote = $this->quoteFor($this->agent, [['kind' => 'base', 'ref_id' => $this->base()->id]]);

        $this->actingAs($this->agent)->post(route('workspace.agents.sales.request', $quote), [])
            ->assertSessionHasErrors(['deal_note', 'files']);
        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);
    }

    public function test_finance_can_return_a_request_and_the_agent_can_fix_it(): void
    {
        Storage::fake('local');
        $quote = $this->quoteFor($this->agent, [['kind' => 'base', 'ref_id' => $this->base()->id]]);
        $this->requestClose($quote);

        // Waiting for Finance: the agent cannot change or delete it.
        $this->actingAs($this->agent)->delete(route('workspace.agents.sales.destroy', $quote))->assertSessionHas('error');

        $this->actingAs($this->admin)->post(route('workspace.finance.deals.return', $quote), ['finance_note' => 'The standing order is not signed.'])
            ->assertRedirect(route('workspace.finance.deals'));
        $quote->refresh();
        $this->assertSame(QuoteStatus::Returned, $quote->status);
        $this->assertSame('The standing order is not signed.', $quote->finance_note);
        $this->assertNull($this->client->fresh()->billing_items);

        // The earlier evidence is kept, so a new note is enough to ask again.
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.request', $quote), ['deal_note' => 'Signed now.'])->assertRedirect();
        $this->assertSame(QuoteStatus::Pending, $quote->fresh()->status);
        $this->assertNull($quote->fresh()->finance_note);
    }

    public function test_finance_confirm_without_stock_keeps_the_request_waiting(): void
    {
        Storage::fake('local');
        $empty = StorageItem::query()->create(['name' => 'Nexgo', 'unit_price' => 1500, 'stock' => 0, 'track_stock' => true]);
        $quote = $this->quoteFor($this->agent, [['kind' => 'equipment', 'ref_id' => $empty->id, 'quantity' => 1]]);
        $this->requestClose($quote);

        $this->actingAs($this->admin)->post(route('workspace.finance.deals.confirm', $quote))->assertSessionHasErrors('stock');

        $this->assertSame(QuoteStatus::Pending, $quote->fresh()->status);
        $this->assertSame(0, FinanceInvoice::query()->count());
    }

    public function test_quote_pdf_goes_to_the_client_on_whatsapp(): void
    {
        Http::fake(['https://green.test/*' => Http::response(['idMessage' => 'ok'])]);
        KamanWhatsappLine::query()->create([
            'user_id' => $this->admin->id,
            'name' => 'Kaman POS — WhatsApp',
            'system_prompt' => 'test',
            'stores_members' => false,
            'integration_type' => 'kaman_whatsapp',
            'greenapi_url' => 'https://green.test/waInstance1/sendMessage/token',
            'integration_settings' => ['enabled' => true, 'ignored_reply_phones' => []],
        ]);
        $this->client->update(['phone' => null]);
        $quote = $this->quoteFor($this->agent, [['kind' => 'base', 'ref_id' => $this->base()->id]]);

        $this->actingAs($this->agent)->post(route('workspace.agents.sales.whatsapp', $quote), [
            'phone' => '0501112233',
            'lang' => 'ar',
            'message' => 'مرحبًا',
            'pdf' => UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%quote\n"),
        ], ['Accept' => 'application/json'])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendFileByUpload/') && str_contains($request->body(), '972501112233@c.us'));
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
        $this->assertNotNull($quote->fresh()->whatsapp_sent_at);
        $this->assertSame('0501112233', $this->client->fresh()->phone);
    }

    public function test_agents_only_see_their_own_quotes(): void
    {
        $other = User::factory()->create(['is_admin' => false]);
        $quote = $this->quoteFor($other, [['kind' => 'base', 'ref_id' => $this->base()->id]]);
        $this->flushSession();

        $this->actingAs($this->agent)->get(route('workspace.agents.sales'))->assertOk()->assertDontSee($quote->title());
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.request', $quote), ['deal_note' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('workspace.agents.sales'))->assertOk()->assertSee($quote->title());
    }

    public function test_panel_loads_on_its_own_for_the_owner_only(): void
    {
        $quote = $this->quoteFor($this->agent, [['kind' => 'base', 'ref_id' => $this->base()->id]]);
        $other = User::factory()->create(['is_admin' => false]);

        $this->actingAs($this->agent)->get(route('workspace.agents.sales.panel', $quote))
            ->assertOk()
            ->assertSee('data-sq-panel', false)
            ->assertSee(__('sales.download_pdf'))
            ->assertDontSee('<html', false);
        $this->actingAs($other)->get(route('workspace.agents.sales.panel', $quote))->assertForbidden();
    }

    public function test_document_downloads_in_arabic_hebrew_and_english(): void
    {
        $drawer = StorageItem::query()->where('name', 'מגירת כסף')->firstOrFail();
        $quote = $this->quoteFor($this->agent, [
            ['kind' => 'base', 'ref_id' => $this->base()->id],
            ['kind' => 'equipment', 'ref_id' => $drawer->id, 'quantity' => 1],
        ]);
        $doc = fn (string $lang) => $this->actingAs($this->agent)->get(route('workspace.agents.sales.document', [$quote, 'lang' => $lang, 'pdf' => 1]));

        $doc('ar')->assertOk()->assertSee('dir="rtl"', false)->assertSee('عرض سعر')->assertSee('اشتراك كمان الأساسي')->assertSee('درج نقود')->assertSee('html2pdf', false);
        $doc('he')->assertOk()->assertSee('dir="rtl"', false)->assertSee('הצעת מחיר')->assertSee('מנוי בסיס קמאן');
        $doc('en')->assertOk()->assertSee('dir="ltr"', false)->assertSee('Price quote')->assertSee('Kaman base subscription')->assertSee('Cash drawer')->assertDontSee('מנוי בסיס קמאן')->assertDontSee('מגירת כסף');
        auth()->logout();
        $this->get(route('workspace.agents.sales.document', $quote))->assertRedirect();
    }

    public function test_kaman_agents_devices_are_in_stock(): void
    {
        $this->assertSame(15, StorageItem::query()->where('category', 'hardware')->count());
        $printer = StorageItem::query()->where('name', 'מדפסת בונים וחשבון')->firstOrFail();
        $this->assertSame(900.0, (float) $printer->unit_price);
        $this->assertSame(27, $printer->stock);
        $this->assertSame(27, $printer->movements()->sum('change'));
    }

    private function lead(array $attributes = []): AgentLead
    {
        return AgentLead::query()->create($attributes + ['name' => 'Cafe Lina', 'contact' => 'Lina Haddad', 'phone' => '0529998877', 'agent_id' => $this->agent->id]);
    }

    private function quoteForLead(AgentLead $lead): SalesQuote
    {
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.store'), [
            'agent_lead_id' => $lead->id,
            'lines' => [['kind' => 'base', 'ref_id' => $this->base()->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SalesQuote::query()->latest('id')->firstOrFail();
    }

    private function closeRequest(SalesQuote $quote): TestResponse
    {
        return $this->actingAs($this->agent)->post(route('workspace.agents.sales.request', $quote), [
            'deal_note' => 'Standing order signed.',
            'files' => [UploadedFile::fake()->image('standing-order.jpg')],
        ]);
    }

    public function test_a_quote_needs_a_client_or_a_lead(): void
    {
        $lines = [['kind' => 'base', 'ref_id' => $this->base()->id]];

        // Neither one.
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.store'), ['lines' => $lines])
            ->assertSessionHasErrors(['finance_client_id' => __('sales.errors.no_client')]);
        $this->post(route('workspace.agents.sales.store'), ['finance_client_id' => '', 'agent_lead_id' => '', 'lines' => $lines])
            ->assertSessionHasErrors('finance_client_id');
        // Both, or a lead that does not exist.
        $this->post(route('workspace.agents.sales.store'), ['finance_client_id' => $this->client->id, 'agent_lead_id' => $this->lead()->id, 'lines' => $lines])
            ->assertSessionHasErrors('finance_client_id');
        $this->post(route('workspace.agents.sales.store'), ['agent_lead_id' => 999999, 'lines' => $lines])
            ->assertSessionHasErrors('agent_lead_id');
        $this->assertSame(0, SalesQuote::query()->count());

        // A client on its own saves as before.
        $quote = $this->quoteFor($this->agent, $lines);
        $this->assertSame($this->client->id, $quote->finance_client_id);
        $this->assertNull($quote->agent_lead_id);
    }

    public function test_the_modal_offers_clients_and_leads_only_behind_the_leads_door(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->agent)->get(route('workspace.agents.sales'))
            ->assertOk()
            ->assertSee('name="agent_lead_id"', false)
            ->assertSee(__('sales.pick_lead'))
            ->assertSee($lead->name);

        $closed = User::factory()->create(['is_admin' => false, 'projects' => ['app-development'], 'door_access' => ['agents.leads' => false]]);
        $this->actingAs($closed)->get(route('workspace.agents.sales'))
            ->assertOk()
            ->assertDontSee('name="agent_lead_id"', false)
            ->assertDontSee($lead->name);
        $this->post(route('workspace.agents.sales.store'), [
            'agent_lead_id' => $lead->id,
            'lines' => [['kind' => 'base', 'ref_id' => $this->base()->id]],
        ])->assertSessionHasErrors('agent_lead_id');
        $this->assertSame(0, SalesQuote::query()->count());
    }

    public function test_a_lead_quote_saves_and_shows_wherever_quotes_are_listed(): void
    {
        $lead = $this->lead();
        $quote = $this->quoteForLead($lead);

        $this->assertNull($quote->finance_client_id);
        $this->assertSame($lead->id, $quote->agent_lead_id);
        $this->assertSame('Lina Haddad', $quote->contact_name);
        $this->assertSame('he', $quote->lang);
        $this->assertSame(300.0, (float) $quote->monthly_total);

        $this->actingAs($this->agent)->get(route('workspace.agents.sales'))->assertOk()->assertSee('Cafe Lina')->assertSee(__('sales.lead'));
        $this->get(route('workspace.agents.sales', ['q' => 'Lina']))->assertOk()->assertSee($quote->title());
        $this->get(route('workspace.agents.sales', ['open' => $quote->id]))->assertOk()->assertSee('Cafe Lina');
        $this->get(route('workspace.agents.sales.panel', $quote))
            ->assertOk()
            ->assertSee('Cafe Lina')
            ->assertSee('0529998877')
            ->assertSee('data-sq-convert', false)
            ->assertDontSee('data-sq-request', false);
        $this->get(route('workspace.agents.sales.document', [$quote, 'lang' => 'en']))->assertOk()->assertSee('Cafe Lina')->assertSee('Lina Haddad');

        $this->getJson('/api/mobile/v1/agents/sales')->assertOk()
            ->assertJsonPath('quotes.0.client', null)
            ->assertJsonPath('quotes.0.lead.name', 'Cafe Lina')
            ->assertJsonPath('quotes.0.customer_name', 'Cafe Lina');
        $this->getJson('/api/mobile/v1/agents/sales/'.$quote->id)->assertOk()->assertJsonPath('customer_name', 'Cafe Lina');

        // Editing keeps it on the lead, or moves it onto a client.
        $this->put(route('workspace.agents.sales.update', $quote), ['agent_lead_id' => $lead->id, 'notes' => 'Later', 'lines' => [['kind' => 'base', 'ref_id' => $this->base()->id]]])->assertRedirect();
        $this->assertSame($lead->id, $quote->fresh()->agent_lead_id);
        $this->put(route('workspace.agents.sales.update', $quote), ['finance_client_id' => $this->client->id, 'lines' => [['kind' => 'base', 'ref_id' => $this->base()->id]]])->assertRedirect();
        $this->assertSame($this->client->id, $quote->fresh()->finance_client_id);
        $this->assertNull($quote->fresh()->agent_lead_id);
    }

    public function test_a_lead_quote_can_go_out_on_whatsapp(): void
    {
        Http::fake(['https://green.test/*' => Http::response(['idMessage' => 'ok'])]);
        KamanWhatsappLine::query()->create([
            'user_id' => $this->admin->id,
            'name' => 'Kaman POS — WhatsApp',
            'system_prompt' => 'test',
            'stores_members' => false,
            'integration_type' => 'kaman_whatsapp',
            'greenapi_url' => 'https://green.test/waInstance1/sendMessage/token',
            'integration_settings' => ['enabled' => true, 'ignored_reply_phones' => []],
        ]);
        $quote = $this->quoteForLead($this->lead());

        $this->actingAs($this->agent)->post(route('workspace.agents.sales.whatsapp', $quote), [
            'phone' => '0529998877',
            'lang' => 'he',
            'pdf' => UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%quote\n"),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
    }

    public function test_closing_a_deal_on_an_unconverted_lead_is_refused(): void
    {
        Storage::fake('local');
        // Marked won on the leads page, but nothing links it to a client yet, so Finance has no one to bill.
        foreach ([$this->lead(), $this->lead(['name' => 'Won only', 'stage' => 'won']), $this->lead(['name' => 'Not synced yet', 'restaurant_id' => 'c-unknown'])] as $lead) {
            $quote = $this->quoteForLead($lead);

            $this->closeRequest($quote)->assertSessionHas('error', __('sales.errors.lead_not_converted'));

            $quote->refresh();
            $this->assertSame(QuoteStatus::Draft, $quote->status);
            $this->assertNull($quote->requested_at);
            $this->assertCount(0, $quote->files);
        }
        $this->assertSame(0, $this->admin->unreadNotifications()->count());
    }

    public function test_converting_the_lead_moves_its_quotes_to_the_client_and_opens_the_deal(): void
    {
        Storage::fake('local');
        $lead = $this->lead();
        $quote = $this->quoteForLead($lead);
        $second = $this->quoteForLead($lead);

        $this->actingAs($this->agent)->post(route('workspace.agents.sales.convert', $quote), [])
            ->assertSessionHasErrors(['finance_client_id' => __('sales.errors.no_converted_client')]);
        $other = User::factory()->create(['is_admin' => false]);
        $this->actingAs($other)->post(route('workspace.agents.sales.convert', $quote), ['finance_client_id' => $this->client->id])->assertForbidden();
        $this->assertNull($lead->fresh()->restaurant_id);

        $this->actingAs($this->agent)->post(route('workspace.agents.sales.convert', $quote), ['finance_client_id' => $this->client->id])
            ->assertRedirect(route('workspace.agents.sales', ['open' => $quote->id]))
            ->assertSessionHas('success');

        $lead->refresh();
        $this->assertSame('c-ponte', $lead->restaurant_id);
        $this->assertSame(LeadStage::Won, $lead->stage);
        foreach ([$quote, $second] as $moved) {
            $moved->refresh();
            $this->assertSame($this->client->id, $moved->finance_client_id);
            $this->assertNull($moved->agent_lead_id);
            $this->assertSame('ar', $moved->lang);
        }
        $this->get(route('workspace.agents.sales.panel', $quote))->assertOk()->assertSee('data-sq-request', false)->assertDontSee('data-sq-convert', false);

        $this->closeRequest($quote)->assertRedirect(route('workspace.agents.sales', ['open' => $quote->id]));
        $this->assertSame(QuoteStatus::Pending, $quote->fresh()->status);

        // From here Finance handles it as any other deal.
        $this->actingAs($this->admin)->get(route('workspace.finance.deals'))->assertOk()->assertSee('Ponte');
        $this->actingAs($this->admin)->post(route('workspace.finance.deals.confirm', $quote), ['next_billing_date' => '2026-12-15'])->assertRedirect();
        $this->assertSame(QuoteStatus::Sold, $quote->fresh()->status);
        $this->assertSame(300.0, (float) $this->client->fresh()->monthly_fee);
    }

    public function test_a_converted_lead_closes_without_another_step(): void
    {
        Storage::fake('local');
        // Quoted first, then linked to its client from outside this quote.
        $lead = $this->lead();
        $quote = $this->quoteForLead($lead);
        $lead->update(['restaurant_id' => 'c-ponte']);

        $this->closeRequest($quote)->assertRedirect(route('workspace.agents.sales', ['open' => $quote->id]));

        $quote->refresh();
        $this->assertSame(QuoteStatus::Pending, $quote->status);
        $this->assertSame($this->client->id, $quote->finance_client_id);
        $this->assertNull($quote->agent_lead_id);

        // Quoted after it was linked: it is the client's quote from the start.
        $later = $this->quoteForLead($lead);
        $this->assertSame($this->client->id, $later->finance_client_id);
        $this->assertNull($later->agent_lead_id);
    }

    public function test_a_client_quote_closes_as_it_always_did(): void
    {
        Storage::fake('local');
        $quote = $this->quoteFor($this->agent, [['kind' => 'base', 'ref_id' => $this->base()->id]]);

        $this->closeRequest($quote)->assertRedirect(route('workspace.agents.sales', ['open' => $quote->id]));

        $this->assertSame(QuoteStatus::Pending, $quote->fresh()->status);
        $this->assertSame(1, $this->admin->unreadNotifications()->count());
    }

    public function test_a_lead_with_quotes_cannot_be_deleted(): void
    {
        $lead = $this->lead();
        $quote = $this->quoteForLead($lead);

        $this->actingAs($this->agent)->deleteJson('/workspace/agents/leads/'.$lead->id)->assertUnprocessable();
        $this->assertNotNull($lead->fresh());

        $this->delete(route('workspace.agents.sales.destroy', $quote))->assertRedirect();
        $this->deleteJson('/workspace/agents/leads/'.$lead->id)->assertOk();
        $this->assertNull($lead->fresh());
    }

    public function test_price_list_cannot_be_changed_from_the_agents_door(): void
    {
        $this->actingAs($this->admin)
            ->post('/workspace/agents/packages', ['kind' => 'feature', 'name' => 'Loyalty', 'price' => 70])
            ->assertStatus(405);
        $this->assertFalse(SalesCatalogItem::query()->where('name', 'Loyalty')->exists());
    }
}
