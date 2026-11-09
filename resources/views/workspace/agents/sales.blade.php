@extends('workspace.agents.layout')

@php
    use App\Enums\Sales\QuoteStatus;
    use App\Support\FinanceMoney;
    $statusLink = fn (string $value) => route('workspace.agents.sales', array_filter(['status' => $value, 'agent' => $filters['agent'], 'q' => $search]));
@endphp

@section('content')
    <div class="ds-page sq-page">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('sales.title') }}</h1>
                <p class="ds-sub">{{ __('sales.subtitle') }}</p>
            </div>
            <button type="button" class="ds-btn ds-btn--primary" data-sq-new>
                <x-ds.icon name="plus" />
                {{ __('sales.new_quote') }}
            </button>
        </header>

        <section class="ds-stats ds-card">
            <div class="ds-stat">
                <span class="ds-label">{{ __('sales.stats.open') }}</span>
                <strong class="ds-num">{{ $stats['open'] }}</strong>
            </div>
            <a class="ds-stat ds-stat--link" href="{{ $statusLink('pending') }}">
                <span class="ds-label">{{ __('sales.stats.pending') }}</span>
                <strong class="ds-num {{ $stats['pending'] > 0 ? 'is-warn' : '' }}">{{ $stats['pending'] }}</strong>
            </a>
            <div class="ds-stat">
                <span class="ds-label">{{ __('sales.stats.sold') }}</span>
                <strong class="ds-num">{{ $stats['sold'] }}</strong>
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('sales.stats.monthly') }}</span>
                <strong class="ds-num">{{ FinanceMoney::format($stats['monthly']) }}</strong>
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('sales.stats.one_time') }}</span>
                <strong class="ds-num">{{ FinanceMoney::format($stats['one_time']) }}</strong>
            </div>
        </section>

        <form class="ds-toolbar" method="get" action="{{ route('workspace.agents.sales') }}" data-no-loading>
            <div class="ds-affix ds-toolbar__search">
                <span class="ds-affix__lead"><x-ds.icon name="search" /></span>
                <input class="ds-input" type="search" name="q" value="{{ $search }}"
                       placeholder="{{ __('sales.search') }}" aria-label="{{ __('sales.search') }}" data-fin-search>
            </div>
            @if($filters['status'] !== '')
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
            @endif
            @if($agents !== [])
                <x-ds.select name="agent" :options="$agents" :value="$filters['agent']" :placeholder="__('sales.all_agents')" :label="__('sales.agent')" auto wrap="ds-toolbar__select" />
            @endif
        </form>

        <nav class="ds-seg sq-status" aria-label="{{ __('sales.status_label') }}">
            <a href="{{ $statusLink('') }}" @if($filters['status'] === '') aria-current="page" @endif>{{ __('sales.all') }}</a>
            @foreach(QuoteStatus::cases() as $case)
                <a href="{{ $statusLink($case->value) }}" @if($filters['status'] === $case->value) aria-current="page" @endif>{{ $case->label() }}</a>
            @endforeach
        </nav>

        <div class="sq-list">
            @forelse($quotes as $quote)
                <a class="ds-card sq-card" data-sq-card="{{ route('workspace.agents.sales.panel', $quote) }}" data-tone="{{ $quote->status->badgeTone() }}" href="{{ route('workspace.agents.sales', array_filter(['open' => $quote->id, 'status' => $filters['status'], 'agent' => $filters['agent'], 'q' => $search])) }}">
                    <span class="sq-card__head">
                        <strong>{{ $quote->customerName() }}</strong>
                        @if($quote->finance_client_id === null)<x-ds.badge soft>{{ __('sales.lead') }}</x-ds.badge>@endif
                        <x-ds.badge :tone="$quote->status->badgeTone()" soft>{{ $quote->status->label() }}</x-ds.badge>
                    </span>
                    <small class="ds-muted">{{ $quote->title() }}@if($quote->contact_name) · {{ $quote->contact_name }}@endif</small>
                    <span class="sq-card__money">
                        @if((float) $quote->monthly_total > 0)
                            <span><b class="ds-num">{{ FinanceMoney::format($quote->monthly_total) }}</b><small>{{ __('sales.monthly') }}</small></span>
                        @endif
                        @if((float) $quote->one_time_total > 0)
                            <span><b class="ds-num">{{ FinanceMoney::format($quote->one_time_total) }}</b><small>{{ __('sales.one_time') }}</small></span>
                        @endif
                    </span>
                    <small class="ds-muted">{{ trans_choice('sales.items_count', $quote->items_count, ['count' => $quote->items_count]) }} · {{ $quote->agent?->name ?? '—' }}</small>
                </a>
            @empty
                <x-ds.empty :title="__('sales.empty')" icon="receipt" class="sq-empty" />
            @endforelse
        </div>
    </div>
