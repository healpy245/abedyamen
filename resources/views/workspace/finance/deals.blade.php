@extends('workspace.finance.layout')

@php
    use App\Enums\Sales\QuoteStatus;
    use App\Support\FinanceMoney;
    $method = function ($client) {
        return $client?->has_payment_method
            ? '<span class="fc-method is-yes"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg><span>'.e(__('finance.clients.method_yes')).'</span></span>'
            : '<span class="fc-method"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M6 12h12"/></svg><span>'.e(__('finance.clients.method_no')).'</span></span>';
    };
@endphp

@section('content')
    <div class="ds-page fd-page">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('finance.deals.title') }}</h1>
                <p class="ds-sub">{{ __('finance.deals.subtitle') }}</p>
            </div>
        </header>

        <section class="ds-card fd-table">
            @forelse($pending as $deal)
                <button type="button" class="fd-row" data-fd-open="{{ $deal->id }}">
                    <x-ds.avatar :src="$deal->client?->logo" :name="$deal->client?->name ?? '?'" />
                    <span class="fd-row__main">
                        <strong>{{ $deal->client?->name }}</strong>
                        <small>{{ $deal->title() }} · {{ $deal->agent?->name ?? '—' }} · {{ $deal->requested_at?->format('j.n.Y H:i') }}</small>
                    </span>
                    <span class="fd-row__money">
                        <b class="ds-num">{{ FinanceMoney::format($deal->monthly_total) }}</b><small>{{ __('sales.monthly') }}</small>
                    </span>
                    <span class="fd-row__money">
                        <b class="ds-num">{{ FinanceMoney::format($deal->one_time_total) }}</b><small>{{ __('sales.one_time') }}</small>
                    </span>
                    <span class="fd-row__method">{!! $method($deal->client) !!}</span>
                    <x-ds.icon name="chevron" class="fd-row__go" />
                </button>
            @empty
                <x-ds.empty :title="__('finance.deals.empty')" icon="check" class="fd-empty" />
            @endforelse
        </section>

        @if($recent->isNotEmpty())
            <h2 class="ds-section-title fd-recent-title">{{ __('finance.deals.recent') }}</h2>
            <section class="ds-card fd-table">
                @foreach($recent as $deal)
                    <button type="button" class="fd-row fd-row--done" data-fd-open="{{ $deal->id }}">
                        <x-ds.badge :tone="$deal->status->badgeTone()" soft>{{ $deal->status->label() }}</x-ds.badge>
                        <span class="fd-row__main">
                            <strong>{{ $deal->client?->name }}</strong>
                            <small>{{ $deal->title() }} · {{ $deal->agent?->name ?? '—' }} · {{ $deal->updated_at?->format('j.n.Y H:i') }}</small>
                        </span>
                        <x-ds.icon name="chevron" class="fd-row__go" />
                    </button>
                @endforeach
            </section>
        @endif
    </div>
@endsection

