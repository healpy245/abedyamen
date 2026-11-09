@extends('workspace.finance.layout')

@php
    use App\Support\FinanceMoney;
    $mixTotal = max(1, collect($dash['payment_mix'])->sum('count'));
@endphp

@section('content')
    <div class="ds-page fd-page">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('finance.title') }}</h1>
                <p class="ds-sub">{{ now()->translatedFormat('F Y') }}</p>
            </div>
        </header>

        <section class="ds-stats ds-card" aria-label="{{ __('finance.nav.dashboard') }}">
            <div class="ds-stat">
                <span class="ds-label">{{ __('finance.dashboard.mrr') }}</span>
                <strong class="ds-num">{{ FinanceMoney::format($dash['mrr']) }}</strong>
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('finance.dashboard.collected') }}</span>
                <strong class="ds-num">{{ FinanceMoney::format($dash['collected']) }}</strong>
                @if($dash['delta'] !== null)
                    <small class="ds-delta {{ $dash['delta'] >= 0 ? 'is-up' : 'is-down' }}">
                        <span dir="ltr">{{ $dash['delta'] >= 0 ? '+' : '−' }}{{ number_format(abs($dash['delta']), 1) }}%</span>
                        {{ __('finance.dashboard.vs_last') }}
                    </small>
                @endif
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('finance.dashboard.active_clients') }}</span>
                <strong class="ds-num">{{ $dash['active'] }}</strong>
                <small class="ds-muted">{{ __('finance.dashboard.of_total', ['total' => $dash['total']]) }}</small>
            </div>
            <a class="ds-stat ds-stat--link" href="{{ route('workspace.finance.clients', ['payment' => 'failed']) }}">
                <span class="ds-label">{{ __('finance.dashboard.failed') }}</span>
                <strong class="ds-num {{ $dash['failed'] > 0 ? 'is-bad' : '' }}">{{ $dash['failed'] }}</strong>
                <small class="ds-muted">{{ __('finance.dashboard.see_all') }} <x-ds.icon name="chevron" class="ds-arrow" /></small>
            </a>
        </section>

        <div class="fd-grid">
            <section class="ds-card ds-section ds-section--wide">
                <header class="ds-section__head">
                    <h2>{{ __('finance.dashboard.income') }}</h2>
                </header>
                <div class="fd-chart" role="img" aria-label="{{ __('finance.dashboard.income') }}">
                    @foreach($dash['trend'] as $point)
                        <div class="fd-chart__col {{ $point['current'] ? 'is-current' : '' }}" tabindex="0">
                            <span class="fd-chart__tip ds-num">{{ FinanceMoney::format($point['value']) }}</span>
                            <span class="fd-chart__track">
                                <i style="height: {{ $point['value'] > 0 ? max(3, round($point['value'] / $dash['trend_max'] * 100, 1)) : 0 }}%"></i>
                            </span>
                            <small>{{ $point['label'] }}</small>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="ds-card ds-section">
                <header class="ds-section__head">
                    <h2>{{ __('finance.dashboard.payment_mix') }}</h2>
                </header>
                <div class="fd-mix" aria-hidden="true">
                    @foreach($dash['payment_mix'] as $row)
                        @if($row['count'] > 0)
                            <i data-tone="{{ $row['status']->badgeTone() ?? 'neutral' }}" style="flex: {{ $row['count'] / $mixTotal }}"></i>
                        @endif
                    @endforeach
                </div>
                <ul class="fd-legend" role="list">
                    @foreach($dash['payment_mix'] as $row)
                        <li>
                            <a href="{{ route('workspace.finance.clients', ['payment' => $row['status']->value]) }}">
                                <x-ds.badge plain :tone="$row['status']->badgeTone()">{{ $row['status']->label() }}</x-ds.badge>
                                <b class="ds-num">{{ $row['count'] }}</b>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <dl class="fd-flags">
                    <div><dt>{{ __('finance.dashboard.no_method') }}</dt><dd class="ds-num">{{ $dash['no_method'] }}</dd></div>
                    <div><dt>{{ __('finance.dashboard.hardware_due') }}</dt><dd class="ds-num">{{ $dash['hardware_due'] }}</dd></div>
                </dl>
            </section>

            <section class="ds-card ds-section ds-section--wide">
                <header class="ds-section__head">
                    <h2>{{ __('finance.dashboard.recent') }}</h2>
                    <a class="ds-btn ds-btn--ghost ds-btn--sm" href="{{ route('workspace.finance.clients') }}">{{ __('finance.dashboard.see_all') }}</a>
                </header>
                @forelse($dash['recent'] as $invoice)
                    <a class="ds-row" href="{{ route('workspace.finance.invoices.show', $invoice) }}" target="_blank" rel="noopener">
                        <x-ds.avatar :src="$invoice->client?->logo" :name="$invoice->client?->name" />
                        <span class="ds-row__main">{{ $invoice->client?->name }}</span>
                        <x-ds.badge plain :tone="$invoice->type->badgeTone()" lang="he" class="ds-row__type">{{ $invoice->type->label() }}</x-ds.badge>
                        <span class="ds-row__meta ds-num">{{ $invoice->issued_on->format('d.m') }}</span>
                        <b class="ds-num">{{ FinanceMoney::format($invoice->type->incomeSign() < 0 ? -$invoice->total : $invoice->total) }}</b>
                    </a>
                @empty
                    <x-ds.empty :title="__('finance.dashboard.empty')" icon="receipt" />
                @endforelse
            </section>

            <section class="ds-card ds-section">
                <header class="ds-section__head">
                    <h2>{{ __('finance.dashboard.low_stock') }}</h2>
                    <a class="ds-btn ds-btn--ghost ds-btn--sm" href="{{ route('workspace.finance.storage', ['low' => 1]) }}">{{ __('finance.dashboard.see_all') }}</a>
                </header>
                @forelse($dash['low_stock'] as $item)
                    <div class="ds-row ds-row--static">
                        <span class="ds-row__main">{{ $item->name }}</span>
                        <x-ds.badge plain :tone="$item->stock <= 0 ? 'red' : 'amber'" class="ds-num">{{ $item->stock }}</x-ds.badge>
                    </div>
                @empty
                    <x-ds.empty :title="__('finance.dashboard.all_good')" icon="check" />
                @endforelse
            </section>
        </div>
    </div>
@endsection
