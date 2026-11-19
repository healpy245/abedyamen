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
use App\Models\Sales\SalesQuoteSignature;
use App\Services\Sales\QuoteContract;
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
        // Signatures and deal evidence are kept on the private disk.
        Storage::fake('local');
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

    /**
     * A drawn signature, as the signing page sends it.
     */
    private function signaturePng(): string
    {
        $image = imagecreatetruecolor(320, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagesetthickness($image, 3);
        imageline($image, 20, 90, 300, 30, imagecolorallocate($image, 20, 20, 60));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * The client opens the contract link and signs it.
     */
    private function signContract(SalesQuote $quote, string $name = 'עלאא חג יחיא'): SalesQuoteSignature
    {
        $link = app(QuoteContract::class)->link($quote, '0501234567', $quote->agent ?? $this->agent);
        $this->post(route('contracts.sign', $link->token), [
            'signer_name' => $name,
            'signer_business_number' => '515151515',
            'signer_address' => 'הרצל 5, טייבה',
            'agree' => '1',
            'signature' => $this->signaturePng(),
        ])->assertRedirect(route('contracts.show', $link->token))->assertSessionHasNoErrors();

        return $link->fresh();
    }

    private function signed(SalesQuote $quote): void
    {
        if (! $quote->fresh()->isSigned()) {
            $this->signContract($quote);
        }
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
        $this->signed($quote);
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

        // Not signed yet: Finance does not get it.
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.request', $quote), [
            'deal_note' => 'Standing order signed.',
            'files' => [UploadedFile::fake()->image('standing-order.jpg')],
        ])->assertSessionHas('error', __('sales.errors.not_signed'));
        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);

        $this->signContract($quote);
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.request', $quote), [])
            ->assertSessionHasErrors(['deal_note', 'files']);
        $this->assertSame(QuoteStatus::Signed, $quote->fresh()->status);
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

        // Finance sees which device is short before trying, inside the deal.
        $this->actingAs($this->admin)->get(route('workspace.finance.deals', ['open' => $quote->id]))
            ->assertOk()
            ->assertSee(__('finance.deals.short_title'))
            ->assertSee(__('finance.deals.out_of_stock'))
            ->assertSee(__('finance.deals.short_fix'));

        // A refused confirm comes back to this deal's modal with the reason in it, not only in a toast under it.
        $this->post(route('workspace.finance.deals.confirm', $quote))
            ->assertRedirect(route('workspace.finance.deals', ['open' => $quote->id]))
            ->assertSessionHasErrors('stock');
        $this->get(route('workspace.finance.deals', ['open' => $quote->id]))
            ->assertSee('class="fd-alert" role="alert"', false)
            ->assertSee(__('finance.errors.not_enough_stock', ['item' => 'Nexgo', 'stock' => 0]));

        $this->assertSame(QuoteStatus::Pending, $quote->fresh()->status);
        $this->assertSame(0, FinanceInvoice::query()->count());

        // With stock added, the same deal confirms.
        $empty->update(['stock' => 1]);
        $this->post(route('workspace.finance.deals.confirm', $quote))->assertRedirect(route('workspace.finance.deals'))->assertSessionHasNoErrors();
        $this->assertSame(QuoteStatus::Sold, $quote->fresh()->status);
    }

    private function whatsappLine(): void
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
    }

    public function test_the_contract_link_goes_to_the_client_on_whatsapp(): void
    {
        $this->whatsappLine();
        $this->client->update(['phone' => null]);
        $quote = $this->quoteFor($this->agent, [['kind' => 'base', 'ref_id' => $this->base()->id]]);

        $this->actingAs($this->agent)->post(route('workspace.agents.sales.whatsapp', $quote), [
            'phone' => '0501112233',
            'message' => 'مرحبًا',
        ], ['Accept' => 'application/json'])->assertOk();

        $link = $quote->signature()->sole();
        $this->assertTrue($link->isPending());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage/')
            && $request['chatId'] === '972501112233@c.us'
            && str_starts_with($request['message'], 'مرحبًا')
            && str_contains($request['message'], route('contracts.show', $link->token)));
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
        $this->assertNotNull($quote->fresh()->whatsapp_sent_at);
        $this->assertSame('0501112233', $this->client->fresh()->phone);

        // Sending again before the client signs sends the same link.
        $this->post(route('workspace.agents.sales.whatsapp', $quote), ['phone' => '0501112233'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $quote->signatures()->count());
    }

    public function test_the_client_signs_the_contract_and_the_agent_sends_it_to_finance(): void
    {
        $service = StorageItem::query()->where('name', 'בניית תפריט')->firstOrFail();
        $quote = $this->quoteFor($this->agent, [
            ['kind' => 'base', 'ref_id' => $this->base()->id],
            ['kind' => 'service', 'ref_id' => $service->id],
        ]);
        $link = app(QuoteContract::class)->link($quote, '0501234567', $this->agent);
        auth()->logout();

        // The client reads the whole agreement with the quote in it, no login needed.
        $this->get(route('contracts.show', $link->token))
            ->assertOk()
            ->assertSee('הסכם התקשרות לשירותי אפליקציית KAMAN')
            ->assertSee('אמ.פיי איי טי גרופ בע"מ, ח.פ. 517119558', false)
            ->assertSee('14.3 כותרות הסעיפים בהסכם זה נועדו לנוחות בלבד ולא ישמשו בפרשנותו.')
            ->assertSee('Ponte')
            ->assertSee('בניית תפריט')
            ->assertSeeText('בסך 500 ש"ח')
            ->assertSee('data-ct-form', false);
        $this->get(route('contracts.show', str_repeat('a', 48)))->assertNotFound();

        // An empty pad or no agreement is refused.
        $this->post(route('contracts.sign', $link->token), ['signer_name' => 'Ala', 'agree' => '1', 'signature' => 'data:image/png;base64,AAAA'])->assertSessionHasErrors('signature');
        $this->post(route('contracts.sign', $link->token), ['signer_name' => 'Ala', 'signature' => $this->signaturePng()])->assertSessionHasErrors('agree');
        $this->assertTrue($link->fresh()->isPending());

        $signature = $this->signContract($quote, 'Ala Haj Yahya');
        $this->assertTrue($signature->isSigned());
        Storage::disk('local')->assertExists($signature->signature_path);
        $this->assertSame('Ala Haj Yahya', $signature->snapshot['signed']['name']);
        $this->assertSame(QuoteStatus::Signed, $quote->fresh()->status);
        $this->get(route('contracts.show', $link->token))->assertOk()->assertSee('Ala Haj Yahya')->assertSee('data:image/png;base64,', false)->assertDontSee('data-ct-form', false);
        // Signing twice is refused.
        $this->post(route('contracts.sign', $link->token), ['signer_name' => 'X', 'agree' => '1', 'signature' => $this->signaturePng()])->assertSessionHasErrors('signature');

        // The agent sees it signed, can view the signature, and can now send it to Finance. The lines are held.
        $this->actingAs($this->agent)->get(route('workspace.agents.sales.panel', $quote))
            ->assertOk()
            ->assertSee(__('sales.contract.state_signed'))
            ->assertSee(__('sales.contract.view'))
            ->assertSee(__('sales.contract.resend'))
            ->assertSee('data-sq-request', false)
            ->assertDontSee('data-sq-edit', false)
            ->assertDontSee('data-sq-whatsapp', false);
        $this->get(route('workspace.agents.sales.document', $quote))->assertOk()->assertSee('Ala Haj Yahya')->assertSee('515151515');
        $this->put(route('workspace.agents.sales.update', $quote), ['finance_client_id' => $this->client->id, 'lines' => [['kind' => 'base', 'ref_id' => $this->base()->id]]])
            ->assertSessionHas('error', __('sales.errors.locked'));

        $this->requestClose($quote);
        $this->assertSame(QuoteStatus::Pending, $quote->fresh()->status);

        // Finance sees the signed contract with the deal, and can open it.
        $this->actingAs($this->admin)->get(route('workspace.finance.deals'))
            ->assertOk()
            ->assertSee(__('sales.contract.finance_title'))
            ->assertSee('Ala Haj Yahya')
            ->assertSee(route('workspace.agents.sales.document', $quote), false);
        $this->get(route('workspace.agents.sales.document', $quote))->assertOk()->assertSee('Ala Haj Yahya');

        // Once Finance has it, the signature can no longer be cancelled.
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.signature.cancel', $quote))->assertSessionHasErrors('contract');
        $this->assertTrue($quote->fresh()->isSigned());
    }

    public function test_sending_again_cancels_the_signature(): void
    {
        $quote = $this->quoteFor($this->agent, [['kind' => 'base', 'ref_id' => $this->base()->id]]);
        $first = $this->signContract($quote);

        // A signed contract is not sent again by accident.
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.whatsapp', $quote), ['phone' => '0501112233'], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $other = User::factory()->create(['is_admin' => false]);
        $this->actingAs($other)->post(route('workspace.agents.sales.signature.cancel', $quote))->assertForbidden();

        $this->actingAs($this->agent)->post(route('workspace.agents.sales.signature.cancel', $quote))
            ->assertRedirect(route('workspace.agents.sales', ['open' => $quote->id, 'resend' => 1]));
        $this->assertSame(SalesQuoteSignature::CANCELLED, $first->fresh()->status);
        $this->assertSame($this->agent->id, $first->fresh()->cancelled_by);
        $quote->refresh();
        $this->assertFalse($quote->isSigned());
        $this->assertSame(QuoteStatus::Sent, $quote->status);
        // The page comes back with the send dialog ready, and the quote can be changed again.
        $this->get(route('workspace.agents.sales', ['open' => $quote->id, 'resend' => 1]))->assertOk()->assertSee('data-resend', false);
        $this->put(route('workspace.agents.sales.update', $quote), ['finance_client_id' => $this->client->id, 'lines' => [['kind' => 'base', 'ref_id' => $this->base()->id]]])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');

        // The old link no longer opens the contract; the new one does.
        $this->get(route('contracts.show', $first->token))->assertOk()->assertSee(__('sales.contract.closed_title'))->assertDontSee('data-ct-form', false);
        $this->post(route('contracts.sign', $first->token), ['signer_name' => 'X', 'agree' => '1', 'signature' => $this->signaturePng()])
            ->assertRedirect(route('contracts.show', $first->token));
        $second = $this->signContract($quote);
        $this->assertNotSame($first->token, $second->token);
        $this->assertTrue($quote->fresh()->isSigned());
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

    public function test_the_pdf_is_the_contract_with_the_quote_in_it(): void
    {
        $drawer = StorageItem::query()->where('name', 'מגירת כסף')->firstOrFail();
        $setup = StorageItem::query()->where('name', 'הגדרת מכשירים ורשת LAN')->firstOrFail();
        $quote = $this->quoteFor($this->agent, [
            ['kind' => 'base', 'ref_id' => $this->base()->id],
            ['kind' => 'equipment', 'ref_id' => $drawer->id, 'quantity' => 1],
            ['kind' => 'service', 'ref_id' => $setup->id],
        ]);

        $this->actingAs($this->agent)->get(route('workspace.agents.sales.document', [$quote, 'pdf' => 1]))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('הסכם התקשרות לשירותי אפליקציית KAMAN')
            ->assertSee('הצעת מחיר')
            ->assertSee('מנוי בסיס קמאן')
            ->assertSee('מגירת כסף')
            ->assertSee('הגדרת מכשירים ורשת LAN')
            ->assertSee(__('sales.contract.groups.devices'))
            ->assertSee(__('sales.contract.groups.services'))
            ->assertSee('ולראיה באו הצדדים על החתום:')
            ->assertSee('html2pdf', false)
            ->assertDontSee('data-ct-form', false);
        auth()->logout();
        $this->get(route('workspace.agents.sales.document', $quote))->assertRedirect();
    }

    public function test_services_have_their_own_square_on_the_quote(): void
    {
        $catalog = $this->actingAs($this->agent)->get(route('workspace.agents.sales'))->assertOk()->viewData('catalog');
        $services = collect($catalog['service'])->pluck('price', 'id');
        $id = fn (string $name): int => StorageItem::query()->where('name', $name)->firstOrFail()->id;
        $this->assertSame(500.0, $services[$id('בניית תפריט')]);
        $this->assertSame(200.0, $services[$id('הדרכה מלאה לעובדי הקופה')]);
        $this->assertSame(200.0, $services[$id('הדרכת CRM בזום')]);
        $this->assertSame(500.0, $services[$id('הגדרת מכשירים ורשת LAN')]);
        // Services are not offered as devices.
        $this->assertNotContains($id('בניית תפריט'), array_column($catalog['equipment'], 'id'));

        $menu = StorageItem::query()->where('name', 'בניית תפריט')->firstOrFail();
        $quote = $this->quoteFor($this->agent, [
            ['kind' => 'base', 'ref_id' => $this->base()->id],
            ['kind' => 'service', 'ref_id' => $menu->id, 'quantity' => 3],
        ]);
        $line = $quote->items->firstWhere('kind', 'service');
        $this->assertSame('one_time', $line->billing);
        $this->assertSame(1.0, (float) $line->quantity);
        $this->assertSame(500.0, (float) $quote->one_time_total);
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
        $this->signed($quote);

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
            // A lead's quote is a plain quote: PDF in a language, sent as a file. No convert, signing or Finance.
            ->assertSee('data-wa-mode="pdf"', false)
            ->assertSee('data-sq-pdf-lang="ar"', false)
            ->assertDontSee('data-sq-convert', false)
            ->assertDontSee(__('sales.convert.state_title'))
            ->assertDontSee(__('sales.contract.need_signature'))
            ->assertDontSee('data-sq-request', false);
        // Its PDF is the price quote, not the contract, in the language picked.
        $this->get(route('workspace.agents.sales.document', [$quote, 'lang' => 'en']))->assertOk()
            ->assertSee('Cafe Lina')->assertSee('Price quote')->assertSee('dir="ltr"', false)
            ->assertDontSee('הסכם התקשרות לשירותי אפליקציית KAMAN');

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
        $this->whatsappLine();
        $quote = $this->quoteForLead($this->lead());

        // The quote PDF goes as a file, as quotes always did; no contract link is made.
        $this->actingAs($this->agent)->post(route('workspace.agents.sales.whatsapp', $quote), [
            'phone' => '0529998877',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['pdf', 'lang']);

        $this->post(route('workspace.agents.sales.whatsapp', $quote), [
            'phone' => '0529998877',
            'lang' => 'he',
            'pdf' => UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%quote\n"),
        ], ['Accept' => 'application/json'])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendFileByUpload/') && str_contains($request->body(), '972529998877@c.us'));
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
        $this->assertSame(0, $quote->signatures()->count());
    }

    public function test_closing_a_deal_on_an_unconverted_lead_is_refused(): void
    {
        Storage::fake('local');
        // Marked won on the leads page, but nothing links it to a client yet, so Finance has no one to bill.
        foreach ([$this->lead(), $this->lead(['name' => 'Won only', 'stage' => 'won']), $this->lead(['name' => 'Not synced yet', 'restaurant_id' => 'c-unknown'])] as $lead) {
            $quote = $this->quoteForLead($lead);

            $this->closeRequest($quote)->assertSessionHas('error', __('sales.errors.lead_not_converted'));

            $quote->refresh();
            $this->assertSame(QuoteStatus::Signed, $quote->status);
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
        // Converted, but not signed yet: the agent sends the contract first.
        $this->get(route('workspace.agents.sales.panel', $quote))->assertOk()->assertDontSee('data-sq-request', false)->assertDontSee('data-sq-convert', false)->assertSee(__('sales.contract.need_signature'));
        $this->signContract($quote);
        $this->actingAs($this->agent)->get(route('workspace.agents.sales.panel', $quote))->assertOk()->assertSee('data-sq-request', false);

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
