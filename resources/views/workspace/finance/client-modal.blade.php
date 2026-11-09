@php
    use App\Enums\Finance\HardwareStatus;
    use App\Enums\Finance\InvoiceType;
    use App\Enums\Finance\PaymentRecordStatus;
    use App\Enums\Finance\PaymentStatus;
    use App\Enums\Finance\ServiceStatus;
    use App\Models\Finance\FinanceClient;
    use App\Support\FinanceMoney;

    /** @var FinanceClient $client */
    $tab = $tab ?? 'details';
    // Embedded: one section (payments or invoices) inside the restaurant modal, which has its own header and tabs.
    $embedded = $embedded ?? false;
    // Read-only: the same sections in the other doors' restaurant modal, with nothing to change or open.
    $readonly = $readonly ?? false;
    $formTag = $readonly ? 'div' : 'form';
    $shows = fn (string $panel): bool => ! $embedded || $tab === $panel;
    $options = fn (string $enum): array => collect($enum::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all();
    $methods = collect($paymentMethods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all();
    $formId = 'fcm-details-'.$client->id;
    $billId = 'fcm-billing-'.$client->id;
    $docId = 'fcm-doc-'.$client->id;
    $builderOpen = $errors->hasAny(['lines', 'lines.*', 'type', 'issued_on', 'stock']);
    $serviceOn = $client->service_status === ServiceStatus::Active;
    // Monthly billing is on unless it was turned off; a failed charge shows in the history, not here.
    $billingOn = $client->payment_status !== PaymentStatus::Inactive;
    // The whole account's lines: each restaurant's features when several share one MFITGROUP account.
    $billingLines = old('items', $client->accountBillingLines());
    $accountRestaurants = $client->accountRestaurants();
    $linkable = $linkable ?? collect();
    // A failed payment form reopens its own dialog with what was typed.
    $payErrors = $errors->hasAny(['period', 'amount', 'status', 'method', 'paid_on', 'reference', 'note', 'files', 'files.*']);
    $payMode = old('_pay_mode', 'record');
    $payTarget = old('_pay_target');
@endphp
<div class="fcm2 @if($embedded) fcm2--embedded @endif @if($readonly) fcm2--readonly @endif" data-fcm data-ds-tabs-scope data-builder="{{ $builderOpen ? 'open' : 'closed' }}">
    @unless($embedded)
    <header class="ds-dialog__head fcm2__head">
        <x-ds.avatar :src="$client->logo" :name="$client->name" size="lg" />
        <div class="fcm2__title">
            <h2 id="fin-modal-title">{{ $client->name }}</h2>
            <div class="fcm2__meta">
                @if($client->mfitUrl())
                    <a href="{{ $client->mfitUrl() }}" target="_blank" rel="noopener" class="fcm2__mfit">
                        {{ __('finance.modal.open_mfit') }}
                        <x-ds.icon name="external" />
                    </a>
                @else
                    <span class="fcm2__unlinked">{{ __('finance.modal.not_linked') }}</span>
                @endif
                @if($client->payment_status === PaymentStatus::Failed)
                    <x-ds.badge soft tone="red">{{ $client->payment_status->label() }}</x-ds.badge>
                @endif
            </div>
        </div>
        <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-close aria-label="{{ __('finance.modal.close') }}">
            <x-ds.icon name="x" />
        </button>
    </header>
    @endunless


    @unless($embedded)
    <div class="fcm2__tabs">
        <div class="ds-tabs ds-tabs--full" role="tablist" data-ds-tabs aria-label="{{ $client->name }}">
            <button type="button" class="ds-tab" role="tab" data-tab="details" aria-selected="{{ $tab === 'details' ? 'true' : 'false' }}">{{ __('finance.modal.details') }}</button>
            <button type="button" class="ds-tab" role="tab" data-tab="payments" aria-selected="{{ $tab === 'payments' ? 'true' : 'false' }}">
                {{ __('finance.modal.payments') }}
                <span class="ds-tab__count">{{ $client->payments->count() }}</span>
            </button>
            <button type="button" class="ds-tab" role="tab" data-tab="invoices" aria-selected="{{ $tab === 'invoices' ? 'true' : 'false' }}">
                {{ __('finance.modal.invoices') }}
                <span class="ds-tab__count">{{ $client->invoices->count() }}</span>
            </button>
        </div>
    </div>

    @endunless

    <div class="ds-dialog__body fcm2__body">
        {{-- Details: contact and business identity only. --}}
        @unless($embedded)
        <section class="fcm2__panel" data-ds-panel="details" role="tabpanel" @if($tab !== 'details') hidden @endif>
            <form id="{{ $formId }}" method="post" action="{{ route('workspace.finance.clients.update', $client) }}" class="fcm2__form" data-fcm-details>
                @csrf
                @method('PATCH')
                <div class="fcm2__grid">
                    <x-ds.field :label="__('finance.modal.contact_name')" for="{{ $formId }}-contact" error="contact_name">
                        <input id="{{ $formId }}-contact" class="ds-input @error('contact_name') is-invalid @enderror" type="text" name="contact_name" maxlength="120" value="{{ old('contact_name', $client->contact_name) }}" autocomplete="off">
                    </x-ds.field>
                    <x-ds.field :label="__('finance.modal.phone')" for="{{ $formId }}-phone" error="phone">
                        <input id="{{ $formId }}-phone" class="ds-input @error('phone') is-invalid @enderror" type="tel" name="phone" value="{{ old('phone', $client->phone) }}" dir="ltr" placeholder="05X-XXXXXXX">
                    </x-ds.field>
                    <x-ds.field :label="__('finance.modal.business_number')" for="{{ $formId }}-bn" error="business_number">
                        <input id="{{ $formId }}-bn" class="ds-input ds-num @error('business_number') is-invalid @enderror" type="text" name="business_number" inputmode="numeric" maxlength="20" value="{{ old('business_number', $client->business_number) }}" dir="ltr" lang="he">
                    </x-ds.field>
                    <x-ds.field :label="__('finance.modal.mfit_id')" for="{{ $formId }}-mfit" error="mfit_client_id">
                        <input id="{{ $formId }}-mfit" class="ds-input ds-num @error('mfit_client_id') is-invalid @enderror" type="number" name="mfit_client_id" min="1" step="1" value="{{ old('mfit_client_id', $client->mfit_client_id) }}" dir="ltr" placeholder="139">
                    </x-ds.field>
                </div>
            </form>
        </section>
        @endunless

        {{-- Monthly payments: what is billed, the card, and the history. --}}
        @if($shows('payments'))
        <section class="fcm2__panel" data-ds-panel="payments" role="tabpanel" @if($tab !== 'payments') hidden @endif>
            {{-- The two switches, side by side. Turning either off always asks first. --}}
            <div class="fcm2-switches">
                <{{ $formTag }} method="post"
                      action="{{ route('workspace.finance.clients.service', $client) }}"
                      class="fcm2-switch {{ $serviceOn ? 'is-on' : '' }}"
                      @if($serviceOn && ! $readonly)
                          data-fin-confirm="{{ __('finance.modal.confirm_suspend', ['name' => $client->name]) }}"
                          data-fin-confirm-ok="{{ __('finance.modal.suspend') }}"
                      @endif
                      data-fcm-service>
                    @unless($readonly)
                        @csrf
                        @method('PATCH')
                    @endunless
                    <input type="hidden" name="service_status" value="{{ $serviceOn ? ServiceStatus::Inactive->value : ServiceStatus::Active->value }}">
                    <input type="hidden" name="tab" value="payments">
                    <span class="fcm2-switch__text">
                        <small>{{ __('finance.modal.service') }}</small>
                        <b>{{ __($serviceOn ? 'finance.modal.service_on' : 'finance.modal.service_off') }}</b>
                    </span>
                    <button type="submit" class="fcm2-toggle" role="switch" aria-checked="{{ $serviceOn ? 'true' : 'false' }}"
                            aria-label="{{ __($serviceOn ? 'finance.modal.service_stop' : 'finance.modal.service_start') }}"
                            data-tip="{{ __($serviceOn ? 'finance.modal.service_stop' : 'finance.modal.service_start') }}" @disabled($readonly)><i></i></button>
                </{{ $formTag }}>
                <{{ $formTag }} method="post"
                      action="{{ route('workspace.finance.clients.billing.state', $client) }}"
                      class="fcm2-switch {{ $billingOn ? 'is-on' : '' }}"
                      @if($billingOn && ! $readonly)
                          data-fin-confirm="{{ __('finance.modal.confirm_billing_off', ['name' => $client->name]) }}"
                          data-fin-confirm-ok="{{ __('finance.modal.billing_stop') }}"
                      @endif>
                    @unless($readonly)
                        @csrf
                        @method('PATCH')
                    @endunless
                    <input type="hidden" name="billing" value="{{ $billingOn ? 'off' : 'on' }}">
                    <span class="fcm2-switch__text">
                        <small>{{ __('finance.modal.billing') }}</small>
                        <b>{{ __($billingOn ? 'finance.modal.billing_on' : 'finance.modal.billing_off') }}</b>
                    </span>
                    <button type="submit" class="fcm2-toggle" role="switch" aria-checked="{{ $billingOn ? 'true' : 'false' }}"
                            aria-label="{{ __($billingOn ? 'finance.modal.billing_stop' : 'finance.modal.billing_start') }}"
                            data-tip="{{ __($billingOn ? 'finance.modal.billing_stop' : 'finance.modal.billing_start') }}" @disabled($readonly)><i></i></button>
                </{{ $formTag }}>
            </div>

            {{-- One row: the monthly payment with what it is made of and when it is next billed, and the card beside it. --}}
            <div class="fcm2-billrow">
            <{{ $formTag }} id="{{ $billId }}" method="post" action="{{ route('workspace.finance.clients.billing', $client) }}" class="fcm2-month" data-fcm-billing>
                @unless($readonly)
                    @csrf
                    @method('PATCH')
                @endunless
                <div class="fcm2-month__fee">
                    <span>{{ __('finance.modal.monthly_fee') }}</span>
                    <b class="ds-num">{{ FinanceMoney::format(collect($billingLines)->sum(fn ($l) => (float) ($l['amount'] ?? 0))) }}</b>
                </div>
                @if(count($billingLines) > 0)
                    <ul class="fcm2-features__list" role="list">
                        @foreach($billingLines as $line)
                            <li class="fcm2-features__item">
                                <span dir="auto">{{ $line['name'] ?? '' }}</span>
                                <b class="ds-num">{{ FinanceMoney::format((float) ($line['amount'] ?? 0)) }}</b>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($readonly)
                    <div class="fcm2-month__next fcm2-month__when">
                        <span>{{ __('finance.modal.next_billing') }}</span>
                        <b>{{ $client->next_billing_date?->translatedFormat('j F Y') ?? '—' }}</b>
                    </div>
                @else
                    <x-ds.field :label="__('finance.modal.next_billing')" for="{{ $billId }}-next" error="next_billing_date" class="fcm2-month__next">
                        <input id="{{ $billId }}-next" class="ds-input" type="date" name="next_billing_date" value="{{ old('next_billing_date', $client->next_billing_date?->toDateString()) }}">
                    </x-ds.field>
                @endif
            </{{ $formTag }}>

            {{-- Visa card: only brand, last 4 digits, expiry and holder are saved; the number never leaves the browser. --}}
            <div class="fcm2-cardbox">
                <p class="fcm2-cardbox__title"><x-ds.icon name="card" />{{ __('finance.card.title') }}</p>
                @unless($readonly)
                    <a class="ds-btn ds-btn--secondary ds-btn--sm" href="{{ route('workspace.finance.clients.tranzila.show', $client) }}">{{ __('tranzila.open') }}</a>
                @endunless
                @if($client->card_last4)
                    <div class="fcm2-plastic">
                        <span class="fcm2-card-chip" data-brand="{{ $client->card_brand }}">{{ __('finance.card.brands.'.$client->card_brand) }}</span>
                        <span class="fcm2-plastic__num ds-num" dir="ltr">•••• •••• •••• {{ $client->card_last4 }}</span>
                        <span class="fcm2-plastic__foot">
                            <span class="fcm2-cardbox__holder" dir="auto">{{ $client->card_holder }}</span>
                            <span class="fcm2-cardbox__meta ds-num" dir="ltr">{{ $client->card_expiry }}</span>
                        </span>
                    </div>
                    @unless($readonly)
                    <div class="fcm2-cardbox__saved">
                        <button type="button" class="ds-btn ds-btn--ghost ds-btn--sm" data-fcm-card-edit>{{ __('finance.card.replace') }}</button>
                        <form method="post" action="{{ route('workspace.finance.clients.card.remove', $client) }}" data-fin-confirm="{{ __('finance.card.remove_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm ds-btn--danger" aria-label="{{ __('finance.card.remove') }}" data-tip="{{ __('finance.card.remove') }}"><x-ds.icon name="trash" /></button>
                        </form>
                    </div>
                    @endunless
                @elseif($readonly)
                    <p class="fcm2-features__empty">{{ __('finance.card.none') }}</p>
                @endif
                @unless($readonly)
                <form method="post" action="{{ route('workspace.finance.clients.card', $client) }}" class="fcm2-cardform" data-fcm-card-form @if($client->card_last4 && ! $errors->hasAny(['card_last4', 'card_expiry', 'card_holder', 'card_brand'])) hidden @endif>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="card_brand" value="card" data-card-brand>
                    <input type="hidden" name="card_last4" value="" data-card-last4>
                    <div class="fcm2-cardform__number">
                        {{-- No name attribute: the full number is only checked here and never submitted. --}}
                        <input class="ds-input ds-num" type="text" inputmode="numeric" autocomplete="cc-number" maxlength="23" dir="ltr" placeholder="0000 0000 0000 0000" aria-label="{{ __('finance.card.number') }}" data-card-number required>
                        <span class="fcm2-card-chip" data-card-brand-chip hidden></span>
                    </div>
                    <input class="ds-input ds-num" type="text" name="card_expiry" inputmode="numeric" autocomplete="cc-exp" maxlength="5" dir="ltr" placeholder="MM/YY" aria-label="{{ __('finance.card.expiry') }}" value="{{ old('card_expiry') }}" data-card-expiry required>
                    <input class="ds-input" type="text" name="card_holder" autocomplete="cc-name" maxlength="80" placeholder="{{ __('finance.card.holder') }}" aria-label="{{ __('finance.card.holder') }}" value="{{ old('card_holder', $client->card_holder) }}" required>
                    <button type="submit" class="ds-btn ds-btn--secondary">{{ __('finance.card.save') }}</button>
                    <p class="fcm2__alert fcm2-cardform__error" role="alert" data-card-error @unless($errors->hasAny(['card_last4', 'card_expiry', 'card_holder', 'card_brand'])) hidden @endunless
                       data-invalid="{{ __('finance.card.invalid_number') }}">{{ $errors->first('card_last4') ?: $errors->first('card_expiry') ?: $errors->first('card_holder') }}</p>
                </form>
                @endunless
            </div>
            </div>

            {{-- The MFITGROUP account: its id and the restaurants billed under it. --}}
            <div class="fcm2-account">
                <div class="fcm2-account__head">
                    <p class="fcm2-cardbox__title"><x-ds.icon name="users" />{{ __('finance.account.title') }}</p>
                    @if($client->mfitUrl())
                        <a href="{{ $client->mfitUrl() }}" target="_blank" rel="noopener" class="fcm2__mfit">{{ __('finance.modal.open_mfit') }} <x-ds.icon name="external" /></a>
                    @endif
                </div>
                @if($readonly)
                    <p class="fcm2-month__when"><span>{{ __('finance.modal.mfit_id') }}</span><b class="ds-num" dir="ltr">{{ $client->mfit_client_id ?: '—' }}</b></p>
                @else
                    <form method="post" action="{{ route('workspace.finance.clients.mfit', $client) }}" class="fcm2-account__mfit">
                        @csrf
                        @method('PATCH')
                        <x-ds.field :label="__('finance.modal.mfit_id')" for="fcm-mfit-{{ $client->id }}" error="mfit_client_id" :hint="__('finance.account.mfit_hint')">
                            <input id="fcm-mfit-{{ $client->id }}" class="ds-input ds-num" type="number" name="mfit_client_id" min="1" step="1" value="{{ old('mfit_client_id', $client->mfit_client_id) }}" dir="ltr" placeholder="139">
                        </x-ds.field>
                        <button type="submit" class="ds-btn ds-btn--secondary">{{ __('finance.modal.save') }}</button>
                    </form>
                @endif

                <ul class="fcm2-account__list" role="list">
                    @foreach($accountRestaurants as $restaurant)
                        <li>
                            <x-ds.avatar :src="$restaurant->logo" :name="$restaurant->name" size="sm" />
                            <span class="fcm2-account__name">
                                <strong>{{ $restaurant->name }}</strong>
                                @if($restaurant->subdomain)<small dir="ltr">{{ $restaurant->subdomain }}.kaman.rest</small>@endif
                            </span>
                            @if($loop->first)
                                <span class="fcm2-account__tag">{{ __('finance.account.holder') }}</span>
                            @elseif(! $readonly)
                                <form method="post" action="{{ route('workspace.finance.clients.unlink', [$client, $restaurant]) }}"
                                      data-fin-confirm="{{ __('finance.account.unlink_confirm', ['name' => $restaurant->name]) }}"
                                      data-fin-confirm-ok="{{ __('finance.account.unlink') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ds-btn ds-btn--ghost ds-btn--sm">{{ __('finance.account.unlink') }}</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if(! $readonly && $linkable->isNotEmpty())
                    <form method="post" action="{{ route('workspace.finance.clients.link', $client) }}" class="fcm2-account__add"
                          data-fin-confirm="{{ __('finance.account.link_confirm') }}"
                          data-fin-confirm-ok="{{ __('finance.account.link') }}" data-fin-confirm-tone="primary">
                        @csrf
                        <select name="restaurant_id" data-ds-select required aria-label="{{ __('finance.account.pick') }}">
                            <option value="">{{ __('finance.account.pick') }}</option>
                            @foreach($linkable as $other)
                                <option value="{{ $other->id }}">{{ $other->name }}@if($other->subdomain) · {{ $other->subdomain }}@endif</option>
                            @endforeach
                        </select>
                        <button type="submit" class="ds-btn ds-btn--secondary"><x-ds.icon name="plus" />{{ __('finance.account.link') }}</button>
                    </form>
                @endif
            </div>

            <p class="ds-section-title fcm2__history-title">{{ __('finance.modal.history') }}</p>
            @if($client->payments->isEmpty())
                <p class="fcm2-features__empty">{{ __('finance.modal.no_payments') }}</p>
            @else
                <ul class="fcm2__list" role="list">
                    @foreach($client->payments as $payment)
                        <li class="fcm2__row fcm2__row--pay {{ $payment->status === PaymentRecordStatus::Failed ? 'is-failed' : '' }}">
                            <span class="fcm2__row-main">{{ $payment->period->translatedFormat('F Y') }}</span>
                            <x-ds.badge plain :tone="$payment->status->badgeTone()">{{ $payment->status->label() }}</x-ds.badge>
                            <span class="fcm2__row-meta">
                                {{ $payment->method?->label() }}@if($payment->method && $payment->paid_on) · @endif{{ $payment->paid_on?->format('d.m.Y') }}
                                @if($payment->files->isNotEmpty())
                                    <span class="fcm2__clip"><x-ds.icon name="paperclip" />{{ $payment->files->count() }}</span>
                                @endif
                            </span>
                            <b class="ds-num">{{ FinanceMoney::format($payment->amount) }}</b>
                            @unless($readonly)
                            <span class="fcm2__row-action">
                                @if($payment->isOpen())
                                    <button type="button" class="ds-btn ds-btn--primary ds-btn--sm"
                                            data-fcm-pay-open="settle"
                                            data-action="{{ route('workspace.finance.payments.settle', $payment) }}"
                                            data-target="{{ $payment->id }}"
                                            data-title="{{ __('finance.modal.pay_title', ['period' => $payment->period->translatedFormat('F Y')]) }}"
                                            data-amount="{{ FinanceMoney::format($payment->amount) }}">
                                        <x-ds.icon name="card" />
                                        {{ __('finance.modal.pay') }}
                                    </button>
                                @endif
                                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm" data-fcm-detail-open="{{ $payment->id }}" aria-label="{{ __('finance.modal.view_details') }}" data-tip="{{ __('finance.modal.view_details') }}">
                                    <x-ds.icon name="eye" />
                                </button>
                                <a class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm" href="{{ route('workspace.finance.payments.invoice', $payment) }}" target="_blank" rel="noopener" aria-label="{{ __('finance.modal.invoice_pdf') }}" data-tip="{{ __('finance.modal.invoice_pdf') }}">
                                    <x-ds.icon name="file" />
                                </a>
                                <form method="post" action="{{ route('workspace.finance.payments.destroy', $payment) }}" data-fin-confirm="{{ __('finance.modal.delete_payment') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm ds-btn--danger" aria-label="{{ __('finance.modal.delete') }}" data-tip="{{ __('finance.modal.delete') }}">
                                        <x-ds.icon name="trash" />
                                    </button>
                                </form>
                            </span>
                            @endunless
                            <template data-fcm-detail="{{ $payment->id }}">
                                <h3 class="fcm2-sub__title">{{ $payment->period->translatedFormat('F Y') }} · <span class="ds-num">{{ FinanceMoney::format($payment->amount) }}</span></h3>
                                <dl class="fcm2-facts">
                                    <div><dt>{{ __('finance.modal.status') }}</dt><dd><x-ds.badge plain :tone="$payment->status->badgeTone()">{{ $payment->status->label() }}</x-ds.badge></dd></div>
                                    <div><dt>{{ __('finance.modal.method') }}</dt><dd>{{ $payment->method?->label() ?? '—' }}</dd></div>
                                    <div><dt>{{ __('finance.modal.paid_on') }}</dt><dd>{{ $payment->paid_on?->format('d.m.Y') ?? '—' }}</dd></div>
                                    <div><dt>{{ __('finance.modal.reference') }}</dt><dd dir="auto">{{ $payment->reference ?: '—' }}</dd></div>
                                </dl>
                                @if($payment->note)
                                    <p class="fcm2-sub__label">{{ __('finance.modal.note') }}</p>
                                    <p class="fcm2-note" dir="auto">{{ $payment->note }}</p>
                                @endif
                                @if($payment->files->isNotEmpty())
                                    <p class="fcm2-sub__label">{{ __('finance.modal.attachments') }}</p>
                                    <div class="fcm2-files">
                                        @foreach($payment->files as $file)
                                            <a href="{{ route('workspace.finance.payments.file', $file) }}" target="_blank" rel="noopener" class="fcm2-file {{ $file->isImage() ? 'is-image' : '' }}" title="{{ $file->original_name }}">
                                                @if($file->isImage())
                                                    <img src="{{ route('workspace.finance.payments.file', $file) }}" alt="{{ $file->original_name }}" loading="lazy">
                                                @else
                                                    <x-ds.icon name="file" />
                                                    <span>{{ $file->original_name }}</span>
                                                @endif
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                                @if(! $payment->note && $payment->files->isEmpty())
                                    <p class="fcm2-sub__empty">{{ __('finance.modal.no_details') }}</p>
                                @endif
                            </template>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        @endif

        {{-- Hardware payments & invoices --}}
        @if($shows('invoices'))
        <section class="fcm2__panel" data-ds-panel="invoices" role="tabpanel" @if($tab !== 'invoices') hidden @endif>
            @if($client->devicesOwed->isNotEmpty())
                <div class="fcm2-owed">
                    @if($errors->has('amount'))
                        <p class="fcm2__alert" role="alert">{{ $errors->first('amount') }}</p>
                    @endif
                    @foreach($client->devicesOwed as $owed)
                        <div class="fcm2-owed__row">
                            <span class="fcm2-owed__text">
                                <strong>{{ __('finance.devices.owed_title', ['number' => $owed->number, 'left' => FinanceMoney::format($owed->devices_due)]) }}</strong>
                                <small>{{ __('finance.devices.owed_sub', ['total' => FinanceMoney::format($owed->devices_total), 'paid' => FinanceMoney::format((float) $owed->devices_total - (float) $owed->devices_due)]) }}</small>
                            </span>
                            @unless($readonly)
                            <form method="post" action="{{ route('workspace.finance.deals.device-payment', $owed) }}" class="fcm2-owed__form"
                                  data-fin-confirm="{{ __('finance.devices.continue_confirm', ['number' => $owed->number]) }}"
                                  data-fin-confirm-ok="{{ __('finance.devices.continue') }}" data-fin-confirm-tone="primary">
                                @csrf
                                <div class="ds-affix">
                                    <span class="ds-affix__lead">₪</span>
                                    <input class="ds-input ds-num" type="number" name="amount" min="0.01" max="{{ (float) $owed->devices_due }}" step="0.01" value="{{ (float) $owed->devices_due }}" required dir="ltr" aria-label="{{ __('finance.devices.amount') }}">
                                </div>
                                <button type="submit" class="ds-btn ds-btn--primary ds-btn--sm"><x-ds.icon name="check" />{{ __('finance.devices.continue') }}</button>
                            </form>
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif

            @if($readonly)
                <div class="fcm2-card fcm2-card--row fcm2-month__when">
                    <span>{{ __('finance.modal.hardware_status') }}</span>
                    <b>{{ $client->hardware_status->label() }}</b>
                </div>
            @else
            <form method="post" action="{{ route('workspace.finance.clients.hardware', $client) }}" class="fcm2-card fcm2-card--row">
                @csrf
                @method('PATCH')
                <x-ds.field :label="__('finance.modal.hardware_status')" error="hardware_status">
                    <x-ds.select name="hardware_status" :options="$options(HardwareStatus::class)" :value="old('hardware_status', $client->hardware_status->value)" :label="__('finance.modal.hardware_status')" />
                </x-ds.field>
                <button type="submit" class="ds-btn ds-btn--secondary ds-btn--sm">{{ __('finance.modal.save_status') }}</button>
            </form>
            @endif

            @if($errors->hasAny(['lines', 'lines.*', 'type', 'issued_on', 'stock']))
                <p class="fcm2__alert" role="alert">{{ $errors->first('stock') ?: $errors->first('lines') ?: $errors->first() }}</p>
            @endif

            @unless($readonly)
            <form id="{{ $docId }}"
                  method="post"
                  action="{{ route('workspace.finance.invoices.store', $client) }}"
                  class="fcm2__builder"
                  data-fcm-builder
                  data-vat="{{ $vatRate }}">
                @csrf
                @if($storageItems->isEmpty())
                    <x-ds.empty :title="__('finance.modal.open_storage')" icon="receipt">
                        <a class="ds-btn ds-btn--secondary ds-btn--sm" href="{{ route('workspace.finance.storage') }}">{{ __('finance.nav.storage') }}</a>
                    </x-ds.empty>
                @else
                    <div class="fcm2__types" role="radiogroup" aria-label="{{ __('finance.modal.type') }}">
                        @foreach($invoiceTypes as $type)
                            <label>
                                <input type="radio" name="type" value="{{ $type->value }}" data-has-vat="{{ $type->hasVat() ? 1 : 0 }}" @checked(old('type', InvoiceType::TaxInvoiceReceipt->value) === $type->value)>
                                <span lang="he">{{ $type->label() }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="fcm2__grid">
                        <x-ds.field :label="__('finance.modal.issued_on')" for="{{ $docId }}-date" error="issued_on">
                            <input id="{{ $docId }}-date" class="ds-input" type="date" name="issued_on" value="{{ old('issued_on', now()->toDateString()) }}" required>
                        </x-ds.field>
                        <x-ds.field :label="__('finance.modal.notes')" for="{{ $docId }}-notes">
                            <input id="{{ $docId }}-notes" class="ds-input" type="text" name="notes" maxlength="2000" value="{{ old('notes') }}">
                        </x-ds.field>
                    </div>

                    <div class="fcm2__lines" data-fcm-lines>
                        <div class="fcm2__lines-head" aria-hidden="true">
                            <span>{{ __('finance.modal.item') }}</span>
                            <span>{{ __('finance.modal.qty') }}</span>
                            <span>{{ __('finance.modal.price') }}</span>
                            <span class="is-num">{{ __('finance.modal.total') }}</span>
                            <span></span>
                        </div>
                        <template data-fcm-line-template>
                            <div class="fcm2__line" data-fcm-line>
                                <select name="lines[__i__][storage_item_id]" data-fcm-item data-ds-select required aria-label="{{ __('finance.modal.item') }}">
                                    <option value="">{{ __('finance.modal.pick_item') }}</option>
                                    @foreach($storageItems->groupBy(fn ($item) => $item->category->label()) as $group => $items)
                                        <optgroup label="{{ $group }}">
                                            @foreach($items as $item)
                                                <option value="{{ $item->id }}" data-price="{{ (float) $item->unit_price }}" data-stock="{{ $item->track_stock ? $item->stock : '' }}">{{ $item->name }}@if($item->track_stock) · {{ __('finance.modal.in_stock', ['count' => $item->stock]) }}@endif</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                <input class="ds-input ds-num" type="number" name="lines[__i__][quantity]" value="1" min="0.01" step="0.01" data-fcm-qty required aria-label="{{ __('finance.modal.qty') }}" dir="ltr">
                                <div class="ds-affix">
                                    <span class="ds-affix__lead">₪</span>
                                    <input class="ds-input ds-num" type="number" name="lines[__i__][unit_price]" min="0" step="0.01" data-fcm-price required aria-label="{{ __('finance.modal.price') }}" dir="ltr">
                                </div>
                                <b class="ds-num is-num" data-fcm-line-total>₪0</b>
                                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm" data-fcm-remove aria-label="{{ __('finance.modal.delete') }}">
                                    <x-ds.icon name="x" />
                                </button>
                            </div>
                        </template>
                    </div>
                    <button type="button" class="ds-btn ds-btn--ghost ds-btn--sm fcm2__add" data-fcm-add>
                        <x-ds.icon name="plus" />
                        {{ __('finance.modal.add_line') }}
                    </button>

                    <dl class="fcm2__totals">
                        <div><dt>{{ __('finance.modal.subtotal') }}</dt><dd class="ds-num" data-fcm-subtotal>₪0</dd></div>
                        <div data-fcm-vat-row><dt>{{ __('finance.modal.vat', ['rate' => rtrim(rtrim(number_format($vatRate, 2), '0'), '.')]) }}</dt><dd class="ds-num" data-fcm-vat>₪0</dd></div>
                        <div class="is-total"><dt>{{ __('finance.modal.total') }}</dt><dd class="ds-num" data-fcm-total>₪0</dd></div>
                    </dl>
                @endif
            </form>
            @endunless

            <div class="fcm2__docs">
                @if($client->invoices->isEmpty())
                    <x-ds.empty :title="__('finance.modal.no_invoices')" icon="receipt">{{ __('finance.modal.no_invoices_hint') }}</x-ds.empty>
                @else
                    <ul class="fcm2__list" role="list">
                        @foreach($client->invoices as $invoice)
                            <li class="fcm2__row">
                                <x-ds.badge plain :tone="$invoice->type->badgeTone()" lang="he">{{ $invoice->type->label() }}</x-ds.badge>
                                <span class="fcm2__row-main ds-num" dir="ltr">#{{ $invoice->displayNumber() }}</span>
                                <span class="fcm2__row-meta">{{ $invoice->issued_on->format('d.m.Y') }} · {{ __('finance.modal.items_count', ['count' => $invoice->items->count()]) }}</span>
                                <b class="ds-num">{{ FinanceMoney::format($invoice->type === InvoiceType::Credit ? -$invoice->total : $invoice->total) }}</b>
                                @unless($readonly)
                                <span class="fcm2__row-action">
                                    <a class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm" href="{{ route('workspace.finance.invoices.show', $invoice) }}" target="_blank" rel="noopener" aria-label="{{ __('finance.modal.view') }}" data-tip="{{ __('finance.modal.view') }}">
                                        <x-ds.icon name="file" />
                                    </a>
                                    <form method="post" action="{{ route('workspace.finance.invoices.destroy', $invoice) }}" data-fin-confirm="{{ __('finance.modal.delete_invoice') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm ds-btn--danger" aria-label="{{ __('finance.modal.delete') }}" data-tip="{{ __('finance.modal.delete') }}">
                                            <x-ds.icon name="trash" />
                                        </button>
                                    </form>
                                </span>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
        @endif
    </div>

    @unless($readonly)
    <footer class="ds-dialog__foot fcm2__foot">
        @unless($embedded)
        <div class="fcm2__foot-set" data-ds-for="details" @if($tab !== 'details') hidden @endif>
            <span class="fcm2__dirty" data-fcm-dirty hidden>{{ __('finance.modal.unsaved') }}</span>
            <span class="ds-spacer"></span>
            <button type="button" class="ds-btn ds-btn--secondary" data-fin-close>{{ __('finance.modal.cancel') }}</button>
            <button type="submit" class="ds-btn ds-btn--primary" form="{{ $formId }}">{{ __('finance.modal.save') }}</button>
        </div>
        @endunless
        <div class="fcm2__foot-set" data-ds-for="payments" @if($tab !== 'payments') hidden @endif>
            <span class="ds-spacer"></span>
            @unless($embedded)<button type="button" class="ds-btn ds-btn--secondary" data-fin-close>{{ __('finance.modal.close') }}</button>@endunless
            <button type="submit" class="ds-btn ds-btn--primary" form="{{ $billId }}">{{ __('finance.modal.save_billing') }}</button>
        </div>
        <div class="fcm2__foot-set" data-ds-for="invoices" @if($tab !== 'invoices') hidden @endif>
            <span class="ds-spacer"></span>
            <div class="fcm2__foot-closed">
                @unless($embedded)<button type="button" class="ds-btn ds-btn--secondary" data-fin-close>{{ __('finance.modal.close') }}</button>@endunless
                @if($storageItems->isNotEmpty())
                    <button type="button" class="ds-btn ds-btn--primary" data-fcm-builder-toggle>
                        <x-ds.icon name="plus" />
                        {{ __('finance.modal.new_invoice') }}
                    </button>
                @endif
            </div>
            <div class="fcm2__foot-open">
                <button type="button" class="ds-btn ds-btn--secondary" data-fcm-builder-toggle>{{ __('finance.modal.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary" form="{{ $docId }}">{{ __('finance.modal.create') }}</button>
            </div>
        </div>
    </footer>
    @endunless

    @unless($readonly)
    {{-- Record a payment, or pay a failed one: method, proof and a note. --}}
    <dialog class="ds-dialog fcm2-sub" data-fcm-pay aria-labelledby="fcm-pay-title-{{ $client->id }}"
            @if($payErrors) data-reopen="{{ $payMode }}" @endif>
        <form method="post"
              action="{{ $payMode === 'settle' && $payTarget ? route('workspace.finance.payments.settle', $payTarget) : route('workspace.finance.clients.payments.store', $client) }}"
              enctype="multipart/form-data"
              class="fcm2-sub__form"
              data-fcm-pay-form>
            @csrf
            <input type="hidden" name="_pay_mode" value="{{ $payMode }}" data-fcm-pay-mode>
            <input type="hidden" name="_pay_target" value="{{ $payTarget }}" data-fcm-pay-target>
            <header class="ds-dialog__head">
                <h3 id="fcm-pay-title-{{ $client->id }}" data-fcm-pay-title>{{ __('finance.modal.record_title') }}</h3>
                <span class="ds-num fcm2-sub__amount" data-fcm-pay-amount></span>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('finance.modal.close') }}"><x-ds.icon name="x" /></button>
            </header>
            <div class="ds-dialog__body fcm2-sub__body">
                @if($errors->has('files') || $errors->has('files.*'))
                    <p class="fcm2__alert" role="alert">{{ $errors->first('files.*') ?: $errors->first('files') }}</p>
                @endif
                <fieldset class="fcm2__grid fcm2__grid--3" data-fcm-pay-record>
                    <x-ds.field :label="__('finance.modal.period')" for="fcm-pay-period-{{ $client->id }}" error="period">
                        <input id="fcm-pay-period-{{ $client->id }}" class="ds-input" type="month" name="period" value="{{ old('period', now()->format('Y-m')) }}">
                    </x-ds.field>
                    <x-ds.field :label="__('finance.modal.amount')" for="fcm-pay-amount-{{ $client->id }}" error="amount">
                        <div class="ds-affix">
                            <span class="ds-affix__lead">₪</span>
                            <input id="fcm-pay-amount-{{ $client->id }}" class="ds-input ds-num" type="number" name="amount" min="0" step="0.01" value="{{ old('amount', (float) $client->monthly_fee) }}" dir="ltr">
                        </div>
                    </x-ds.field>
                    <x-ds.field :label="__('finance.modal.status')" error="status">
                        <x-ds.select name="status" :options="$options(PaymentRecordStatus::class)" :value="old('status', 'paid')" :label="__('finance.modal.status')" data-fcm-pay-status />
                    </x-ds.field>
                </fieldset>
                <div class="fcm2__grid fcm2__grid--3">
                    <x-ds.field :label="__('finance.modal.method')" error="method">
                        <x-ds.select name="method" :options="$methods" :value="old('method', '')" :placeholder="__('finance.modal.pick_method')" :label="__('finance.modal.method')" />
                    </x-ds.field>
                    <x-ds.field :label="__('finance.modal.paid_on')" for="fcm-pay-date-{{ $client->id }}" error="paid_on">
                        <input id="fcm-pay-date-{{ $client->id }}" class="ds-input" type="date" name="paid_on" value="{{ old('paid_on', now()->toDateString()) }}">
                    </x-ds.field>
                    <x-ds.field :label="__('finance.modal.reference')" for="fcm-pay-ref-{{ $client->id }}" error="reference">
                        <input id="fcm-pay-ref-{{ $client->id }}" class="ds-input" type="text" name="reference" maxlength="120" value="{{ old('reference') }}">
                    </x-ds.field>
                </div>
                <x-ds.field :label="__('finance.modal.note')" for="fcm-pay-note-{{ $client->id }}" error="note">
                    <textarea id="fcm-pay-note-{{ $client->id }}" class="ds-textarea" name="note" rows="2" maxlength="2000">{{ old('note') }}</textarea>
                </x-ds.field>
                <label class="fcm2-drop" data-fcm-drop>
                    <input type="file" name="files[]" accept="image/*,application/pdf" multiple data-fcm-files>
                    <x-ds.icon name="paperclip" />
                    <span>
                        <strong>{{ __('finance.modal.proof') }}</strong>
                        <small>{{ __('finance.modal.proof_hint') }}</small>
                    </span>
                </label>
                <div class="fcm2-picked" data-fcm-picked hidden></div>
            </div>
            <footer class="ds-dialog__foot">
                <span class="ds-spacer"></span>
                <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('finance.modal.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary" data-fcm-pay-submit data-record="{{ __('finance.modal.add_payment') }}" data-settle="{{ __('finance.modal.pay_submit') }}">{{ __('finance.modal.add_payment') }}</button>
            </footer>
        </form>
    </dialog>

    {{-- One payment's method, note and attachments. Filled from the row's template. --}}
    <dialog class="ds-dialog fcm2-sub fcm2-sub--narrow" data-fcm-detail-dialog aria-label="{{ __('finance.modal.payment_details') }}">
        <header class="ds-dialog__head">
            <h3>{{ __('finance.modal.payment_details') }}</h3>
            <span class="ds-spacer"></span>
            <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('finance.modal.close') }}"><x-ds.icon name="x" /></button>
        </header>
        <div class="ds-dialog__body fcm2-sub__body" data-fcm-detail-body></div>
    </dialog>
    @endunless
</div>
