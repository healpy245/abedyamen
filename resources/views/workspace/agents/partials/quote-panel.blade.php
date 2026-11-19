@php
    use App\Enums\Sales\QuoteStatus;
    use App\Models\Sales\SalesQuote;
    use App\Support\FinanceMoney;
    /** @var \App\Models\Sales\SalesQuote $quote */
    $editing = $quote->isLocked() ? null : [
        'action' => route('workspace.agents.sales.update', $quote),
        'finance_client_id' => $quote->finance_client_id,
        'agent_lead_id' => $quote->agent_lead_id,
        'notes' => $quote->notes,
        'lines' => $quote->items->map(fn ($l) => [
            'kind' => $l->kind,
            'ref_id' => $l->sales_catalog_item_id ?? $l->storage_item_id,
            'name' => $l->name,
            'quantity' => (float) $l->quantity,
            'unit_price' => (float) $l->unit_price,
            'billing' => $l->billing,
        ])->values(),
    ];
    $fileName = $quote->number.'-'.(\Illuminate\Support\Str::slug($quote->customerName()) ?: 'quote');
    $messages = collect(SalesQuote::DOCUMENT_LANGUAGES)->mapWithKeys(fn ($lang) => [
        $lang => __($quote->needsConversion() ? 'sales.whatsapp.pdf_message' : 'sales.whatsapp.message', ['client' => $quote->customerName(), 'number' => $quote->number], $lang),
    ]);
    // A quote for a lead is a plain price quote: PDF and WhatsApp as before, no contract or signature.
    $forLead = $quote->needsConversion();
    $signature = $forLead ? null : $quote->signature;
    $signed = $signature?->isSigned() ?? false;