@endsection

@push('dialogs')
    <dialog class="ds-dialog fs-dialog sq-wa" data-sq-wa-dialog aria-labelledby="sq-wa-title">
        <form class="fs-dialog__form" data-sq-wa-form>
            <header class="ds-dialog__head fs-dialog__head">
                <div>
                    <h2 id="sq-wa-title">{{ __('sales.whatsapp.title') }}</h2>
                    <p class="ds-sub">{{ __('sales.whatsapp.subtitle') }}</p>
                </div>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('sales.close') }}"><x-ds.icon name="x" /></button>
            </header>
            <div class="ds-dialog__body fs-dialog__body">
                <x-ds.field :label="__('sales.whatsapp.phone')" for="sq-wa-phone" :hint="__('sales.whatsapp.phone_hint')">
                    <input id="sq-wa-phone" class="ds-input ds-num" type="tel" name="phone" required maxlength="40" dir="ltr" placeholder="05X-XXXXXXX" data-sq-wa-phone>
                </x-ds.field>
                <div class="ds-field">
                    <span class="ds-label">{{ __('sales.pdf_language') }}</span>
                    <div class="sq-langs sq-langs--wide" role="radiogroup">
                        @foreach(\App\Models\Sales\SalesQuote::DOCUMENT_LANGUAGES as $lang)
                            <label><input type="radio" name="lang" value="{{ $lang }}" data-sq-wa-lang><span>{{ __('sales.lang_short.'.$lang) }}</span></label>
                        @endforeach
                    </div>
                </div>
                <x-ds.field :label="__('sales.whatsapp.message_label')" for="sq-wa-message">
                    <textarea id="sq-wa-message" class="ds-input ds-textarea" name="message" rows="4" maxlength="1000" data-sq-wa-message></textarea>
                </x-ds.field>
                <p class="sq-wa__error" data-sq-wa-error data-default="{{ __('sales.errors.whatsapp_failed') }}" hidden></p>
            </div>
            <footer class="ds-dialog__foot">
                <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('sales.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary sq-wa__send" data-sq-wa-send><x-ds.icon name="whatsapp" />{{ __('sales.whatsapp.send') }}</button>
            </footer>
        </form>
    </dialog>

    <dialog class="ds-dialog fs-dialog sq-request" data-sq-request-dialog aria-labelledby="sq-request-title">
        <form method="post" action="#" enctype="multipart/form-data" class="fs-dialog__form" data-sq-request-form>
            @csrf
            <header class="ds-dialog__head fs-dialog__head">
                <div>
                    <h2 id="sq-request-title">{{ __('sales.request.title') }}</h2>
                    <p class="ds-sub">{{ __('sales.request.subtitle') }}</p>
                </div>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('sales.close') }}"><x-ds.icon name="x" /></button>
            </header>
            <div class="ds-dialog__body fs-dialog__body">
                <div class="sq-request__guide">
                    <strong>{{ __('sales.request.guide_title') }}</strong>
                    <ul>
                        <li>{{ __('sales.request.guide_monthly') }}</li>
                        <li>{{ __('sales.request.guide_devices') }}</li>
                    </ul>
                </div>
                <x-ds.field :label="__('sales.request.note')" for="sq-request-note">
                    <textarea id="sq-request-note" class="ds-input ds-textarea" name="deal_note" rows="4" required maxlength="3000" placeholder="{{ __('sales.request.note_placeholder') }}" data-sq-request-note></textarea>
                </x-ds.field>
                <label class="sq-drop" data-sq-drop>
                    <input type="file" name="files[]" multiple accept="image/*,application/pdf" data-sq-files>
                    <x-ds.icon name="paperclip" />
                    <strong>{{ __('sales.request.add_evidence') }}</strong>
                    <small>{{ __('sales.request.evidence_hint') }}</small>
                </label>
                <div class="sq-thumbs" data-sq-thumbs></div>
                <p class="ds-field__hint" data-sq-has-files hidden>{{ __('sales.request.has_files') }}</p>
            </div>
            <footer class="ds-dialog__foot">
                <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('sales.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary"><x-ds.icon name="send" />{{ __('sales.request.send') }}</button>
            </footer>
        </form>
    </dialog>

    <dialog class="ds-dialog fs-dialog sq-convert" data-sq-convert-dialog aria-labelledby="sq-convert-title">
        <form method="post" action="#" class="fs-dialog__form" data-sq-convert-form>
            @csrf
            <header class="ds-dialog__head fs-dialog__head">
                <div>
                    <h2 id="sq-convert-title">{{ __('sales.convert.title') }}</h2>
                    <p class="ds-sub" data-sq-convert-lead data-template="{{ __('sales.convert.subtitle', ['lead' => ':lead']) }}"></p>
                </div>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('sales.close') }}"><x-ds.icon name="x" /></button>
            </header>
            <div class="ds-dialog__body fs-dialog__body">
                <div class="sq-request__guide sq-convert__step">
                    <span>{{ __('sales.convert.boarding_hint') }}</span>
                    <a class="ds-btn ds-btn--secondary ds-btn--sm" href="#" target="_blank" rel="noopener" data-sq-convert-boarding><x-ds.icon name="external" />{{ __('sales.convert.open_boarding') }}</a>
                </div>
                <div class="ds-field">
                    <label class="ds-label" for="sq-convert-client">{{ __('sales.convert.client') }}</label>
                    <select id="sq-convert-client" name="finance_client_id" required data-ds-select data-ds-search aria-label="{{ __('sales.convert.client') }}">
                        <option value="">{{ __('sales.pick_client') }}</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                    <p class="ds-field__hint">{{ __('sales.convert.client_hint') }}</p>
                </div>
            </div>
            <footer class="ds-dialog__foot">
                <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('sales.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary"><x-ds.icon name="check" />{{ __('sales.convert.save') }}</button>
            </footer>
        </form>
    </dialog>

    <dialog class="ds-dialog sq-drawer" data-sq-detail aria-labelledby="sq-detail-title" @if($open) data-open-on-load @endif>
        @if($open)
            @include('workspace.agents.partials.quote-panel', ['quote' => $open])
        @endif
    </dialog>

    @php
        $old = old('lines') !== null ? [
            'action' => old('_action', route('workspace.agents.sales.store')),
            'finance_client_id' => old('finance_client_id'),
            'agent_lead_id' => old('agent_lead_id'),
            'notes' => old('notes'),
            'lines' => array_values((array) old('lines', [])),
        ] : null;
        $base = $catalog['base'][0] ?? null;
    @endphp
    <dialog class="ds-dialog sq-builder" data-sq-builder aria-labelledby="sq-builder-title"
            @if($old) data-open-on-load @endif>
        <form method="post" action="{{ route('workspace.agents.sales.store') }}" class="fs-dialog__form" data-sq-form
              data-store="{{ route('workspace.agents.sales.store') }}">
            @csrf
            <input type="hidden" name="_method" value="POST" data-sq-method>
            <input type="hidden" name="_action" value="{{ route('workspace.agents.sales.store') }}" data-sq-action>
            <div data-sq-inputs hidden></div>

            <header class="ds-dialog__head fs-dialog__head">
                <h2 id="sq-builder-title" data-new="{{ __('sales.new_quote') }}" data-edit="{{ __('sales.edit_quote') }}">{{ __('sales.new_quote') }}</h2>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('sales.close') }}"><x-ds.icon name="x" /></button>
            </header>

            <div class="ds-dialog__body sq-builder__body">
                <div class="ds-field sq-client">
                    <div class="sq-client__head">
                        <label class="ds-label" for="sq-client">{{ $leads !== null ? __('sales.quote_for') : __('sales.client') }}</label>
                        @if($leads !== null)
                            <div class="sq-langs" role="radiogroup" aria-label="{{ __('sales.quote_for') }}">
                                <label><input type="radio" name="quote_for" value="client" checked data-sq-for><span>{{ __('sales.client') }}</span></label>
                                <label><input type="radio" name="quote_for" value="lead" data-sq-for><span>{{ __('sales.lead') }}</span></label>
                            </div>
                        @endif
                    </div>
                    <div data-sq-for-pane="client">
                        <select id="sq-client" name="finance_client_id" required data-ds-select data-ds-search data-sq-client aria-label="{{ __('sales.client') }}">
                            <option value="">{{ __('sales.pick_client') }}</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" data-contact="{{ $client->contact_name }}" data-phone="{{ $client->phone }}">{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($leads !== null)
                        <div data-sq-for-pane="lead" hidden>
                            <select id="sq-lead" name="agent_lead_id" disabled data-ds-select data-ds-search data-sq-lead aria-label="{{ __('sales.lead') }}">
                                <option value="">{{ __('sales.pick_lead') }}</option>
                                @foreach($leads as $lead)
                                    <option value="{{ $lead->id }}" data-contact="{{ $lead->contact }}" data-phone="{{ $lead->phone }}">{{ $lead->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <p class="sq-client__meta" data-sq-client-meta hidden></p>
                    @error('finance_client_id')<p class="ds-field__error" role="alert">{{ $message }}</p>@enderror
                    @error('agent_lead_id')<p class="ds-field__error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="sq-sections">
                    <section class="sq-section" data-sq-monthly>
                        <header class="sq-section__head">
                            <span class="sq-section__icon">@include('components.workspace.icons', ['name' => 'calendar'])</span>
                            <span class="sq-section__title">
                                <strong>{{ __('sales.monthly_offer') }}</strong>
                                <small>{{ __('sales.monthly_offer_hint') }}</small>
                            </span>
                            <label class="ds-switch sq-section__switch" title="{{ __('sales.monthly_offer') }}">
                                <input type="checkbox" checked data-sq-monthly-on aria-label="{{ __('sales.monthly_offer') }}">
                                <span class="ds-switch__track" aria-hidden="true"></span>
                            </label>
                        </header>
                        <div class="sq-section__body" data-sq-monthly-body>
                            @if($base)
                                <div class="sq-row is-base" data-sq-base="{{ $base['id'] }}">
                                    <span class="sq-row__check is-on"><x-ds.icon name="check" /></span>
                                    <span class="sq-row__name">{{ $base['name'] }}<small>{{ __('sales.base_included') }}</small></span>
                                    <b class="sq-row__price ds-num">{{ FinanceMoney::format($base['price']) }}</b>
                                </div>
                            @endif
                            <p class="sq-section__label">{{ __('sales.add_features') }}</p>
                            @foreach($catalog['feature'] as $feature)
                                <label class="sq-row sq-row--pick">
                                    <input type="checkbox" value="{{ $feature['id'] }}" data-sq-feature>
                                    <span class="sq-row__check"><x-ds.icon name="check" /></span>
                                    <span class="sq-row__name">{{ $feature['name'] }}</span>
                                    <b class="sq-row__price ds-num">+{{ FinanceMoney::format($feature['price']) }}</b>
                                </label>
                            @endforeach
                        </div>
                        <p class="sq-section__off" data-sq-monthly-off hidden>{{ __('sales.monthly_off') }}</p>
                        <footer class="sq-section__foot">
                            <span>{{ __('sales.monthly_total') }}</span>
                            <b class="ds-num" data-sq-total="monthly">₪0</b>
                        </footer>
                    </section>

                    <section class="sq-section" data-sq-devices>
                        <header class="sq-section__head">
                            <span class="sq-section__icon">@include('components.workspace.icons', ['name' => 'box'])</span>
                            <span class="sq-section__title">
                                <strong>{{ __('sales.devices_offer') }}</strong>
                                <small>{{ __('sales.devices_offer_hint') }}</small>
                            </span>
                        </header>
                        <div class="sq-section__body">
                            @forelse($catalog['equipment'] as $device)
                                <div class="sq-row" data-sq-device="{{ $device['id'] }}">
                                    <span class="sq-row__name">
                                        {{ $device['name'] }}
                                        @if($device['stock'] !== null)
                                            <small class="{{ $device['stock'] <= 0 ? 'is-out' : '' }}">{{ $device['stock'] <= 0 ? __('sales.out_of_stock') : __('sales.in_stock').': '.$device['stock'] }}</small>
                                        @endif
                                    </span>
                                    <b class="sq-row__price ds-num">{{ FinanceMoney::format($device['price']) }}</b>
                                    <span class="sq-stepper">
                                        <button type="button" class="ds-btn ds-btn--secondary ds-btn--icon ds-btn--sm" data-step="-1" aria-label="-1"><x-ds.icon name="minus" /></button>
                                        <output class="ds-num" data-qty>0</output>
                                        <button type="button" class="ds-btn ds-btn--secondary ds-btn--icon ds-btn--sm" data-step="1" aria-label="+1"><x-ds.icon name="plus" /></button>
                                    </span>
                                </div>
                            @empty
                                <p class="sq-section__off">{{ __('sales.catalog.empty') }}</p>
                            @endforelse
                        </div>
                        <footer class="sq-section__foot">
                            <span>{{ __('sales.one_time_total') }}</span>
                            <b class="ds-num" data-sq-total="one_time">₪0</b>
                        </footer>
                    </section>
                </div>

                <details class="sq-note" @if($old['notes'] ?? false) open @endif>
                    <summary>{{ __('sales.add_note') }}</summary>
                    <textarea class="ds-input ds-textarea" name="notes" rows="2" maxlength="2000" aria-label="{{ __('sales.notes') }}"></textarea>
                </details>
            </div>

            <footer class="ds-dialog__foot sq-builder__foot">
                <span class="sq-builder__empty" data-sq-empty>{{ __('sales.no_items') }}</span>
                <span class="ds-spacer"></span>
                <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('sales.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary" data-sq-save>{{ __('sales.save') }}</button>
            </footer>
        </form>
    </dialog>
    <script>
        window.SALES_BOOT = {
            catalog: @json($catalog),
            editing: @json($old),
            reopen: @json($old !== null),
        };
    </script>
@endpush
