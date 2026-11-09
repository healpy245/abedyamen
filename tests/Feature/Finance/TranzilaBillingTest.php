<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\PaymentRecordStatus;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Tranzila\CardBrand;
use App\Enums\Tranzila\DocumentAction;
use App\Enums\Tranzila\DocumentStatus;
use App\Enums\Tranzila\TransactionStatus;
use App\Enums\Tranzila\TransactionType;
use App\Models\Finance\FinanceClient;
use App\Models\Finance\FinancePayment;
use App\Models\Finance\TranzilaDocument;
use App\Models\Finance\TranzilaTransaction;
use App\Models\User;
use App\Services\Tranzila\TranzilaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TranzilaBillingTest extends TestCase
{
    use RefreshDatabase;

    private const CHARGE_URL = 'https://api.tranzila.com/v1/transaction/credit_card/create';

    private const DOCUMENT_URL = 'https://billing5.tranzila.com/api/documents_db/create_document';

    private User $admin;

    private FinanceClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tranzila.app_key' => 'app-key',
            'tranzila.secret' => 'top-secret',
            'tranzila.terminal' => 'mfitltd',
            'tranzila.charge_terminal' => 'mfitltdtok',
        ]);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->client = FinanceClient::query()->create(['company_id' => 'company-1', 'name' => 'Asado', 'business_number' => '514000000', 'billing_email' => 'a@example.com']);
        $this->client->forceFill([
            'tranzila_token' => 'O5d55d2922ca4021382',
            'card_brand' => 'visa',
            'card_last4' => '1533',
            'card_expiry' => '07/29',
            'has_payment_method' => true,
        ])->save();
    }

    public function test_auth_headers_are_signed_as_the_docs_describe(): void
    {
        $headers = app(TranzilaClient::class)->authHeaders();

        $this->assertSame('app-key', $headers['X-tranzila-api-app-key']);
        $this->assertSame(80, strlen($headers['X-tranzila-api-nonce']));
        $this->assertSame(
            hash_hmac('sha256', 'app-key', 'top-secret'.$headers['X-tranzila-api-request-time'].$headers['X-tranzila-api-nonce']),
            $headers['X-tranzila-api-access-token'],
        );
    }

    public function test_approved_charge_uses_the_token_and_issues_a_linked_document(): void
    {
        $this->fakeApproved();

        $this->charge()->assertSessionHas('success', __('tranzila.flash.charge_approved'));

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== self::CHARGE_URL) {
                return false;
            }

            return $request['terminal_name'] === 'mfitltdtok'
                && $request['txn_type'] === 'debit'
                && $request['card_number'] === 'O5d55d2922ca4021382'
                && $request['expire_month'] === 7
                && $request['expire_year'] === 2029
                && $request['items'][0]['unit_price'] === 70.25
                && $request['items'][0]['price_type'] === 'G'
                && $request->hasHeader('X-tranzila-api-access-token');
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== self::DOCUMENT_URL) {
                return false;
            }
            $payment = $request['payments'][0];

            return $request['terminal_name'] === 'mfitltd'
                && $request['document_type'] === 'IR'
                && $request['action'] === 1
                && $request['client_id'] === '514000000'
                && $payment['txnindex'] === 12345
                && $payment['amount'] === 70.25
                && $payment['cc_brand'] === CardBrand::Mastercard->value
                && $payment['cc_last_4_digits'] === '1533'
                && ! array_key_exists('canceldoc', $payment);
        });

        $transaction = TranzilaTransaction::query()->sole();
        $this->assertSame(TransactionStatus::Approved, $transaction->status);
        $this->assertSame(12345, (int) $transaction->tranzila_transaction_id);

        $document = TranzilaDocument::query()->sole();
        $this->assertSame(DocumentStatus::Issued, $document->status);
        $this->assertSame('1000010', $document->number);
        $this->assertSame('ASG-1', $document->assignment_number);
        $this->assertSame('retrieve-me', $document->retrieval_key);
        $this->assertSame($transaction->id, $document->transaction_id);
    }

    public function test_the_token_is_stored_encrypted(): void
    {
        $this->fakeApproved();
        $this->charge();

        $this->assertStringNotContainsString('O5d55d2922ca4021382', (string) DB::table('finance_clients')->where('id', $this->client->id)->value('tranzila_token'));
        $this->assertStringNotContainsString('O5d55d2922ca4021382', (string) DB::table('tranzila_transactions')->value('card_token'));
        $this->assertArrayNotHasKey('tranzila_token', $this->client->fresh()->toArray());
    }

    public function test_submitting_the_same_form_twice_charges_once(): void
    {
        $this->fakeApproved();
        $key = (string) Str::uuid();

        $this->charge($key);
        $this->charge($key);

        Http::assertSentCount(2); // One charge and one document.
        $this->assertSame(1, TranzilaTransaction::query()->count());
    }

    public function test_a_lost_answer_is_not_retried_and_blocks_the_next_charge(): void
    {
        $attempts = 0;
        Http::fake([self::CHARGE_URL => function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('timed out');
        }]);

        $this->charge()->assertSessionHas('error', __('tranzila.flash.unknown'));
        $this->assertSame(1, $attempts);

        $this->assertSame(TransactionStatus::Unknown, TranzilaTransaction::query()->sole()->status);
        $this->assertSame(0, TranzilaDocument::query()->count());

        $this->charge()->assertSessionHas('error', __('tranzila.errors.unresolved'));
        $this->assertSame(1, $attempts);
    }

    public function test_a_reads_network_failure_is_retried(): void
    {
        $attempts = 0;
        Http::fake(['https://api.tranzila.com/v1/stos/get' => function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new ConnectionException('timed out');
            }

            return Http::response(['error_code' => 0, 'stos' => []]);
        }]);

        $this->assertTrue(app(TranzilaClient::class)->getStandingOrders(['terminal_name' => 'mfitltdtok'])->ok());
        $this->assertSame(3, $attempts);
    }

    public function test_a_charges_document_is_only_cancelled_through_its_refund(): void
    {
        $this->fakeApproved();
        $this->charge();

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.documents.credit', [$this->client, TranzilaDocument::query()->sole()]))
            ->assertSessionHas('error', __('tranzila.errors.not_creditable'));

        $this->assertSame(1, TranzilaDocument::query()->count());
    }

    public function test_a_failed_document_of_a_refunded_charge_cannot_be_issued_later(): void
    {
        Http::fakeSequence(self::DOCUMENT_URL)->push(['status_code' => 10300, 'status_msg' => 'failed']);
        Http::fake([self::CHARGE_URL => Http::response($this->approvedCharge())]);
        $this->charge();
        $original = TranzilaTransaction::query()->sole();

        $this->actingAs($this->admin)->post(route('workspace.finance.clients.tranzila.refund', [$this->client, $original]));

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.documents.reissue', [$this->client, TranzilaDocument::query()->sole()]))
            ->assertSessionHas('error', __('tranzila.errors.not_reissuable'));
    }

    public function test_a_server_error_counts_as_unknown_rather_than_declined(): void
    {
        Http::fake([self::CHARGE_URL => Http::response('Bad gateway', 502)]);

        $this->charge();

        $this->assertSame(TransactionStatus::Unknown, TranzilaTransaction::query()->sole()->status);
    }

    public function test_declined_charge_needs_both_codes_and_flags_the_month(): void
    {
        Http::fake([self::CHARGE_URL => Http::response([
            'error_code' => 0,
            'message' => 'success',
            'transaction_result' => ['processor_response_code' => '004', 'transaction_id' => 77],
        ])]);

        $this->charge(period: '2026-11');

        $this->assertSame(TransactionStatus::Declined, TranzilaTransaction::query()->sole()->status);
        $this->assertSame(0, TranzilaDocument::query()->count());
        $this->assertSame(PaymentRecordStatus::Failed, FinancePayment::query()->sole()->status);
        $this->assertSame(PaymentStatus::Failed, $this->client->fresh()->payment_status);
    }

    public function test_a_charge_for_a_month_settles_it_and_keeps_paying_when_the_document_fails(): void
    {
        $open = $this->client->payments()->create(['period' => '2026-11-01', 'amount' => 70.25, 'status' => PaymentRecordStatus::Failed]);
        $this->client->forceFill(['payment_status' => PaymentStatus::Failed])->save();

        Http::fake([
            self::CHARGE_URL => Http::response($this->approvedCharge()),
            self::DOCUMENT_URL => Http::response(['status_code' => 10003, 'status_msg' => 'Invalid document date']),
        ]);

        $this->charge(period: '2026-11')->assertSessionHas('error', __('tranzila.flash.charge_document_failed'));

        $this->assertSame(PaymentRecordStatus::Paid, $open->fresh()->status);
        $this->assertSame(PaymentStatus::Active, $this->client->fresh()->payment_status);

        $document = TranzilaDocument::query()->sole();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame(10003, (int) $document->error_code);
    }

    public function test_failed_document_can_be_issued_again_unchanged(): void
    {
        Http::fakeSequence(self::DOCUMENT_URL)
            ->push(['status_code' => 10300, 'status_msg' => 'failed'])
            ->push($this->issuedDocument());
        Http::fake([self::CHARGE_URL => Http::response($this->approvedCharge())]);

        $this->charge();
        $document = TranzilaDocument::query()->sole();
        $this->assertSame(DocumentStatus::Failed, $document->status);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.documents.reissue', [$this->client, $document]))
            ->assertSessionHas('success');

        $this->assertSame(DocumentStatus::Issued, $document->fresh()->status);
    }

    public function test_refund_credits_the_card_and_cancels_the_document(): void
    {
        $this->fakeApproved();
        $this->charge(period: '2026-11');
        $original = TranzilaTransaction::query()->sole();

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.refund', [$this->client, $original]))
            ->assertSessionHas('success', __('tranzila.flash.refund_approved'));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::CHARGE_URL
            && $request['txn_type'] === 'credit'
            && $request['reference_txn_id'] === 12345
            && $request['authorization_number'] === '0587923');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::DOCUMENT_URL
            && $request['action'] === 3
            && $request['related_document_number'] === '1000010'
            && $request['relation_type'] === 1
            && $request['payments'][0]['canceldoc'] === 'Y');

        $refund = TranzilaTransaction::query()->where('type', TransactionType::Credit)->sole();
        $this->assertSame(TransactionStatus::Approved, $refund->status);

        $credit = TranzilaDocument::query()->where('action', DocumentAction::Credit)->sole();
        $this->assertSame($refund->id, $credit->transaction_id);
        $this->assertNotNull($credit->related_document_id);
        $this->assertSame(PaymentRecordStatus::Pending, FinancePayment::query()->sole()->status);

        // The same charge cannot be refunded twice.
        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.refund', [$this->client, $original]))
            ->assertSessionHas('error', __('tranzila.errors.not_refundable'));
    }

    public function test_recording_that_a_lost_charge_went_through_issues_its_document(): void
    {
        Http::fake([self::CHARGE_URL => fn () => throw new ConnectionException('timed out'), self::DOCUMENT_URL => Http::response($this->issuedDocument())]);
        $this->charge();
        $transaction = TranzilaTransaction::query()->sole();

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.resolve', [$this->client, $transaction]), [
                'outcome' => 'went_through',
                'tranzila_transaction_id' => 555,
                'auth_number' => '0001',
            ])
            ->assertSessionHas('success');

        $this->assertSame(TransactionStatus::Approved, $transaction->fresh()->status);
        $this->assertSame(DocumentStatus::Issued, TranzilaDocument::query()->sole()->status);
    }

    public function test_manual_receipt_sends_payments_only(): void
    {
        Http::fake([self::DOCUMENT_URL => Http::response($this->issuedDocument())]);

        $this->actingAs($this->admin)->post(route('workspace.finance.clients.tranzila.documents.store', $this->client), [
            'document_type' => 'RE',
            'document_date' => '2026-11-05',
            'payment_method' => 3,
            'amount' => 200,
            'cheque_number' => '4411',
            'bank' => '12',
        ])->assertSessionHas('success');

        Http::assertSent(fn (Request $request): bool => $request['document_type'] === 'RE'
            && ! isset($request['items'])
            && $request['payments'][0]['payment_method'] === 3
            && $request['payments'][0]['cheque_number'] === '4411');
    }

    public function test_manual_invoice_receipt_with_different_totals_is_stopped_before_sending(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->post(route('workspace.finance.clients.tranzila.documents.store', $this->client), [
            'document_type' => 'IR',
            'document_date' => '2026-11-05',
            'lines' => [['name' => 'POS', 'unit_price' => 100, 'units_number' => 1]],
            'payment_method' => 5,
            'amount' => 90,
        ])->assertSessionHas('error', __('tranzila.errors.totals_differ'));

        Http::assertNothingSent();
    }

    public function test_crediting_a_manual_document_copies_it_and_never_touches_money(): void
    {
        Http::fake([self::DOCUMENT_URL => Http::response($this->issuedDocument())]);
        $this->actingAs($this->admin)->post(route('workspace.finance.clients.tranzila.documents.store', $this->client), [
            'document_type' => 'IN',
            'document_date' => '2026-11-05',
            'lines' => [['name' => 'POS', 'unit_price' => 100, 'units_number' => 1]],
        ]);
        $document = TranzilaDocument::query()->sole();

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.documents.credit', [$this->client, $document]))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $request): bool => $request['action'] === 3
            && $request['document_type'] === 'IN'
            && $request['related_document_number'] === '1000010'
            && $request['relation_type'] === 1);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::CHARGE_URL);
    }

    public function test_standing_order_is_created_and_stopped(): void
    {
        Http::fake([
            'https://api.tranzila.com/v2/sto/create' => Http::response(['error_code' => 0, 'message' => 'Success', 'sto_id' => 189976]),
            'https://api.tranzila.com/v2/sto/update' => Http::response(['error_code' => 0, 'message' => 'Success']),
        ]);

        $this->actingAs($this->admin)->post(route('workspace.finance.clients.tranzila.subscriptions.store', $this->client), [
            'lines' => [['name' => 'POS', 'amount' => 199]],
            'charge_frequency' => 'monthly',
            'charge_dom' => 5,
            'payments_number' => 12,
        ])->assertSessionHas('success');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.tranzila.com/v2/sto/create'
            && $request['charge_frequency'] === 'monthly'
            && $request['sto_payments_number'] === 12
            && $request['card']['token'] === 'O5d55d2922ca4021382'
            && $request['card']['expire_year'] === 2029);

        $subscription = $this->client->tranzilaSubscriptions()->sole();
        $this->assertSame(189976, (int) $subscription->sto_id);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.subscriptions.cancel', [$this->client, $subscription]))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.tranzila.com/v2/sto/update'
            && $request['sto_id'] === 189976
            && $request['sto_status'] === 'inactive');
    }

    public function test_handshake_returns_the_thtk_and_never_the_secret(): void
    {
        Http::fake(['https://api.tranzila.com/v2/handshake/create' => Http::response(['error_code' => 0, 'thtk' => 'one-time'])]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('workspace.finance.clients.tranzila.handshake', $this->client))
            ->assertOk()
            ->assertJson(['thtk' => 'one-time', 'terminal' => 'mfitltdtok', 'amount' => 1]);

        $this->assertStringNotContainsString('top-secret', $response->getContent());
    }

    public function test_a_full_card_number_is_refused_as_a_token(): void
    {
        $this->actingAs($this->admin)->postJson(route('workspace.finance.clients.tranzila.card', $this->client), [
            'token' => '4580458045804580',
            'last4' => '4580',
            'brand' => 'visa',
            'expiry_month' => 12,
            'expiry_year' => 2030,
        ])->assertUnprocessable();

        $this->assertSame('O5d55d2922ca4021382', $this->client->fresh()->tranzila_token);
    }

    public function test_saving_a_card_from_hosted_fields_replaces_the_token(): void
    {
        $this->actingAs($this->admin)->postJson(route('workspace.finance.clients.tranzila.card', $this->client), [
            'token' => 'NewT0ken99',
            'last4' => '4242',
            'brand' => 'visa',
            'expiry_month' => 3,
            'expiry_year' => 2031,
            'holder' => 'A. Holder',
        ])->assertOk();

        $client = $this->client->fresh();
        $this->assertSame('NewT0ken99', $client->tranzila_token);
        $this->assertSame('03/31', $client->card_expiry);
        $this->assertSame('4242', $client->card_last4);
    }

    public function test_a_card_typed_in_by_hand_or_removed_clears_the_token(): void
    {
        $this->actingAs($this->admin)->put(route('workspace.finance.clients.card', $this->client), [
            'card_brand' => 'visa',
            'card_last4' => '1111',
            'card_expiry' => '01/30',
            'card_holder' => 'Someone',
        ]);

        $this->assertNull($this->client->fresh()->tranzila_token);

        $this->client->forceFill(['tranzila_token' => 'AnotherT0ken'])->save();
        $this->actingAs($this->admin)->delete(route('workspace.finance.clients.card.remove', $this->client));

        $this->assertNull($this->client->fresh()->tranzila_token);
    }

    public function test_page_shows_for_admins_and_is_closed_to_others(): void
    {
        $this->actingAs($this->admin)
            ->get(route('workspace.finance.clients.tranzila.show', $this->client))
            ->assertOk()
            ->assertSee('hf.tranzila.com/assets/js/thostedf.js', false)
            ->assertDontSee('top-secret')
            ->assertDontSee('O5d55d2922ca4021382');

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('workspace.finance.clients.tranzila.show', $this->client))
            ->assertForbidden();
    }

    public function test_another_clients_transaction_cannot_be_refunded_through_this_client(): void
    {
        $this->fakeApproved();
        $this->charge();
        $other = FinanceClient::query()->create(['company_id' => 'company-2', 'name' => 'Other']);

        $this->actingAs($this->admin)
            ->post(route('workspace.finance.clients.tranzila.refund', [$other, TranzilaTransaction::query()->sole()]))
            ->assertNotFound();
    }

    public function test_without_credentials_nothing_is_sent(): void
    {
        config(['tranzila.app_key' => null]);
        Http::fake();

        $this->charge();

        Http::assertNothingSent();
        $this->assertSame(TransactionStatus::Declined, TranzilaTransaction::query()->sole()->status);
    }

    private function charge(?string $key = null, ?string $period = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('workspace.finance.clients.tranzila.charge', $this->client), [
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'lines' => [['name' => 'POS', 'amount' => 70.25]],
            'period' => $period,
        ]);
    }

    private function fakeApproved(): void
    {
        Http::fake([
            self::CHARGE_URL => Http::response($this->approvedCharge()),
            self::DOCUMENT_URL => Http::response($this->issuedDocument()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedCharge(): array
    {
        return [
            'error_code' => 0,
            'message' => 'success',
            'transaction_result' => [
                'processor_response_code' => '000',
                'transaction_id' => 12345,
                'auth_number' => '0587923',
                'token' => 'O5d55d2922ca4021382',
                'last_4' => '1533',
                'card_type' => 1,
                'card_type_name' => 'Mastercard',
                'amount' => 70.25,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function issuedDocument(): array
    {
        return [
            'status_code' => 0,
            'status_msg' => 'הצלחה',
            'enquiry_key' => '14451a5f',
            'document' => [
                'id' => '6995',
                'number' => '1000010',
                'assignment_number' => 'ASG-1',
                'total_charge_amount' => 70.25,
                'currency' => 'ILS',
                'created_at' => '2026-11-05 14:12:09',
                'retrieval_key' => 'retrieve-me',
            ],
        ];
    }
}