@endphp
<div class="sq-panel" data-sq-panel
     data-editing="{{ json_encode($editing) }}"
     data-panel-url="{{ route('workspace.agents.sales.panel', $quote) }}"
     data-document-url="{{ route('workspace.agents.sales.document', $quote) }}"
     data-whatsapp-url="{{ route('workspace.agents.sales.whatsapp', $quote) }}"
     data-request-url="{{ route('workspace.agents.sales.request', $quote) }}"
     data-filename="{{ $fileName }}"
     data-lang="{{ $quote->lang }}"
     data-phone="{{ $quote->customerPhone() }}"
     data-wa-mode="{{ $forLead ? 'pdf' : 'link' }}"
     data-messages="{{ $messages->toJson() }}"
     data-has-files="{{ $quote->files->isNotEmpty() ? '1' : '0' }}"
     @if(request()->boolean('resend') && ! $signed && ! $quote->isWithFinance()) data-resend @endif
     data-deal-note="{{ $quote->deal_note }}">
    <header class="sq-panel__head">
        <span class="sq-panel__avatar"><x-ds.avatar :src="$quote->client?->logo" :name="$quote->customerName() ?: '?'" /></span>
        <div class="sq-panel__title">
            <h2 id="sq-detail-title">{{ $quote->title() }}</h2>
            <p>{{ $quote->customerName() }}@if($quote->finance_client_id === null) ({{ __('sales.lead') }})@endif · {{ $quote->agent?->name ?? '—' }} · {{ $quote->created_at?->format('j.n.Y') }}</p>
        </div>
        <x-ds.badge :tone="$quote->status->badgeTone()" soft>{{ $quote->status->label() }}</x-ds.badge>
        <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon sq-panel__close" data-fin-sheet-close aria-label="{{ __('sales.close') }}"><x-ds.icon name="x" /></button>
    </header>

    <div class="sq-panel__body">
        <div class="sq-share">
            @if($forLead)
                <div class="sq-pdf">
                    <button type="button" class="ds-btn ds-btn--secondary" data-sq-pdf-toggle aria-expanded="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
                        {{ __('sales.download_pdf') }}
                    </button>
                    <div class="sq-pdf__menu" data-sq-pdf-menu hidden>
                        <span class="sq-pdf__label">{{ __('sales.pdf_language') }}</span>
                        @foreach(SalesQuote::DOCUMENT_LANGUAGES as $lang)
                            <button type="button" data-sq-pdf-lang="{{ $lang }}" @if($lang === $quote->lang) class="is-default" @endif>{{ __('sales.lang_short.'.$lang) }}</button>
                        @endforeach
                    </div>
                </div>
            @else
                <button type="button" class="ds-btn ds-btn--secondary" data-sq-pdf>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
                    {{ __('sales.download_pdf') }}
                </button>
            @endif
            @unless($signed || $quote->isWithFinance())
                <button type="button" class="ds-btn ds-btn--secondary sq-wa-btn" data-sq-whatsapp>
                    <x-ds.icon name="whatsapp" />
                    {{ __('sales.send_whatsapp') }}
                </button>
            @endunless
            @if($quote->whatsapp_sent_at)
                <small class="sq-share__sent">{{ __('sales.whatsapp_sent_at', ['date' => $quote->whatsapp_sent_at->format('j.n H:i')]) }}</small>
            @endif
        </div>

        @if($signed)
            <div class="sq-state is-sold sq-signature">
                <x-ds.icon name="check" />
                <span>
                    <strong>{{ __('sales.contract.state_signed') }}</strong>
                    <small>{{ __('sales.contract.state_signed_body', ['name' => $signature->signer_name, 'date' => $signature->signed_at?->format('j.n.Y H:i')]) }}</small>
                </span>
                <span class="sq-signature__actions">
                    <a class="ds-btn ds-btn--secondary ds-btn--sm" href="{{ route('workspace.agents.sales.document', $quote) }}" target="_blank" rel="noopener"><x-ds.icon name="file" />{{ __('sales.contract.view') }}</a>
                    @unless($quote->isWithFinance())
                        <form method="post" action="{{ route('workspace.agents.sales.signature.cancel', $quote) }}"
                              data-fin-confirm="{{ __('sales.contract.resend_confirm') }}"
                              data-fin-confirm-ok="{{ __('sales.contract.resend_ok') }}">
                            @csrf
                            <button type="submit" class="ds-btn ds-btn--ghost ds-btn--sm"><x-ds.icon name="send" />{{ __('sales.contract.resend') }}</button>
                        </form>
                    @endunless
                </span>
            </div>
        @elseif($signature !== null)
            <div class="sq-state is-pending sq-signature">
                <x-ds.icon name="send" />
                <span>
                    <strong>{{ __('sales.contract.state_waiting') }}</strong>
                    <small>{{ __('sales.contract.state_waiting_body') }}</small>
                </span>
                <span class="sq-signature__actions">
                    <a class="ds-btn ds-btn--ghost ds-btn--sm" href="{{ $signature->url() }}" target="_blank" rel="noopener"><x-ds.icon name="external" />{{ __('sales.contract.open_link') }}</a>
                </span>
            </div>
        @endif

        @if($quote->status === QuoteStatus::Pending)
            <div class="sq-state is-pending">
                <x-ds.icon name="calendar" />
                <span><strong>{{ __('sales.pending_title') }}</strong><small>{{ __('sales.pending_body', ['date' => $quote->requested_at?->format('j.n.Y H:i')]) }}</small></span>
            </div>
        @elseif($quote->status === QuoteStatus::Returned)
            <div class="sq-state is-returned">
                <x-ds.icon name="x" />
                <span><strong>{{ __('sales.returned_title') }}</strong><small>{{ $quote->finance_note }}</small></span>
            </div>
        @elseif($quote->synced_at)
            <div class="sq-state is-sold">
                <x-ds.icon name="check" />
                <span><strong>{{ __('sales.synced_title') }}</strong><small>{{ __('sales.synced_body', ['date' => $quote->synced_at->format('j.n.Y H:i')]) }}</small></span>
                @if(auth()->user()->canOpenSubDoor(\App\Enums\Door::Finance, 'clients') && $quote->invoice)
                    <a class="ds-btn ds-btn--secondary ds-btn--sm" href="{{ route('workspace.finance.invoices.show', $quote->invoice) }}" target="_blank" rel="noopener">{{ __('sales.open_invoice', ['number' => $quote->invoice->displayNumber()]) }}</a>
                @endif
            </div>
        @endif

        @foreach(['monthly', 'one_time'] as $billing)
            @php $lines = $quote->items->where('billing', $billing); @endphp
            @continue($lines->isEmpty())
            <section class="sq-group">
                <h3 class="sq-group__title">
                    @include('components.workspace.icons', ['name' => $billing === 'monthly' ? 'calendar' : 'box'])
                    {{ __('sales.'.$billing.'_lines') }}
                </h3>
                <div class="sq-group__lines">
                    @foreach($lines as $line)
                        <div class="sq-line">
                            <span class="sq-line__name">{{ $line->displayName() }}</span>
                            @if($line->kind === 'equipment')<span class="ds-muted ds-num" dir="ltr">× {{ $line->quantityLabel() }}</span>@endif
                            <b class="ds-num">{{ FinanceMoney::format($line->line_total) }}</b>
                        </div>
                    @endforeach
                </div>
                <div class="sq-group__total">
                    <span>{{ __('sales.'.$billing.'_total') }}</span>
                    <b class="ds-num">{{ FinanceMoney::format($billing === 'monthly' ? $quote->monthly_total : $quote->one_time_total) }}</b>
                </div>
            </section>
        @endforeach

        @if($quote->notes)
            <p class="sq-notes">{{ $quote->notes }}</p>
        @endif
    </div>

    @unless($quote->isWithFinance())
        <footer class="sq-panel__foot">
            @unless($quote->isLocked())
                <form method="post" action="{{ route('workspace.agents.sales.destroy', $quote) }}" data-fin-confirm="{{ __('sales.delete_confirm', ['quote' => $quote->title()]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--danger" aria-label="{{ __('sales.delete') }}" data-tip="{{ __('sales.delete') }}"><x-ds.icon name="trash" /></button>
                </form>
            @endunless
            <span class="ds-spacer"></span>
            @unless($quote->isLocked())
                <button type="button" class="ds-btn ds-btn--secondary" data-sq-edit><x-ds.icon name="edit" />{{ __('sales.edit') }}</button>
            @endunless
            @if($forLead)
                {{-- A lead's quote is only sent to the lead; there is nothing to sign or send to Finance. --}}
            @elseif($signed)
                <button type="button" class="ds-btn ds-btn--primary sq-sell" data-sq-request><x-ds.icon name="send" />{{ __('sales.request_close') }}</button>
            @else
                <small class="sq-panel__hint">{{ __('sales.contract.need_signature') }}</small>
            @endif
        </footer>
    @endunless
</div>
