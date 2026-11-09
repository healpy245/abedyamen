@extends('workspace.finance.layout')

@php
    use App\Enums\Finance\StorageCategory;
    use App\Support\FinanceMoney;
    $categoryOptions = collect(StorageCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $reasonOptions = collect(['purchase', 'return', 'adjust'])->mapWithKeys(fn ($r) => [$r => __('finance.storage.reasons.'.$r)])->all();
    $filtered = $search !== '' || $filters['category'] !== '' || $filters['low'];
@endphp

@section('content')
    <div class="ds-page fs-page">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('finance.storage.title') }}</h1>
            </div>
            <button type="button" class="ds-btn ds-btn--primary" data-fin-item-new>
                <x-ds.icon name="plus" />
                {{ __('finance.storage.new_item') }}
            </button>
        </header>

        <section class="ds-stats ds-card">
            <div class="ds-stat">
                <span class="ds-label">{{ __('finance.storage.items') }}</span>
                <strong class="ds-num">{{ $stats['items'] }}</strong>
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('finance.storage.units') }}</span>
                <strong class="ds-num">{{ number_format($stats['units']) }}</strong>
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('finance.storage.value') }}</span>
                <strong class="ds-num">{{ FinanceMoney::format($stats['value']) }}</strong>
            </div>
            <a class="ds-stat ds-stat--link" href="{{ route('workspace.finance.storage', ['low' => 1]) }}">
                <span class="ds-label">{{ __('finance.storage.low') }}</span>
                <strong class="ds-num {{ $stats['low'] > 0 ? 'is-warn' : '' }}">{{ $stats['low'] }}</strong>
                <small class="ds-muted">{{ __('finance.dashboard.see_all') }} <x-ds.icon name="chevron" class="ds-arrow" /></small>
            </a>
        </section>

        <form class="ds-toolbar" method="get" action="{{ route('workspace.finance.storage') }}" data-no-loading>
            <div class="ds-affix ds-toolbar__search">
                <span class="ds-affix__lead"><x-ds.icon name="search" /></span>
                <input class="ds-input" type="search" name="q" value="{{ $search }}"
                       placeholder="{{ __('finance.storage.search') }}"
                       aria-label="{{ __('finance.storage.search') }}"
                       data-fin-search>
            </div>
            <x-ds.select name="category" :options="$categoryOptions" :value="$filters['category']" :placeholder="__('finance.storage.all_categories')" :label="__('finance.storage.category')" auto wrap="ds-toolbar__select" />
            <label class="ds-chip">
                <input type="checkbox" name="low" value="1" @checked($filters['low']) data-fin-autosubmit>
                <span>{{ __('finance.storage.only_low') }}</span>
            </label>
            @if($filtered)
                <a class="ds-btn ds-btn--ghost" href="{{ route('workspace.finance.storage') }}">
                    <x-ds.icon name="x" />
                    {{ __('finance.clients.clear') }}
                </a>
            @endif
        </form>

        <div class="fs-layout">
            <div class="ds-table-wrap fs-table">
                <table class="ds-table">
                    <thead>
                        <tr>
                            <th>{{ __('finance.storage.name') }}</th>
                            <th>{{ __('finance.storage.category') }}</th>
                            <th class="is-num">{{ __('finance.storage.unit_price') }}</th>
                            <th>{{ __('finance.storage.stock') }}</th>
                            <th class="fs-col-actions"><span class="ds-sr-only">{{ __('finance.storage.edit') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $item)
                            @php
                                $fill = $item->track_stock ? min(100, $item->stock / max(1, $item->low_stock * 3) * 100) : 0;
                                $tone = ! $item->track_stock ? null : ($item->stock <= 0 ? 'red' : ($item->isLow() ? 'amber' : 'green'));
                            @endphp
                            <tr>
                                <td>
                                    <span class="ds-identity__text">
                                        <strong>{{ $item->name }}</strong>
                                        @if($item->sku)
                                            <small><bdi dir="ltr">{{ $item->sku }}</bdi></small>
                                        @endif
                                    </span>
                                </td>
                                <td><x-ds.badge soft :dot="false">{{ $item->category->label() }}</x-ds.badge></td>
                                <td class="is-num">{{ FinanceMoney::format($item->unit_price) }}</td>
                                <td>
                                    @if($item->track_stock)
                                        <span class="fs-stock" data-tone="{{ $tone }}">
                                            <b class="ds-num">{{ $item->stock }}</b>
                                            <span class="fs-stock__bar" aria-hidden="true"><i style="width: {{ round($fill) }}%"></i></span>
                                        </span>
                                    @else
                                        <span class="ds-muted">{{ __('finance.storage.untracked') }}</span>
                                    @endif
                                </td>
                                <td class="fs-col-actions">
                                    <div class="fs-actions">
                                        @if($item->track_stock)
                                            <button type="button" class="ds-btn ds-btn--secondary ds-btn--sm"
                                                    data-fin-adjust="{{ route('workspace.finance.storage.adjust', $item) }}"
                                                    data-name="{{ $item->name }}"
                                                    data-stock="{{ $item->stock }}">
                                                {{ __('finance.storage.adjust') }}
                                            </button>
                                        @endif
                                        <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm"
                                                data-fin-item-edit="{{ route('workspace.finance.storage.update', $item) }}"
                                                data-item="{{ json_encode(['name' => $item->name, 'name_ar' => $item->name_ar, 'name_en' => $item->name_en, 'sku' => $item->sku, 'category' => $item->category->value, 'unit_price' => (float) $item->unit_price, 'track_stock' => $item->track_stock, 'low_stock' => $item->low_stock]) }}"
                                                aria-label="{{ __('finance.storage.edit') }}" data-tip="{{ __('finance.storage.edit') }}">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/></svg>
                                        </button>
                                        <form method="post" action="{{ route('workspace.finance.storage.destroy', $item) }}" data-fin-confirm="{{ __('finance.storage.remove_confirm') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm ds-btn--danger" aria-label="{{ __('finance.storage.remove') }}" data-tip="{{ __('finance.storage.remove') }}">
                                                <x-ds.icon name="trash" />
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="ds-empty-cell">
                                    <x-ds.empty :title="__('finance.storage.empty')" icon="receipt">
                                        @if($filtered){{ __('finance.clients.empty_hint') }}@endif
                                    </x-ds.empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <aside class="ds-card ds-section fs-moves">
                <header class="ds-section__head">
                    <h2>{{ __('finance.storage.recent') }}</h2>
                </header>
                @forelse($movements as $move)
                    <div class="fs-move">
                        <span class="fs-move__delta ds-num {{ $move->change > 0 ? 'is-in' : 'is-out' }}" dir="ltr">{{ $move->change > 0 ? '+' : '−' }}{{ abs($move->change) }}</span>
                        <span class="fs-move__text">
                            <strong>{{ $move->item?->name }}</strong>
                            <small>{{ __('finance.storage.reasons.'.$move->reason) }}@if($move->invoice) · <bdi dir="ltr">#{{ $move->invoice->displayNumber() }}</bdi>@endif · {{ $move->created_at?->diffForHumans() }}</small>
                        </span>
                    </div>
                @empty
                    <x-ds.empty :title="__('finance.dashboard.empty')" icon="calendar" />
                @endforelse
            </aside>
        </div>
    </div>
@endsection

@push('dialogs')
    <dialog class="ds-dialog fs-dialog" data-fin-item-dialog aria-labelledby="fin-item-title">
        <form method="post" action="{{ route('workspace.finance.storage.store') }}" class="fs-dialog__form" data-fin-item-form>
            @csrf
            <input type="hidden" name="_method" value="POST" data-fin-item-method>
            <header class="ds-dialog__head fs-dialog__head">
                <h2 id="fin-item-title" data-new="{{ __('finance.storage.new_item') }}" data-edit="{{ __('finance.storage.edit_item') }}">{{ __('finance.storage.new_item') }}</h2>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('finance.modal.close') }}"><x-ds.icon name="x" /></button>
            </header>
            <div class="ds-dialog__body fs-dialog__body">
                <x-ds.field :label="__('finance.storage.name')" for="fs-name">
                    <input id="fs-name" class="ds-input" type="text" name="name" required maxlength="191">
                </x-ds.field>
                <div class="fcm2__grid">
                    <x-ds.field :label="__('finance.storage.name_ar')" for="fs-name-ar">
                        <input id="fs-name-ar" class="ds-input" type="text" name="name_ar" maxlength="191" dir="rtl" lang="ar">
                    </x-ds.field>
                    <x-ds.field :label="__('finance.storage.name_en')" for="fs-name-en">
                        <input id="fs-name-en" class="ds-input" type="text" name="name_en" maxlength="191" dir="ltr" lang="en">
                    </x-ds.field>
                </div>
                <div class="fcm2__grid">
                    <x-ds.field :label="__('finance.storage.sku')" for="fs-sku">
                        <input id="fs-sku" class="ds-input" type="text" name="sku" maxlength="60" dir="ltr">
                    </x-ds.field>
                    <x-ds.field :label="__('finance.storage.category')">
                        <x-ds.select name="category" :options="$categoryOptions" value="hardware" :label="__('finance.storage.category')" />
                    </x-ds.field>
                    <x-ds.field :label="__('finance.storage.unit_price')" for="fs-price">
                        <div class="ds-affix">
                            <span class="ds-affix__lead">₪</span>
                            <input id="fs-price" class="ds-input ds-num" type="number" name="unit_price" min="0" step="0.01" value="0" required dir="ltr">
                        </div>
                    </x-ds.field>
                    <x-ds.field :label="__('finance.storage.low_stock')" for="fs-low">
                        <input id="fs-low" class="ds-input ds-num" type="number" name="low_stock" min="0" step="1" value="2" dir="ltr">
                    </x-ds.field>
                    <x-ds.field :label="__('finance.storage.opening_stock')" for="fs-open" data-fin-opening>
                        <input id="fs-open" class="ds-input ds-num" type="number" name="stock" min="0" step="1" value="0" dir="ltr">
                    </x-ds.field>
                </div>
                <label class="ds-switch fcm2__switch">
                    <span class="ds-switch__text"><strong>{{ __('finance.storage.track_stock') }}</strong></span>
                    <input type="checkbox" name="track_stock" value="1" checked>
                    <span class="ds-switch__track" aria-hidden="true"></span>
                </label>
            </div>
            <footer class="ds-dialog__foot">
                <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('finance.modal.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary">{{ __('finance.modal.save') }}</button>
            </footer>
        </form>
    </dialog>

    <dialog class="ds-dialog fs-dialog fs-dialog--sm" data-fin-adjust-dialog aria-labelledby="fin-adjust-title">
        <form method="post" action="#" class="fs-dialog__form" data-fin-adjust-form>
            @csrf
            <header class="ds-dialog__head fs-dialog__head">
                <div>
                    <h2 id="fin-adjust-title">{{ __('finance.storage.adjust_title') }}</h2>
                    <p class="ds-sub" data-fin-adjust-name></p>
                </div>
                <button type="button" class="ds-btn ds-btn--ghost ds-btn--icon" data-fin-sheet-close aria-label="{{ __('finance.modal.close') }}"><x-ds.icon name="x" /></button>
            </header>
            <div class="ds-dialog__body fs-dialog__body">
                <div class="fs-stepper">
                    <button type="button" class="ds-btn ds-btn--secondary" data-fin-step="-1" aria-label="-1"><x-ds.icon name="minus" /></button>
                    <input class="ds-input ds-num" type="number" name="change" value="1" step="1" required autofocus aria-label="{{ __('finance.storage.change') }}" dir="ltr">
                    <button type="button" class="ds-btn ds-btn--secondary" data-fin-step="1" aria-label="+1"><x-ds.icon name="plus" /></button>
                </div>
                <p class="fs-stepper__result ds-sub" data-fin-adjust-result data-label="{{ __('finance.storage.stock') }}"></p>
                <div class="fcm2__grid">
                    <x-ds.field :label="__('finance.storage.reason')">
                        <x-ds.select name="reason" :options="$reasonOptions" value="purchase" :label="__('finance.storage.reason')" />
                    </x-ds.field>
                    <x-ds.field :label="__('finance.storage.note')" for="fs-note">
                        <input id="fs-note" class="ds-input" type="text" name="note" maxlength="191">
                    </x-ds.field>
                </div>
            </div>
            <footer class="ds-dialog__foot">
                <button type="button" class="ds-btn ds-btn--secondary" data-fin-sheet-close>{{ __('finance.modal.cancel') }}</button>
                <button type="submit" class="ds-btn ds-btn--primary">{{ __('finance.modal.save') }}</button>
            </footer>
        </form>
    </dialog>
@endpush
