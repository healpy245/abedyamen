@extends('workspace.agents.layout')

@php
    use App\Support\FinanceMoney;
@endphp

@section('content')
    <div class="ds-page sq-page">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('sales.catalog.title') }}</h1>
                <p class="ds-sub">{{ __('sales.catalog.subtitle') }}</p>
            </div>
        </header>

        <nav class="ds-seg sq-status" aria-label="{{ __('sales.catalog.title') }}">
            @foreach(\App\Http\Controllers\Sales\CatalogController::TABS as $key)
                <a href="{{ route('workspace.agents.packages', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>{{ __('sales.catalog.tabs.'.$key) }}</a>
            @endforeach
        </nav>
        <p class="ds-muted sq-vat">{{ __('sales.catalog.no_vat') }}</p>

        @if($tab === 'features')
            @if($base)
                <article class="ds-card sq-offer sq-offer--base">
                    <div class="sq-offer__row">
                        <div>
                            <span class="sq-offer__flag sq-offer__flag--inline">{{ __('sales.base_included') }}</span>
                            <h2>{{ $base->localName() }}</h2>
                        </div>
                        <div class="sq-offer__price"><b class="ds-num">{{ FinanceMoney::format($base->price) }}</b><small>/{{ __('sales.catalog.per_'.$base->billing) }}</small></div>
                    </div>
                    <ul class="sq-offer__checks">
                        @foreach($base->bulletList() as $bullet)
                            <li><x-ds.icon name="check" />{{ $bullet }}</li>
                        @endforeach
                    </ul>
                </article>
            @endif
            <div class="sq-offers sq-offers--list">
                @forelse($features as $item)
                    <article class="ds-card sq-offer sq-offer--feature">
                        <div class="sq-offer__row">
                            <h2>{{ $item->localName() }}</h2>
                            <div class="sq-offer__price"><b class="ds-num">+{{ FinanceMoney::format($item->price) }}</b><small>/{{ __('sales.catalog.per_'.$item->billing) }}</small></div>
                        </div>
                        @if($item->note)<p class="ds-muted sq-offer__note">* {{ $item->note }}</p>@endif
                        <ul class="sq-offer__bullets">
                            @foreach($item->bulletList() as $bullet)
                                <li>{{ $bullet }}</li>
                            @endforeach
                        </ul>
                    </article>
                @empty
                    <x-ds.empty :title="__('sales.catalog.empty')" icon="receipt" />
                @endforelse
            </div>
        @else
            <div class="ds-table-wrap">
                <table class="ds-table">
                    <thead>
                        <tr>
                            <th>{{ __('sales.catalog.item') }}</th>
                            <th class="is-num">{{ __('sales.price') }}</th>
                            <th>{{ __('sales.in_stock') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($equipment as $item)
                            <tr>
                                <td>
                                    <span class="ds-identity__text">
                                        <strong>{{ $item->localName() }}</strong>
                                        @if($item->sku)<small><bdi dir="ltr">{{ $item->sku }}</bdi></small>@endif
                                    </span>
                                </td>
                                <td class="is-num">{{ FinanceMoney::format($item->unit_price) }}</td>
                                <td>
                                    @if($item->track_stock)
                                        <x-ds.badge :tone="$item->stock <= 0 ? 'red' : ($item->isLow() ? 'amber' : 'green')" soft>{{ $item->stock <= 0 ? __('sales.out_of_stock') : $item->stock }}</x-ds.badge>
                                    @else
                                        <span class="ds-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="ds-empty-cell"><x-ds.empty :title="__('sales.catalog.empty')" icon="receipt" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
