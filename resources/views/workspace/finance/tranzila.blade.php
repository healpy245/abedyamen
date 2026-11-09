@extends('workspace.finance.layout')

@php
    use App\Enums\Tranzila\ChargeFrequency;
    use App\Enums\Tranzila\DocumentPaymentMethod;
    use App\Enums\Tranzila\DocumentType;
    use App\Enums\Tranzila\SubscriptionStatus;
    use App\Support\FinanceMoney;

    /** @var \App\Models\Finance\FinanceClient $client */
    $firstLine = $lines[0] ?? ['name' => '', 'amount' => ''];
    $canBill = $configured && $hasToken;
@endphp

@push('styles')
<style>
    .tz { display: grid; gap: 16px; }
    .tz__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
    .tz__head h1 { margin: 0; font-size: 22px; }
    .tz__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: 16px; align-items: start; }
    .tz-card { display: grid; gap: 12px; padding: 16px; }
    .tz-card h2 { margin: 0; font-size: 16px; }
    .tz-form { display: grid; gap: 10px; }
    .tz-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
    .tz-hosted { height: 40px; padding: 0 10px; border: 1px solid var(--ds-border, #d4d4d8); border-radius: 8px; background: var(--ds-surface, #fff); }
    .tz-note { margin: 0; font-size: 13px; opacity: .75; }
    .tz-alert { margin: 0; padding: 10px 12px; border-radius: 8px; background: rgba(217, 119, 6, .12); }
    .tz-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .tz-resolve { display: flex; flex-wrap: wrap; gap: 6px; }
    .tz-resolve .ds-input { width: 110px; }
</style>
@endpush

@section('content')
<div class="tz">
    <header class="tz__head">
        <div>
            <a href="{{ route('workspace.finance.clients', ['open' => $client->id, 'tab' => 'payments']) }}">{{ __('tranzila.back') }}</a>
            <h1>{{ __('tranzila.title', ['name' => $client->name]) }}</h1>
        </div>
    </header>

    @unless($configured)
        <p class="tz-alert" role="alert">{{ __('tranzila.errors.not_configured') }}</p>
    @endunless

    <div class="tz__grid">
        {{-- Card: the number and CVV are typed into Tranzila's own frames and never reach this server. --}}
        <section class="ds-card tz-card">
            <h2>{{ __('tranzila.card.title') }}</h2>
            @if($hasToken)
                <p class="ds-num" dir="ltr">•••• {{ $client->card_last4 }} · {{ $client->card_expiry }}</p>
            @else
                <p class="tz-note">{{ __('tranzila.card.none') }}</p>
            @endif
            @if($configured)
                <form class="tz-form" data-tz-card
                      data-handshake="{{ route('workspace.finance.clients.tranzila.handshake', $client) }}"
                      data-save="{{ route('workspace.finance.clients.tranzila.card', $client) }}"
                      data-sandbox="{{ config('tranzila.hosted_fields.sandbox') ? '1' : '0' }}"
                      data-language="{{ app()->getLocale() === 'he' ? 'Hebrew' : 'English' }}"
                      data-failed="{{ __('tranzila.card.failed') }}">
                    <x-ds.field :label="__('tranzila.card.number')"><div id="tz-number" class="tz-hosted"></div></x-ds.field>
                    <div class="tz-row">
                        <x-ds.field :label="__('tranzila.card.cvv')"><div id="tz-cvv" class="tz-hosted"></div></x-ds.field>
                        <x-ds.field :label="__('tranzila.card.month')" for="tz-month">
                            <select id="tz-month" class="ds-input" data-tz-month required>
                                @foreach(range(1, 12) as $month)
                                    <option value="{{ sprintf('%02d', $month) }}">{{ sprintf('%02d', $month) }}</option>
                                @endforeach
                            </select>
                        </x-ds.field>
                        <x-ds.field :label="__('tranzila.card.year')" for="tz-year">
                            <select id="tz-year" class="ds-input" data-tz-year required>
                                @foreach(range((int) now()->format('Y'), (int) now()->format('Y') + 12) as $year)
                                    <option value="{{ $year }}">{{ $year }}</option>
                                @endforeach
                            </select>
                        </x-ds.field>
                    </div>
                    <div class="tz-row">
                        <x-ds.field :label="__('tranzila.card.holder')" for="tz-holder">
                            <input id="tz-holder" class="ds-input" type="text" maxlength="80" data-tz-holder value="{{ $client->card_holder }}">
                        </x-ds.field>
                        <x-ds.field :label="__('tranzila.card.holder_id')" for="tz-holder-id">
                            <input id="tz-holder-id" class="ds-input ds-num" type="text" inputmode="numeric" maxlength="9" dir="ltr" data-tz-holder-id>
                        </x-ds.field>
                    </div>
                    <p class="tz-note">{{ __('tranzila.card.validation_note') }}</p>
                    <p class="tz-alert" role="alert" data-tz-card-error hidden></p>
                    <button type="submit" class="ds-btn ds-btn--primary">{{ __($hasToken ? 'tranzila.card.replace' : 'tranzila.card.save') }}</button>
                </form>
            @endif
        </section>

        {{-- Charge --}}
        <section class="ds-card tz-card">
            <h2>{{ __('tranzila.charge.title') }}</h2>
            @unless($hasToken)
                <p class="tz-note">{{ __('tranzila.charge.needs_card') }}</p>
            @endunless
            <form class="tz-form" method="post" action="{{ route('workspace.finance.clients.tranzila.charge', $client) }}"
                  data-fin-confirm="{{ __('tranzila.charge.confirm', ['name' => $client->name]) }}"
                  data-fin-confirm-ok="{{ __('tranzila.charge.submit') }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $chargeKey }}">
                @foreach($lines ?: [$firstLine] as $index => $line)
                    <div class="tz-row">
                        <x-ds.field :label="__('tranzila.line.name')" for="tz-charge-name-{{ $index }}">
                            <input id="tz-charge-name-{{ $index }}" class="ds-input" type="text" name="lines[{{ $index }}][name]" maxlength="120" value="{{ $line['name'] }}" required>
                        </x-ds.field>
                        <x-ds.field :label="__('tranzila.line.amount_gross')" for="tz-charge-amount-{{ $index }}">
                            <input id="tz-charge-amount-{{ $index }}" class="ds-input ds-num" type="number" name="lines[{{ $index }}][amount]" min="0.01" step="0.01" dir="ltr" value="{{ $line['amount'] }}" required>
                        </x-ds.field>
                    </div>
                @endforeach
                <x-ds.field :label="__('tranzila.charge.period')" for="tz-charge-period" :hint="__('tranzila.charge.period_hint')">
                    <input id="tz-charge-period" class="ds-input ds-num" type="month" name="period" dir="ltr" value="{{ now()->format('Y-m') }}">
                </x-ds.field>
                <p class="tz-note">{{ __('tranzila.charge.document_note') }}</p>
                <button type="submit" class="ds-btn ds-btn--primary" @disabled(! $canBill)>{{ __('tranzila.charge.submit') }}</button>
            </form>
        </section>

        {{-- Manual document --}}
        <section class="ds-card tz-card">
            <h2>{{ __('tranzila.document.title') }}</h2>
            <form class="tz-form" method="post" action="{{ route('workspace.finance.clients.tranzila.documents.store', $client) }}"
                  data-fin-confirm="{{ __('tranzila.document.confirm') }}" data-fin-confirm-ok="{{ __('tranzila.document.submit') }}">
                @csrf
                <div class="tz-row">
                    <x-ds.field :label="__('tranzila.document.type')" for="tz-doc-type" error="document_type">
                        <select id="tz-doc-type" class="ds-input" name="document_type">
                            @foreach([DocumentType::TaxInvoice, DocumentType::Receipt, DocumentType::DealInvoice, DocumentType::TaxInvoiceReceipt] as $type)
                                <option value="{{ $type->value }}" @selected(old('document_type') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </x-ds.field>
                    <x-ds.field :label="__('tranzila.document.date')" for="tz-doc-date" error="document_date">
                        <input id="tz-doc-date" class="ds-input ds-num" type="date" name="document_date" dir="ltr" value="{{ old('document_date', now()->toDateString()) }}" required>
                    </x-ds.field>
                </div>
                <div class="tz-row">
                    <x-ds.field :label="__('tranzila.line.name')" for="tz-doc-name" :hint="__('tranzila.document.items_hint')" error="lines">
                        <input id="tz-doc-name" class="ds-input" type="text" maxlength="120" data-tz-doc-name value="{{ old('lines.0.name') }}">
                    </x-ds.field>
                    <x-ds.field :label="__('tranzila.line.unit_price')" for="tz-doc-price">
                        <input id="tz-doc-price" class="ds-input ds-num" type="number" min="0.01" step="0.01" dir="ltr" data-tz-doc-price value="{{ old('lines.0.unit_price') }}">
                    </x-ds.field>
                    <x-ds.field :label="__('tranzila.line.units')" for="tz-doc-units">
                        <input id="tz-doc-units" class="ds-input ds-num" type="number" min="0.01" step="0.01" dir="ltr" data-tz-doc-units value="{{ old('lines.0.units_number', 1) }}">
                    </x-ds.field>
                </div>
                <div class="tz-row">
                    <x-ds.field :label="__('tranzila.document.method')" for="tz-doc-method" :hint="__('tranzila.document.payments_hint')" error="payment_method">
                        <select id="tz-doc-method" class="ds-input" name="payment_method">
                            <option value="">—</option>
                            @foreach(DocumentPaymentMethod::manual() as $method)
                                <option value="{{ $method->value }}" @selected((string) old('payment_method') === (string) $method->value)>{{ $method->label() }}</option>
                            @endforeach
                        </select>
                    </x-ds.field>
                    <x-ds.field :label="__('tranzila.document.amount')" for="tz-doc-amount" error="amount">
                        <input id="tz-doc-amount" class="ds-input ds-num" type="number" name="amount" min="0.01" step="0.01" dir="ltr" value="{{ old('amount') }}">
                    </x-ds.field>
                </div>
                <div class="tz-row">
                    <x-ds.field :label="__('tranzila.document.bank')" for="tz-doc-bank"><input id="tz-doc-bank" class="ds-input ds-num" type="text" name="bank" maxlength="40" dir="ltr" value="{{ old('bank') }}"></x-ds.field>
                    <x-ds.field :label="__('tranzila.document.branch')" for="tz-doc-branch"><input id="tz-doc-branch" class="ds-input ds-num" type="text" name="bank_branch" maxlength="40" dir="ltr" value="{{ old('bank_branch') }}"></x-ds.field>
                    <x-ds.field :label="__('tranzila.document.account')" for="tz-doc-account"><input id="tz-doc-account" class="ds-input ds-num" type="text" name="bank_account" maxlength="40" dir="ltr" value="{{ old('bank_account') }}"></x-ds.field>
                    <x-ds.field :label="__('tranzila.document.cheque')" for="tz-doc-cheque"><input id="tz-doc-cheque" class="ds-input ds-num" type="text" name="cheque_number" maxlength="40" dir="ltr" value="{{ old('cheque_number') }}"></x-ds.field>
                </div>
                <button type="submit" class="ds-btn ds-btn--secondary" @disabled(! $configured)>{{ __('tranzila.document.submit') }}</button>
            </form>
        </section>

        {{-- Standing order --}}
        <section class="ds-card tz-card">
            <h2>{{ __('tranzila.subscription.title') }}</h2>
            <p class="tz-note">{{ __('tranzila.subscription.note') }}</p>
            <form class="tz-form" method="post" action="{{ route('workspace.finance.clients.tranzila.subscriptions.store', $client) }}"
                  data-fin-confirm="{{ __('tranzila.subscription.confirm', ['name' => $client->name]) }}" data-fin-confirm-ok="{{ __('tranzila.subscription.submit') }}">
                @csrf
                @foreach($lines ?: [$firstLine] as $index => $line)
                    <div class="tz-row">
                        <x-ds.field :label="__('tranzila.line.name')" for="tz-sto-name-{{ $index }}">
                            <input id="tz-sto-name-{{ $index }}" class="ds-input" type="text" name="lines[{{ $index }}][name]" maxlength="120" value="{{ $line['name'] }}" required>
                        </x-ds.field>
                        <x-ds.field :label="__('tranzila.line.amount_gross')" for="tz-sto-amount-{{ $index }}">
                            <input id="tz-sto-amount-{{ $index }}" class="ds-input ds-num" type="number" name="lines[{{ $index }}][amount]" min="0.01" step="0.01" dir="ltr" value="{{ $line['amount'] }}" required>
                        </x-ds.field>
                    </div>
                @endforeach
                <div class="tz-row">
                    <x-ds.field :label="__('tranzila.subscription.frequency')" for="tz-sto-frequency">
                        <select id="tz-sto-frequency" class="ds-input" name="charge_frequency">
                            @foreach(ChargeFrequency::cases() as $frequency)
                                <option value="{{ $frequency->value }}" @selected($frequency === ChargeFrequency::Monthly)>{{ $frequency->label() }}</option>
                            @endforeach
                        </select>
                    </x-ds.field>
                    <x-ds.field :label="__('tranzila.subscription.day')" for="tz-sto-day">
                        <input id="tz-sto-day" class="ds-input ds-num" type="number" name="charge_dom" min="1" max="28" dir="ltr" value="1" required>
                    </x-ds.field>
                    <x-ds.field :label="__('tranzila.subscription.payments')" for="tz-sto-payments">
                        <input id="tz-sto-payments" class="ds-input ds-num" type="number" name="payments_number" min="1" max="1000" dir="ltr" value="12" required>
                    </x-ds.field>
                    <x-ds.field :label="__('tranzila.subscription.first')" for="tz-sto-first">
                        <input id="tz-sto-first" class="ds-input ds-num" type="date" name="first_charge_date" dir="ltr">
                    </x-ds.field>
                </div>
                <button type="submit" class="ds-btn ds-btn--secondary" @disabled(! $canBill)>{{ __('tranzila.subscription.submit') }}</button>
            </form>
        </section>

        {{-- Email printed on documents --}}
        <section class="ds-card tz-card">
            <h2>{{ __('tranzila.email.title') }}</h2>
            <form class="tz-form" method="post" action="{{ route('workspace.finance.clients.tranzila.email', $client) }}">
                @csrf
                @method('PATCH')
                <x-ds.field :label="__('tranzila.email.label')" for="tz-email" error="billing_email">
                    <input id="tz-email" class="ds-input" type="email" name="billing_email" maxlength="255" dir="ltr" value="{{ old('billing_email', $client->billing_email) }}">
                </x-ds.field>
                <button type="submit" class="ds-btn ds-btn--secondary">{{ __('tranzila.email.save') }}</button>
            </form>
        </section>
    </div>

    {{-- Transactions --}}
    <section class="ds-card tz-card">
        <h2>{{ __('tranzila.transactions.title') }}</h2>
        <div class="ds-table-wrap">
            <table class="ds-table">
                <thead><tr>
                    <th>{{ __('tranzila.transactions.date') }}</th>
                    <th>{{ __('tranzila.transactions.type') }}</th>
                    <th class="is-num">{{ __('tranzila.transactions.amount') }}</th>
                    <th>{{ __('tranzila.transactions.card') }}</th>
                    <th>{{ __('tranzila.transactions.reference') }}</th>
                    <th>{{ __('tranzila.transactions.status') }}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                @forelse($transactions as $transaction)
                    <tr>
                        <td class="ds-num" dir="ltr">{{ $transaction->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $transaction->type->label() }}</td>
                        <td class="is-num">{{ FinanceMoney::format((float) $transaction->amount) }}</td>
                        <td class="ds-num" dir="ltr">{{ $transaction->card_last4 ? '•••• '.$transaction->card_last4 : '—' }}</td>
                        <td class="ds-num" dir="ltr">{{ $transaction->tranzila_transaction_id ?? '—' }}</td>
                        <td>
                            <x-ds.badge soft :tone="$transaction->status->tone()">{{ $transaction->status->label() }}</x-ds.badge>
                            @if($transaction->error_message)<small dir="auto">{{ $transaction->error_message }} @if($transaction->error_code)({{ $transaction->error_code }})@endif</small>@endif
                        </td>
                        <td>
                            <div class="tz-actions">
                                @if($transaction->isRefundable())
                                    <form method="post" action="{{ route('workspace.finance.clients.tranzila.refund', [$client, $transaction]) }}"
                                          data-fin-confirm="{{ __('tranzila.transactions.refund_confirm', ['amount' => FinanceMoney::format((float) $transaction->amount)]) }}"
                                          data-fin-confirm-ok="{{ __('tranzila.transactions.refund') }}">
                                        @csrf
                                        <button type="submit" class="ds-btn ds-btn--ghost ds-btn--sm">{{ __('tranzila.transactions.refund') }}</button>
                                    </form>
                                @endif
                                @if($transaction->status->isUnresolved())
                                    <form class="tz-resolve" method="post" action="{{ route('workspace.finance.clients.tranzila.resolve', [$client, $transaction]) }}">
                                        @csrf
                                        <input class="ds-input ds-input--sm ds-num" type="number" name="tranzila_transaction_id" min="1" dir="ltr" placeholder="{{ __('tranzila.transactions.reference') }}" aria-label="{{ __('tranzila.transactions.reference') }}">
                                        <input class="ds-input ds-input--sm ds-num" type="text" name="auth_number" maxlength="32" dir="ltr" placeholder="{{ __('tranzila.transactions.auth') }}" aria-label="{{ __('tranzila.transactions.auth') }}">
                                        <button type="submit" class="ds-btn ds-btn--ghost ds-btn--sm" name="outcome" value="went_through">{{ __('tranzila.transactions.went_through') }}</button>
                                        <button type="submit" class="ds-btn ds-btn--ghost ds-btn--sm" name="outcome" value="not_charged">{{ __('tranzila.transactions.not_charged') }}</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('tranzila.transactions.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Documents --}}
    <section class="ds-card tz-card">
        <h2>{{ __('tranzila.documents.title') }}</h2>
        <div class="ds-table-wrap">
            <table class="ds-table">
                <thead><tr>
                    <th>{{ __('tranzila.documents.date') }}</th>
                    <th>{{ __('tranzila.documents.type') }}</th>
                    <th>{{ __('tranzila.documents.number') }}</th>
                    <th>{{ __('tranzila.documents.assignment') }}</th>
                    <th class="is-num">{{ __('tranzila.documents.total') }}</th>
                    <th>{{ __('tranzila.documents.status') }}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td class="ds-num" dir="ltr">{{ $document->document_date?->format('d/m/Y') }}</td>
                        <td>
                            {{ $document->document_type->label() }}
                            @if($document->action === \App\Enums\Tranzila\DocumentAction::Credit)
                                · {{ $document->action->label() }}
                                @if($document->relatedDocument?->number)<small dir="ltr">→ {{ $document->relatedDocument->number }}</small>@endif
                            @endif
                        </td>
                        <td class="ds-num" dir="ltr">{{ $document->number ?? '—' }}</td>
                        <td class="ds-num" dir="ltr">{{ $document->assignment_number ?? '—' }}</td>
                        <td class="is-num">{{ FinanceMoney::format((float) $document->total) }}</td>
                        <td>
                            <x-ds.badge soft :tone="$document->status->tone()">{{ $document->status->label() }}</x-ds.badge>
                            @if($document->error_message)<small dir="auto">{{ $document->error_message }} @if($document->error_code)({{ $document->error_code }})@endif</small>@endif
                        </td>
                        <td>
                            <div class="tz-actions">
                                @if($documentUrl($document))
                                    <a class="ds-btn ds-btn--ghost ds-btn--sm" href="{{ $documentUrl($document) }}" target="_blank" rel="noopener">{{ __('tranzila.documents.open') }}</a>
                                @endif
                                @if($document->isCreditable())
                                    <form method="post" action="{{ route('workspace.finance.clients.tranzila.documents.credit', [$client, $document]) }}"
                                          data-fin-confirm="{{ __('tranzila.documents.credit_confirm', ['number' => (string) $document->number]) }}"
                                          data-fin-confirm-ok="{{ __('tranzila.documents.credit') }}">
                                        @csrf
                                        <button type="submit" class="ds-btn ds-btn--ghost ds-btn--sm">{{ __('tranzila.documents.credit') }}</button>
                                    </form>
                                @endif
                                @if($document->canBeReissued())
                                    <form method="post" action="{{ route('workspace.finance.clients.tranzila.documents.reissue', [$client, $document]) }}"
                                          data-fin-confirm="{{ __($document->status === \App\Enums\Tranzila\DocumentStatus::Unknown ? 'tranzila.documents.reissue_unknown_confirm' : 'tranzila.documents.reissue_confirm') }}"
                                          data-fin-confirm-ok="{{ __('tranzila.documents.reissue') }}">
                                        @csrf
                                        <button type="submit" class="ds-btn ds-btn--ghost ds-btn--sm">{{ __('tranzila.documents.reissue') }}</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('tranzila.documents.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Standing orders --}}
    @if($subscriptions->isNotEmpty())
    <section class="ds-card tz-card">
        <h2>{{ __('tranzila.subscriptions.title') }}</h2>
        <div class="ds-table-wrap">
            <table class="ds-table">
                <thead><tr>
                    <th>{{ __('tranzila.subscription.frequency') }}</th>
                    <th class="is-num">{{ __('tranzila.transactions.amount') }}</th>
                    <th>{{ __('tranzila.subscription.day') }}</th>
                    <th>{{ __('tranzila.subscription.payments') }}</th>
                    <th>{{ __('tranzila.transactions.reference') }}</th>
                    <th>{{ __('tranzila.transactions.status') }}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                @foreach($subscriptions as $subscription)
                    <tr>
                        <td>{{ $subscription->charge_frequency->label() }}</td>
                        <td class="is-num">{{ FinanceMoney::format((float) $subscription->amount) }}</td>
                        <td class="ds-num">{{ $subscription->charge_dom }}</td>
                        <td class="ds-num">{{ $subscription->payments_number }}</td>
                        <td class="ds-num" dir="ltr">{{ $subscription->sto_id ?? '—' }}</td>
                        <td>
                            <x-ds.badge soft :tone="$subscription->status->tone()">{{ $subscription->status->label() }}</x-ds.badge>
                            @if($subscription->error_message)<small dir="auto">{{ $subscription->error_message }}</small>@endif
                        </td>
                        <td>
                            @if($subscription->status === SubscriptionStatus::Active)
                                <form method="post" action="{{ route('workspace.finance.clients.tranzila.subscriptions.cancel', [$client, $subscription]) }}"
                                      data-fin-confirm="{{ __('tranzila.subscriptions.cancel_confirm') }}" data-fin-confirm-ok="{{ __('tranzila.subscriptions.cancel') }}">
                                    @csrf
                                    <button type="submit" class="ds-btn ds-btn--ghost ds-btn--sm">{{ __('tranzila.subscriptions.cancel') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif
</div>
@endsection

@push('scripts')
@if($configured)
<script src="{{ config('tranzila.hosted_fields.script') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-tz-card]');
    if (!form || typeof TzlaHostedFields === 'undefined') { return; }

    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var errorBox = form.querySelector('[data-tz-card-error]');
    var button = form.querySelector('button[type="submit"]');
    var fields = TzlaHostedFields.create({
        sandbox: form.dataset.sandbox === '1',
        fields: {
            credit_card_number: { selector: '#tz-number', placeholder: '0000 0000 0000 0000', tabindex: 1 },
            cvv: { selector: '#tz-cvv', placeholder: '123', tabindex: 2 }
        }
    });

    function fail(message) {
        errorBox.textContent = message || form.dataset.failed;
        errorBox.hidden = false;
        button.disabled = false;
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) { throw new Error(data.message || form.dataset.failed); }
                return data;
            });
        });
    }

    // Tranzila's card brands, as Hosted Fields numbers them. Anything else is kept as a plain card.
    var brands = { '1': 'mastercard', '2': 'visa', '3': 'diners', '4': 'amex', '5': 'isracard' };

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        errorBox.hidden = true;
        button.disabled = true;

        var month = form.querySelector('[data-tz-month]').value;
        var year = form.querySelector('[data-tz-year]').value;
        var holder = form.querySelector('[data-tz-holder]').value;
        var holderId = form.querySelector('[data-tz-holder-id]').value;

        postJson(form.dataset.handshake).then(function (handshake) {
            var request = {
                terminal_name: handshake.terminal,
                amount: handshake.amount,
                thtk: handshake.thtk,
                tran_mode: 'N',
                tokenize: true,
                currency_code: 'ILS',
                response_language: form.dataset.language,
                expiry_month: month,
                expiry_year: year
            };
            if (holderId) { request.card_holder_id_number = holderId; }

            fields.charge(request, function (err, response) {
                var result = response && response.transaction_response;
                if (err || !result || !result.success || !result.token) {
                    var problems = err && (err.messages || err.errors);
                    return fail((problems && problems[0] && problems[0].message) || (result && result.error));
                }
                postJson(form.dataset.save, {
                    token: result.token,
                    last4: result.credit_card_last_4_digits,
                    brand: brands[String(result.card_type)] || 'card',
                    expiry_month: Number(month),
                    expiry_year: Number(year),
                    holder: holder
                }).then(function () { window.location.reload(); }).catch(function (error) { fail(error.message); });
            });
        }).catch(function (error) { fail(error.message); });
    });
});
</script>
@endif
<script>
// The manual document form sends its one item line only when it is filled in: a receipt has none.
document.addEventListener('submit', function (event) {
    var form = event.target;
    var name = form.querySelector && form.querySelector('[data-tz-doc-name]');
    if (!name || !name.value.trim()) { return; }
    [['name', name], ['unit_price', form.querySelector('[data-tz-doc-price]')], ['units_number', form.querySelector('[data-tz-doc-units]')]].forEach(function (pair) {
        pair[1].name = 'lines[0][' + pair[0] + ']';
    });
}, true);
</script>
@endpush
