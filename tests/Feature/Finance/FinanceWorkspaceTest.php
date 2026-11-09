<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\HardwareStatus;
use App\Enums\Finance\InvoiceType;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentRecordStatus;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\ServiceStatus;
use App\Models\KamanWhatsappLine;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\StorageItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake([
            'https://kaman.rest/api/companies' => Http::response(['data' => ['companies' => [
                ['id' => '01a0e7e3-7f0e-71b6-86a7-dc2bee9eb1cb', 'name' => 'shawermabalev', 'subdomain' => 'shawermabalev', 'full_path' => 'https://shawermabalev.kaman.rest', 'settings' => ['lang' => 'he', 'logo' => 'https://admin.kaman.rest/logo.png']],
                ['id' => '01a0af60-776a-703c-91eb-f10bf1a5b09b', 'name' => 'Asado', 'subdomain' => 'asado', 'full_path' => 'https://asado.kaman.rest', 'settings' => ['lang' => 'ar']],
            ]]]),
            'https://kaman.rest/api/company-areas' => Http::response(['data' => ['areas' => []]]),
            'https://green.test/*' => Http::response(['idMessage' => 'ok']),
        ]);

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_only_admins_open_finance(): void
    {
        $member = User::factory()->create(['is_admin' => false]);

        $this->actingAs($member)->get(route('workspace.finance.dashboard'))->assertForbidden();
        $this->actingAs($member)->get(route('workspace.finance.clients'))->assertForbidden();
    }

    public function test_clients_page_keeps_every_row_and_hides_the_ones_a_filter_rules_out(): void
    {
        $page = $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients', ['q' => 'ASADO']))
            ->assertOk()
            ->assertSee('data-fin-clients-filters', false)
            ->assertSee('data-fin-count>1<', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/data-search="shawermabalev[^>]*\shidden/s', $page);
        $this->assertDoesNotMatchRegularExpression('/data-search="asado[^>]*\shidden/s', $page);

        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients', ['q' => 'nothing-like-this']))
            ->assertOk()
            ->assertSee('data-fin-count>0<', false);
    }

    public function test_clients_page_lists_every_live_restaurant(): void
    {
        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients'))
            ->assertOk()
            ->assertSee('shawermabalev')
            ->assertSee('Asado')
            ->assertSee(__('finance.clients.col_method'));

        $this->assertSame(2, FinanceClient::query()->count());
        $this->assertSame('ar', FinanceClient::query()->where('name', 'Asado')->value('lang'));
        $this->actingAs($this->admin)->get(route('workspace.finance.dashboard'))->assertOk();
    }

    public function test_failed_payment_shows_whatsapp_button_and_sends_in_chosen_language(): void
    {
        $this->makeKamanLine();
        $client = $this->client(['payment_status' => PaymentStatus::Failed, 'phone' => '0546452973']);

        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients'))
            ->assertSee(route('workspace.finance.clients.notify', $client), false);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.notify', $client), ['lang' => 'ar'])
            ->assertSessionHas('success');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'green.test')
            && ($request->data()['chatId'] ?? null) === '972546452973@c.us'
            && str_contains((string) ($request->data()['message'] ?? ''), 'لم تنجح آخر دفعة'));
        $this->assertNotNull($client->fresh()->failed_notice_at);
    }

    public function test_clients_open_the_restaurant_modal_on_its_finance_sections(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients'))
            ->assertOk()
            ->assertSee('data-act="open-finance"', false)
            ->assertSee('id="ag-portal"', false)
            ->assertSee('agents-workspace.js', false);

        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients.show', ['client' => $client, 'embed' => 1, 'tab' => 'invoices']))
            ->assertOk()
            ->assertSee('fcm2--embedded', false)
            ->assertSee('data-ds-panel="invoices"', false)
            ->assertDontSee('data-ds-panel="payments"', false)
            ->assertDontSee('data-fcm-details', false)
            ->assertDontSee('fin-modal-title', false);

        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients.show', ['client' => $client, 'embed' => 1, 'tab' => 'payments']))
            ->assertOk()
            ->assertSee('data-fcm-billing', false)
            ->assertSee('data-fcm-service', false)
            ->assertDontSee('data-ds-panel="invoices"', false);
    }

    public function test_failed_save_reopens_the_modal_with_the_error_inline(): void
    {
        $client = $this->client(['phone' => '0501234567']);

        $this->actingAs($this->admin)
            ->from(route('workspace.finance.clients'))
            ->patch(route('workspace.finance.clients.update', $client), [
                'contact_name' => 'Rami',
                'phone' => 'call me!!',
            ])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'details']));

        $this->actingAs($this->admin)
            ->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['phone' => 'Bad phone']))])
            ->get(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'details']))
            ->assertOk()
            ->assertSee('data-fcm', false)
            ->assertSee('Bad phone')
            ->assertSee('data-fcm-billing', false);

        $this->assertSame('0501234567', $client->fresh()->phone);
    }

    public function test_notice_needs_a_phone(): void
    {
        $this->makeKamanLine();
        $client = $this->client(['payment_status' => PaymentStatus::Failed, 'phone' => null]);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.notify', $client), ['lang' => 'he'])
            ->assertSessionHas('error', __('finance.errors.no_phone'));

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'green.test'));
    }

    public function test_invoice_takes_items_from_storage_with_vat_and_delete_puts_them_back(): void
    {
        $client = $this->client();
        $pos = StorageItem::query()->create(['name' => 'POS', 'category' => 'hardware', 'unit_price' => 1000, 'track_stock' => true, 'stock' => 5, 'low_stock' => 1]);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.invoices.store', $client), [
                'type' => InvoiceType::TaxInvoiceReceipt->value,
                'issued_on' => now()->toDateString(),
                'lines' => [['storage_item_id' => $pos->id, 'quantity' => 2, 'unit_price' => 1000]],
            ])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'invoices']));

        $invoice = FinanceInvoice::query()->firstOrFail();
        $this->assertSame(1, $invoice->number);
        $this->assertEquals(2000, (float) $invoice->subtotal);
        $this->assertEquals(360, (float) $invoice->vat);
        $this->assertEquals(2360, (float) $invoice->total);
        $this->assertSame(3, $pos->fresh()->stock);

        $this->actingAs($this->admin)
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('חשבונית מס קבלה');

        $this->actingAs($this->admin)->delete(route('workspace.finance.invoices.destroy', $invoice))->assertRedirect();

        $this->assertSoftDeleted($invoice);
        $this->assertSame(5, $pos->fresh()->stock);
    }

    public function test_receipt_has_no_vat_and_numbers_run_per_type(): void
    {
        $client = $this->client();
        $setup = StorageItem::query()->create(['name' => 'Setup', 'category' => 'service', 'unit_price' => 450, 'track_stock' => false]);

        foreach ([InvoiceType::Receipt, InvoiceType::Receipt, InvoiceType::Credit] as $type) {
            $this->actingAs($this->admin)->post(route('workspace.finance.invoices.store', $client), [
                'type' => $type->value,
                'issued_on' => now()->toDateString(),
                'lines' => [['storage_item_id' => $setup->id, 'quantity' => 1, 'unit_price' => 450]],
            ]);
        }

        $receipts = FinanceInvoice::query()->where('type', InvoiceType::Receipt)->orderBy('number')->get();
        $this->assertSame([1, 2], $receipts->pluck('number')->all());
        $this->assertEquals(0, (float) $receipts->first()->vat);
        $this->assertEquals(450, (float) $receipts->first()->total);
        $this->assertSame(1, FinanceInvoice::query()->where('type', InvoiceType::Credit)->value('number'));
    }

    public function test_invoice_cannot_sell_more_than_storage_holds(): void
    {
        $client = $this->client();
        $printer = StorageItem::query()->create(['name' => 'Printer', 'category' => 'hardware', 'unit_price' => 600, 'track_stock' => true, 'stock' => 1]);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.invoices.store', $client), [
                'type' => InvoiceType::TaxInvoice->value,
                'issued_on' => now()->toDateString(),
                'lines' => [['storage_item_id' => $printer->id, 'quantity' => 3, 'unit_price' => 600]],
            ])
            ->assertSessionHasErrors('stock');

        $this->assertSame(0, FinanceInvoice::query()->count());
        $this->assertSame(1, $printer->fresh()->stock);
    }

    public function test_recording_a_failed_payment_flags_the_client(): void
    {
        $client = $this->client(['monthly_fee' => 299]);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.payments.store', $client), [
                'period' => now()->format('Y-m'),
                'amount' => 299,
                'status' => 'failed',
            ])
            ->assertRedirect();

        $this->assertSame(PaymentStatus::Failed, $client->fresh()->payment_status);
        $this->assertSame(1, $client->payments()->count());
    }

    public function test_details_save_contact_and_link_to_mfitgroup(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin)
            ->patch(route('workspace.finance.clients.update', $client), [
                'contact_name' => 'Rami Haddad',
                'phone' => '050-1234567',
                'business_number' => '515123456',
                'mfit_client_id' => 139,
            ])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'details']));

        $client->refresh();
        $this->assertSame('Rami Haddad', $client->contact_name);
        $this->assertSame('515123456', $client->business_number);
        $this->assertSame('https://mfit.karmelfiber.com/admin/clients/view/139', $client->mfitUrl());

        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients.show', $client))
            ->assertOk()
            ->assertSee('https://mfit.karmelfiber.com/admin/clients/view/139', false)
            ->assertDontSee('.kaman.rest');
    }

    public function test_other_doors_see_the_finance_sections_read_only(): void
    {
        $client = $this->client(['next_billing_date' => '2026-11-01']);
        $worker = User::factory()->create(['is_admin' => false]);

        $this->actingAs($worker)
            ->get(route('workspace.restaurant.finance', ['client' => $client, 'tab' => 'payments']))
            ->assertOk()
            ->assertSee('fcm2--readonly', false)
            ->assertSee('fcm2-switches', false)
            ->assertDontSee('<form', false)
            ->assertDontSee('name="next_billing_date"', false);

        $this->actingAs($worker)
            ->get(route('workspace.restaurant.finance', ['client' => $client, 'tab' => 'invoices']))
            ->assertOk()
            ->assertDontSee('<form', false)
            ->assertDontSee('data-fcm-builder', false);

        // Changing anything stays in the Finance door.
        $this->actingAs($worker)
            ->patch(route('workspace.finance.clients.billing.state', $client), ['billing' => 'off'])
            ->assertForbidden();
    }

    public function test_monthly_billing_switch_turns_billing_off_and_on_and_the_date_form_keeps_it(): void
    {
        $client = $this->client(['payment_status' => 'failed']);

        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients.show', ['client' => $client, 'embed' => 1, 'tab' => 'payments']))
            ->assertOk()
            ->assertSee(route('workspace.finance.clients.billing.state', $client), false)
            ->assertSee('role="switch" aria-checked="true"', false)
            ->assertDontSee('name="payment_status"', false);

        $this->actingAs($this->admin)
            ->patch(route('workspace.finance.clients.billing.state', $client), ['billing' => 'off'])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'payments']));
        $this->assertSame('inactive', $client->fresh()->payment_status->value);

        // Saving the date leaves the switch as it is.
        $this->actingAs($this->admin)
            ->patch(route('workspace.finance.clients.billing', $client), ['next_billing_date' => '2026-12-01']);
        $this->assertSame('inactive', $client->fresh()->payment_status->value);
        $this->assertSame('2026-12-01', $client->fresh()->next_billing_date->toDateString());

        $this->actingAs($this->admin)
            ->patch(route('workspace.finance.clients.billing.state', $client), ['billing' => 'on']);
        $this->assertSame('active', $client->fresh()->payment_status->value);
    }

    public function test_billing_saves_status_and_date_but_never_features_or_fee(): void
    {
        $client = $this->client(['monthly_fee' => 100, 'billing_items' => [['name' => 'Subscription', 'amount' => 100]]]);

        $this->actingAs($this->admin)
            ->patch(route('workspace.finance.clients.billing', $client), [
                'payment_status' => 'active',
                'has_payment_method' => 1,
                'next_billing_date' => '2026-11-01',
                'items' => [
                    ['name' => 'POS', 'amount' => 199],
                    ['name' => '', 'amount' => ''],
                    ['name' => 'Online ordering', 'amount' => 100.5],
                ],
            ])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'payments']));

        $client->refresh();
        $this->assertEquals(100, (float) $client->monthly_fee);
        $this->assertSame('2026-11-01', $client->next_billing_date->toDateString());
        // A payment method on file comes from the saved card, not from this form.
        $this->assertFalse($client->has_payment_method);
        $this->assertSame(['Subscription'], array_column($client->billing_items, 'name'));
    }

    public function test_card_keeps_only_brand_last_four_expiry_and_holder(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin)
            ->put(route('workspace.finance.clients.card', $client), [
                'card_brand' => 'visa',
                'card_last4' => '4242',
                'card_expiry' => '12/28',
                'card_holder' => 'Yousef Zoabi',
                'card_number' => '4242424242424242',
                'cvv' => '123',
            ])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'payments']));

        $client->refresh();
        $this->assertSame('4242', $client->card_last4);
        $this->assertSame('12/28', $client->card_expiry);
        $this->assertTrue($client->has_payment_method);
        // Nothing beyond the last 4 digits is stored anywhere on the client.
        $this->assertStringNotContainsString('4242424242424242', json_encode($client->getAttributes()));

        $this->actingAs($this->admin)->get(route('workspace.finance.clients.show', ['client' => $client, 'tab' => 'payments']))
            ->assertOk()
            ->assertSee('•••• 4242', false)
            ->assertDontSee('fcm2__summary', false);

        $this->actingAs($this->admin)->put(route('workspace.finance.clients.card', $client), ['card_brand' => 'visa', 'card_last4' => '42', 'card_expiry' => '13/28', 'card_holder' => 'x'])
            ->assertSessionHasErrors(['card_last4', 'card_expiry']);

        $this->actingAs($this->admin)->delete(route('workspace.finance.clients.card.remove', $client))->assertRedirect();
        $this->assertNull($client->fresh()->card_last4);
        $this->assertFalse($client->fresh()->has_payment_method);
    }

    public function test_service_and_hardware_status_switch(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin)
            ->patch(route('workspace.finance.clients.service', $client), ['service_status' => 'inactive', 'tab' => 'payments'])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'payments']));
        $this->assertSame(ServiceStatus::Inactive, $client->fresh()->service_status);

        $this->actingAs($this->admin)
            ->patch(route('workspace.finance.clients.hardware', $client), ['hardware_status' => 'partial'])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'invoices']));
        $this->assertSame(HardwareStatus::Partial, $client->fresh()->hardware_status);
    }

    public function test_paying_a_failed_month_with_proof_clears_the_failure(): void
    {
        Storage::fake('local');
        $client = $this->client(['payment_status' => PaymentStatus::Failed, 'billing_items' => [['name' => 'POS', 'amount' => 299]], 'monthly_fee' => 299]);
        $failed = $client->payments()->create(['period' => '2026-10-01', 'amount' => 299, 'status' => PaymentRecordStatus::Failed]);

        // A paid month needs a payment method.
        $this->actingAs($this->admin)
            ->post(route('workspace.finance.payments.settle', $failed), ['note' => 'x'])
            ->assertSessionHasErrors('method');

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.payments.settle', $failed), [
                'method' => PaymentMethod::Bit->value,
                'reference' => 'BIT-1',
                'note' => 'Owner paid after the reminder',
                'files' => [UploadedFile::fake()->image('receipt.jpg')],
            ])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'payments']));

        $failed->refresh();
        $this->assertSame(PaymentRecordStatus::Paid, $failed->status);
        $this->assertSame(PaymentMethod::Bit, $failed->method);
        $this->assertSame('Owner paid after the reminder', $failed->note);
        $this->assertSame(PaymentStatus::Active, $client->fresh()->payment_status);

        $file = $failed->files()->firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $this->actingAs($this->admin)->get(route('workspace.finance.payments.file', $file))->assertOk();
        $this->actingAs($this->admin)
            ->get(route('workspace.finance.payments.invoice', $failed))
            ->assertOk()
            ->assertSee($failed->displayNumber())
            ->assertSee('Owner paid after the reminder');

        $this->actingAs($this->admin)->delete(route('workspace.finance.payments.destroy', $failed))->assertRedirect();
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_recorded_payment_keeps_method_and_billed_features(): void
    {
        $client = $this->client(['billing_items' => [['name' => 'POS', 'amount' => 199], ['name' => 'Kiosk', 'amount' => 100]], 'monthly_fee' => 299]);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.payments.store', $client), [
                'period' => '2026-10',
                'amount' => 299,
                'status' => 'paid',
                'method' => 'credit_card',
            ])
            ->assertRedirect();

        $payment = $client->payments()->firstOrFail();
        $this->assertSame(PaymentMethod::CreditCard, $payment->method);
        $this->assertSame(['POS', 'Kiosk'], array_column($payment->items, 'name'));
        $this->actingAs($this->admin)->get(route('workspace.finance.payments.invoice', $payment))->assertSee('Kiosk');
    }

    public function test_storage_item_create_and_stock_adjust_are_logged(): void
    {
        $this->actingAs($this->admin)->post(route('workspace.finance.storage.store'), [
            'name' => 'Cash drawer',
            'name_ar' => 'درج نقود',
            'name_en' => 'Cash drawer',
            'category' => 'hardware',
            'unit_price' => 320,
            'track_stock' => 1,
            'stock' => 4,
            'low_stock' => 2,
        ])->assertRedirect(route('workspace.finance.storage'));

        $item = StorageItem::query()->where('name', 'Cash drawer')->firstOrFail();
        $this->assertSame(4, $item->stock);
        $this->assertSame('درج نقود', $item->localName('ar'));

        $this->actingAs($this->admin)->post(route('workspace.finance.storage.adjust', $item), [
            'change' => -3,
            'reason' => 'adjust',
        ])->assertRedirect();

        $this->assertSame(1, $item->fresh()->stock);
        $this->assertSame(2, $item->movements()->count());
        $this->actingAs($this->admin)->get(route('workspace.finance.storage'))->assertOk()->assertSee('Cash drawer');
    }

    public function test_restaurants_with_one_mfit_id_share_one_finance_account(): void
    {
        $holder = $this->client(['name' => 'Grill Center', 'mfit_client_id' => 139, 'billing_items' => [['name' => 'POS', 'amount' => 300]]]);
        $branch = $this->client(['name' => 'Grill North', 'billing_items' => [['name' => 'POS', 'amount' => 200]]]);
        $paid = $branch->payments()->create(['period' => '2026-10-01', 'amount' => 200, 'status' => PaymentRecordStatus::Paid]);

        // Saving the same MFIT id on the branch puts it in the holder's account.
        $this->actingAs($this->admin)
            ->patch(route('workspace.finance.clients.mfit', $branch), ['mfit_client_id' => 139])
            ->assertRedirect(route('workspace.finance.clients', ['open' => $holder->id, 'tab' => 'payments']));

        $branch->refresh();
        $this->assertSame($holder->id, $branch->billing_client_id);
        $this->assertSame($holder->id, $paid->fresh()->finance_client_id);
        $this->assertSame(500.0, $holder->fresh()->accountMonthlyFee());

        // Both restaurants open the same account, and the list shows it once.
        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients.show', ['client' => $branch, 'embed' => 1, 'tab' => 'payments']))
            ->assertOk()
            ->assertSee('Grill Center · POS')
            ->assertSee('Grill North · POS')
            ->assertSee(route('workspace.finance.clients.billing.state', $holder), false);
        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients'))
            ->assertOk()
            ->assertSee('+ Grill North')
            ->assertDontSee('data-id="'.$branch->company_id.'"', false);

        $finance = app(\App\Services\Finance\RestaurantFinance::class)->byCompany();
        $this->assertSame($holder->id, $finance[$branch->company_id]['clientId']);
        $this->assertSame(500.0, $finance[$branch->company_id]['monthly']);

        // Invoices for any restaurant in the account go to the account.
        $invoice = app(\App\Services\Finance\InvoiceService::class)->create($branch, \App\Enums\Finance\InvoiceType::Receipt, now(), [['description' => 'Router', 'quantity' => 1, 'unit_price' => 100]], null, $this->admin);
        $this->assertSame($holder->id, $invoice->finance_client_id);

        $this->actingAs($this->admin)
            ->delete(route('workspace.finance.clients.unlink', [$holder, $branch]))
            ->assertRedirect();
        $this->assertNull($branch->fresh()->billing_client_id);
        $this->assertSame($holder->id, $paid->fresh()->finance_client_id);
    }

    public function test_a_restaurant_can_be_added_to_an_account_from_the_list(): void
    {
        $holder = $this->client(['name' => 'Main']);
        $other = $this->client(['name' => 'Second']);
        $third = $this->client(['name' => 'Third', 'billing_client_id' => null]);
        $third->forceFill(['billing_client_id' => $other->id])->save();

        // Adding a restaurant that holds its own account brings its linked restaurants along.
        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.link', $holder), ['restaurant_id' => $other->id])
            ->assertRedirect();

        $this->assertSame($holder->id, $other->fresh()->billing_client_id);
        $this->assertSame($holder->id, $third->fresh()->billing_client_id);
        $this->assertSame(['Main', 'Second', 'Third'], $holder->fresh()->accountRestaurants()->pluck('name')->all());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function client(array $attributes = []): FinanceClient
    {
        return FinanceClient::query()->create(array_merge([
            'company_id' => (string) fake()->uuid(),
            'name' => 'Test Grill',
            'lang' => 'he',
        ], $attributes));
    }

    private function makeKamanLine(): void
    {
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
}
