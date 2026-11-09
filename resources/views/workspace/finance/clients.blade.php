@extends('workspace.finance.layout')

@php
    use App\Enums\Finance\PaymentStatus;
    use App\Enums\Finance\ServiceStatus;
    use App\Models\Finance\FinanceClient;
    use App\Support\FinanceMoney;
    $paymentOptions = collect(PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    $serviceOptions = collect(ServiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    $filtered = $search !== '' || $filters['payment'] !== '' || $filters['service'] !== '';
@endphp

@section('content')
    <div class="ds-page fc-page">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('finance.clients.title') }}</h1>
                <p class="ds-sub">
                    {{ __('finance.clients.summary', ['count' => $totals['all']]) }}
                    @if($totals['failed'] > 0)
                        <span class="ds-head__sep" aria-hidden="true">·</span>
                        <a class="ds-head__alert" href="{{ route('workspace.finance.clients', ['payment' => 'failed']) }}">{{ __('finance.clients.failed_summary', ['count' => $totals['failed']]) }}</a>
                    @endif
                </p>
            </div>
        </header>

        <form class="ds-toolbar" method="get" action="{{ route('workspace.finance.clients') }}" data-no-loading data-fin-clients-filters>
            <div class="ds-affix ds-toolbar__search">
                <span class="ds-affix__lead"><x-ds.icon name="search" /></span>
                <input class="ds-input" type="search" name="q" value="{{ $search }}"
                       placeholder="{{ __('finance.clients.search') }}"
                       aria-label="{{ __('finance.clients.search') }}"
                       data-fin-search>
            </div>
            <x-ds.select name="payment" :options="$paymentOptions" :value="$filters['payment']" :placeholder="__('finance.clients.all_payment')" :label="__('finance.clients.col_payment')" auto wrap="ds-toolbar__select" />
            <x-ds.select name="service" :options="$serviceOptions" :value="$filters['service']" :placeholder="__('finance.clients.all_service')" :label="__('finance.clients.col_service')" auto wrap="ds-toolbar__select" />
            <a class="ds-btn ds-btn--ghost" href="{{ route('workspace.finance.clients') }}" data-fin-clear @if(! $filtered) hidden @endif>
                <x-ds.icon name="x" />
                {{ __('finance.clients.clear') }}
            </a>
            <span class="ds-toolbar__count ds-num" aria-live="polite" data-fin-count>{{ count($shown) }}</span>
        </form>

        <div class="ds-table-wrap fc-table">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th>{{ __('finance.clients.col_name') }}</th>
                        <th class="is-num">{{ __('finance.clients.col_fee') }}</th>
                        <th>{{ __('finance.clients.col_service') }}</th>
                        <th>{{ __('finance.clients.col_payment') }}</th>
                        <th lang="he">{{ __('finance.clients.col_method') }}</th>
                        <th class="fc-col-hw">{{ __('finance.clients.col_hardware') }}</th>
                        <th class="fc-col-go" aria-hidden="true"></th>
                    </tr>
                </thead>
                <tbody data-fin-clients>
                    @foreach($clients as $client)
                        <tr data-act="open-finance" data-id="{{ \App\Services\Finance\RestaurantFinance::key($client) }}"
                            data-fin-row
                            data-search="{{ $searchText[$client->id] }}"
                            data-payment="{{ $client->payment_status->value }}"
                            data-service="{{ $client->service_status->value }}"
                            @if(! isset($shown[$client->id])) hidden @endif
                            tabindex="0"
                            aria-label="{{ __('finance.clients.open', ['name' => $client->name]) }}">
                            <td>
                                <span class="ds-identity">
                                    <x-ds.avatar :src="$client->logo" :name="$client->name" />
                                    <span class="ds-identity__text">
                                        <strong>{{ $client->name }}</strong>
                                        @if($client->subdomain)
                                            <small><bdi dir="ltr">{{ $client->subdomain }}.kaman.rest</bdi></small>
                                        @endif
                                        @if($client->linkedClients->isNotEmpty())
                                            {{-- Restaurants sharing this MFITGROUP account. --}}
                                            <small class="fc-linked">+ {{ $client->linkedClients->pluck('name')->join('، ') }}</small>
                                        @endif
                                    </span>
                                </span>
                            </td>
                            <td class="is-num">
                                @if($client->accountMonthlyFee() > 0)
                                    {{ FinanceMoney::format($client->accountMonthlyFee()) }}
                                @else
                                    <span class="ds-muted">—</span>
                                @endif
                            </td>
                            <td><x-ds.badge plain :tone="$client->service_status->badgeTone()">{{ $client->service_status->label() }}</x-ds.badge></td>
                            <td>
                                <span class="fc-pay">
                                    <x-ds.badge :plain="$client->payment_status !== PaymentStatus::Failed"
                                                :soft="$client->payment_status === PaymentStatus::Failed"
                                                :tone="$client->payment_status->badgeTone()">{{ $client->payment_status->label() }}</x-ds.badge>
                                    @if($client->payment_status === PaymentStatus::Failed)
                                        <span class="fin-wa" data-fin-wa>
                                            <button type="button"
                                                    class="ds-btn ds-btn--ghost ds-btn--icon ds-btn--sm fc-wa"
                                                    data-fin-wa-toggle
                                                    aria-expanded="false"
                                                    aria-label="{{ __('finance.clients.notify') }}"
                                                    data-tip="{{ $client->failed_notice_at ? __('finance.clients.notified', ['when' => $client->failed_notice_at->diffForHumans()]) : __('finance.clients.notify') }}">
                                                <x-ds.icon name="whatsapp" />
                                                @if($client->failed_notice_at)
                                                    <i class="fc-wa__sent" aria-hidden="true"></i>
                                                @endif
                                            </button>
                                            <form method="post" action="{{ route('workspace.finance.clients.notify', $client) }}" class="fc-wa__menu" data-fin-wa-menu hidden>
                                                @csrf
                                                <p>{{ __('finance.clients.notify_title') }}</p>
                                                @foreach(FinanceClient::LANGUAGES as $lang)
                                                    <button type="submit" name="lang" value="{{ $lang }}" class="ds-menu__item" @if($client->lang === $lang) aria-selected="true" @endif>
                                                        <span>{{ __('finance.languages.'.$lang) }}</span>
                                                        <x-ds.icon name="send" />
                                                    </button>
                                                @endforeach
                                            </form>
                                        </span>
                                    @endif
                                </span>
                            </td>
                            <td>
                                @if($client->has_payment_method)
                                    <span class="fc-method is-yes" data-tip="{{ __('finance.clients.method_yes') }}"><x-ds.icon name="check" /><span>{{ __('finance.clients.method_yes') }}</span></span>
                                @else
                                    <span class="fc-method" data-tip="{{ __('finance.clients.method_no') }}"><x-ds.icon name="minus" /><span>{{ __('finance.clients.method_no') }}</span></span>
                                @endif
                            </td>
                            <td class="fc-col-hw"><x-ds.badge plain :tone="$client->hardware_status->badgeTone()">{{ $client->hardware_status->label() }}</x-ds.badge></td>
                            <td class="fc-col-go"><x-ds.icon name="chevron" class="ds-row-chevron" /></td>
                        </tr>
                    @endforeach
                    <tr data-fin-empty @if(count($shown) > 0) hidden @endif>
                        <td colspan="7" class="ds-empty-cell">
                            <x-ds.empty :title="__('finance.clients.empty')" icon="users">
                                @if($clients->isNotEmpty()){{ __('finance.clients.empty_hint') }}@endif
                            </x-ds.empty>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/agents-workspace.css') }}?v={{ filemtime(public_path('css/agents-workspace.css')) }}">
@endpush

{{-- A client opens in the Overview door's restaurant modal, on its Finance section. --}}
@push('dialogs')
    <dialog id="ag-portal" class="ag-portal"></dialog>
    <div id="ag-toast" class="ag-toast" hidden></div>
    @if($modal)
        {{-- After a save or a failed form, the section comes back with its messages and what was typed. --}}
        <template data-fin-embed-initial data-client="{{ $modal['client']->id }}" data-tab="{{ $modal['tab'] }}">
            @include('workspace.finance.client-modal', $modal)
        </template>
    @endif
@endpush

@push('scripts')
    <script>
        window.AGENTS_BOOT = {{ Illuminate\Support\Js::from($agentsBoot + ['page' => 'clients', 'surface' => 'finance', 'user' => auth()->user()->name, 'locale' => app()->getLocale(), 'images' => asset('images/agents'), 'stats' => (object) [], 'activity' => [], 'links' => (object) [], 'copy' => (object) []]) }};
    </script>
    <script src="{{ asset('js/agents-workspace.js') }}?v={{ filemtime(public_path('js/agents-workspace.js')) }}" defer></script>
@endpush