@push('dialogs')
    @foreach($pending->concat($recent) as $deal)
        <dialog class="ds-dialog fd-modal" data-fd-modal="{{ $deal->id }}" @if($open?->id === $deal->id) data-open-on-load @endif aria-labelledby="fd-title-{{ $deal->id }}">
            <header class="fd-modal__head">
                <x-ds.avatar :src="$deal->client?->logo" :name="$deal->client?->name ?? '?'" size="lg" />
                <div class="fd-modal__title">
                    <h2 id="fd-title-{{ $deal->id }}">{{ $deal->client?->name }}</h2>
                    <p>{{ __('finance.deals.by', ['agent' => $deal->agent?->name ?? '—']) }}</p>
                </div>
                <x-ds.badge :tone="$deal->status->badgeTone()" soft>{{ $deal->status->label() }}</x-ds.badge>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('finance.modal.close') }}"><x-ds.icon name="x" /></button>
            </header>

            <div class="fd-modal__body">
                <div class="fd-totals">
                    <div><span>{{ __('sales.monthly_total') }}</span><b class="ds-num">{{ FinanceMoney::format($deal->monthly_total) }}</b></div>
                    <div><span>{{ __('sales.one_time_total') }}</span><b class="ds-num">{{ FinanceMoney::format($deal->one_time_total) }}</b></div>
                    <div><span lang="he">{{ __('finance.modal.has_method') }}</span>{!! $method($deal->client) !!}</div>
                </div>

                @if($deal->deal_note)
                    <div class="fd-note">
                        <span class="ds-label">{{ __('finance.deals.agent_note') }}</span>
                        <p>{{ $deal->deal_note }}</p>
                    </div>
                @endif

                @if($deal->files->isNotEmpty())
                    <div class="fd-evidence">
                        <span class="ds-label">{{ __('finance.deals.evidence') }} ({{ $deal->files->count() }})</span>
                        <div class="fd-evidence__grid">
                            @foreach($deal->files as $file)
                                @if($file->isImage())
                                    <button type="button" class="fd-evidence__item" data-fd-image="{{ route('workspace.agents.sales.file', $file) }}" data-name="{{ $file->original_name }}" aria-label="{{ $file->original_name }}">
                                        <img src="{{ route('workspace.agents.sales.file', $file) }}" alt="{{ $file->original_name }}" loading="lazy">
                                    </button>
                                @else
                                    <a class="fd-evidence__item" href="{{ route('workspace.agents.sales.file', $file) }}" target="_blank" rel="noopener" title="{{ $file->original_name }}">
                                        <span class="fd-evidence__doc"><x-ds.icon name="file" /><small>{{ $file->original_name }}</small></span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach(['monthly', 'one_time'] as $billing)
                    @php $lines = $deal->items->where('billing', $billing); @endphp
                    @continue($lines->isEmpty())
                    <div class="fd-lines">
                        <span class="ds-label">{{ __('sales.'.$billing.'_lines') }}</span>
                        @foreach($lines as $line)
                            <div class="fd-line">
                                <span>{{ $line->displayName() }}</span>
                                @if($line->kind === 'equipment')<small class="ds-muted" dir="ltr">× {{ $line->quantityLabel() }}</small>@endif
                                <b class="ds-num">{{ FinanceMoney::format($line->line_total) }}</b>
                            </div>
                        @endforeach
                    </div>
                @endforeach

                @if($deal->status === QuoteStatus::Sold)
                    <p class="fd-hint is-done">{{ __('finance.deals.done', ['user' => $deal->confirmer?->name ?? '—', 'date' => $deal->synced_at?->format('j.n.Y H:i')]) }}
                        @if($deal->invoice)
                            · <a href="{{ route('workspace.finance.invoices.show', $deal->invoice) }}" target="_blank" rel="noopener">{{ __('sales.open_invoice', ['number' => $deal->invoice->displayNumber()]) }}</a>
                        @endif
                    </p>
                @elseif($deal->status === QuoteStatus::Returned && $deal->finance_note)
                    <p class="fd-hint is-returned">{{ __('finance.deals.returned_with', ['note' => $deal->finance_note]) }}</p>
                @elseif($deal->status === QuoteStatus::Pending)
                    <p class="fd-hint">{{ __('finance.deals.confirm_hint') }}</p>
                @endif
            </div>

            @if($deal->status === QuoteStatus::Pending)
                <footer class="fd-modal__foot">
                    <button type="button" class="ds-btn ds-btn--secondary" data-fd-return="{{ route('workspace.finance.deals.return', $deal) }}">{{ __('finance.deals.return') }}</button>
                    <button type="button" class="ds-btn ds-btn--primary" data-fd-confirm="{{ $deal->id }}"><x-ds.icon name="check" />{{ __('finance.deals.confirm') }}</button>
                </footer>
            @endif
        </dialog>

        @if($deal->status === QuoteStatus::Pending)
            @php
                // What the client will be billed after this deal: their current monthly lines plus this deal's.
                $monthlyNow = collect($deal->client?->billingLines() ?? [])->sum('amount');
                $monthlyNext = round($monthlyNow + (float) $deal->monthly_total, 2);
                $firstBilling = $deal->client?->next_billing_date ?? now()->addMonthNoOverflow()->startOfMonth();
                $vatRate = (float) config('finance.vat_rate', 18);
                $oneTime = (float) $deal->one_time_total;
            @endphp
            <dialog class="ds-dialog fs-dialog fs-dialog--sm fd-confirm" data-fd-confirm-dialog="{{ $deal->id }}" aria-labelledby="fd-confirm-title-{{ $deal->id }}">
                <form method="post" action="{{ route('workspace.finance.deals.confirm', $deal) }}" class="fs-dialog__form">
                    @csrf
                    <header class="ds-dialog__head fs-dialog__head">
                        <div>
                            <h2 id="fd-confirm-title-{{ $deal->id }}">{{ __('finance.deals.confirm_question', ['client' => $deal->client?->name]) }}</h2>
                            <p class="ds-sub">{{ __('finance.deals.confirm_sub') }}</p>
                        </div>
                        <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('finance.modal.close') }}"><x-ds.icon name="x" /></button>
                    </header>
                    <div class="ds-dialog__body fs-dialog__body">
                        @if($monthlyNext > 0)
                            <div class="fd-next">
                                <span class="ds-label">{{ __('finance.deals.next_billing') }}</span>
                                <b class="ds-num">{{ FinanceMoney::format($monthlyNext) }}</b>
                                <small>
                                    {{ __('finance.deals.next_billing_vat', ['total' => FinanceMoney::format(round($monthlyNext * (1 + $vatRate / 100), 2)), 'rate' => rtrim(rtrim(number_format($vatRate, 2), '0'), '.')]) }}
                                    @if($monthlyNow > 0) · {{ __('finance.deals.next_billing_breakdown', ['now' => FinanceMoney::format($monthlyNow), 'added' => FinanceMoney::format($deal->monthly_total)]) }}@endif
                                </small>
                            </div>
                            <x-ds.field :label="__('finance.deals.next_billing_date')" for="fd-date-{{ $deal->id }}" :hint="__('finance.deals.next_billing_date_hint')">
                                <input id="fd-date-{{ $deal->id }}" class="ds-input" type="date" name="next_billing_date" required value="{{ $firstBilling->toDateString() }}" min="{{ now()->toDateString() }}">
                            </x-ds.field>
                        @endif
                        @if($oneTime > 0)
                            @php $devicesTotal = round($oneTime * (1 + $vatRate / 100), 2); @endphp
                            <div class="fd-devices" data-fd-devices data-total="{{ $devicesTotal }}">
                                <div class="fd-devices__head">
                                    <span class="ds-label">{{ __('finance.devices.title') }}</span>
                                    <b class="ds-num">{{ FinanceMoney::format($devicesTotal) }}</b>
                                </div>
                                <div class="fd-choice" role="radiogroup" aria-label="{{ __('finance.devices.title') }}">
                                    <label><input type="radio" name="device_payment" value="full" checked data-fd-pay-mode><span>{{ __('finance.devices.full') }}</span></label>
                                    <label><input type="radio" name="device_payment" value="partial" data-fd-pay-mode><span>{{ __('finance.devices.partial') }}</span></label>
                                </div>
                                <div class="fd-devices__partial" data-fd-partial hidden>
                                    <x-ds.field :label="__('finance.devices.left')" for="fd-left-{{ $deal->id }}">
                                        <div class="ds-affix">
                                            <span class="ds-affix__lead">₪</span>
                                            <input id="fd-left-{{ $deal->id }}" class="ds-input ds-num" type="number" name="devices_left" min="0" max="{{ $devicesTotal }}" step="0.01" dir="ltr" data-fd-left disabled>
                                        </div>
                                    </x-ds.field>
                                </div>
                                <p class="fd-devices__invoice" data-fd-invoice-line
                                   data-full="{{ __('finance.devices.invoice_full', ['amount' => FinanceMoney::format($devicesTotal)]) }}"
                                   data-partial="{{ __('finance.devices.invoice_partial') }}"
                                   data-none="{{ __('finance.devices.invoice_none') }}">{{ __('finance.devices.invoice_full', ['amount' => FinanceMoney::format($devicesTotal)]) }}</p>
                            </div>
                        @endif
                    </div>
                    <footer class="ds-dialog__foot">
                        <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('finance.modal.cancel') }}</button>
                        <button type="submit" class="ds-btn ds-btn--primary"><x-ds.icon name="check" />{{ __('finance.deals.confirm') }}</button>
                    </footer>
                </form>
            </dialog>
        @endif
    @endforeach

    <dialog class="ds-dialog fd-lightbox" data-fd-lightbox aria-label="{{ __('finance.deals.evidence') }}">
        <button type="button" class="ds-btn ds-btn--secondary ds-btn--icon fd-lightbox__close" data-fin-sheet-close aria-label="{{ __('finance.modal.close') }}"><x-ds.icon name="x" /></button>
        <img src="" alt="" data-fd-lightbox-img>
        <a class="ds-btn ds-btn--secondary ds-btn--sm fd-lightbox__open" href="#" target="_blank" rel="noopener" data-fd-lightbox-link><x-ds.icon name="external" />{{ __('finance.deals.open_original') }}</a>
    </dialog>

    <dialog class="ds-dialog fs-dialog fs-dialog--sm" data-fd-return-dialog aria-labelledby="fd-return-title">
        <form method="post" action="#" class="fs-dialog__form" data-fd-return-form>
            @csrf
            <header class="ds-dialog__head fs-dialog__head">
                <h2 id="fd-return-title">{{ __('finance.deals.return_title') }}</h2>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('finance.modal.close') }}"><x-ds.icon name="x" /></button>
            </header>
            <div class="ds-dialog__body fs-dialog__body">
                <x-ds.field :label="__('finance.deals.return_note')" for="fd-return-note">
                    <textarea id="fd-return-note" class="ds-input ds-textarea" name="finance_note" rows="4" required maxlength="2000"></textarea>
                </x-ds.field>
            </div>
            <footer class="ds-dialog__foot">
                <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('finance.modal.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary">{{ __('finance.deals.return') }}</button>
            </footer>
        </form>
    </dialog>
@endpush
