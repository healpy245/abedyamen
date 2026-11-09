@php $nav = 'live'; @endphp
@extends('layouts.support')

@section('title', __('kaman-support.board_title'))

@section('content')
    <div class="sup-head">
        <h1>{{ __('kaman-support.board_title') }}</h1>
        <span class="sup-live"><i></i>{{ __('kaman-support.ws_live') }}</span>
    </div>

    @php
        $counts = ['healthy' => 0, 'warning' => 0, 'offline' => 0, 'unknown' => 0, 'devices' => 0];
        foreach ($restaurants as $card) {
            $counts[$card['status']] = ($counts[$card['status']] ?? 0) + 1;
            $counts['devices'] += $card['total'];
        }
    @endphp
    <div class="sup-counts" aria-label="{{ __('kaman-support.board_title') }}">
        <button type="button" class="is-ok" data-status-filter="healthy"><b data-count="healthy">{{ $counts['healthy'] }}</b> {{ __('kaman-support.ws_online') }}</button>
        <button type="button" class="is-warn" data-status-filter="warning"><b data-count="warning">{{ $counts['warning'] }}</b> {{ __('kaman-support.ws_warning') }}</button>
        <button type="button" class="is-off" data-status-filter="offline"><b data-count="offline">{{ $counts['offline'] }}</b> {{ __('kaman-support.ws_offline') }}</button>
        <button type="button" data-status-filter="all"><b data-count="devices">{{ $counts['devices'] }}</b> {{ __('kaman-support.ws_devices') }}</button>
    </div>

    <form method="get" class="sup-tools" data-sup-tools>
        <input type="search" data-sup-search placeholder="{{ __('kaman-support.ws_search') }}" aria-label="{{ __('kaman-support.ws_search') }}">
    </form>

    <div class="sup-grid" data-sup-grid>
        @forelse($restaurants as $card)
            @php
                $seenAt = filled($card['lastSeenAt']) ? \Illuminate\Support\Carbon::parse($card['lastSeenAt']) : null;
                $recent = ($card['id'] ?? '') === '_pending'
                    || in_array($card['status'], ['healthy', 'warning'], true)
                    || ($seenAt !== null && $seenAt->greaterThan(now()->subMinutes(20)));
            @endphp
            @php
                $productLabels = [];
                foreach ($card['products'] as $product) {
                    $productLabels[] = match ($product) {
                        'mall' => __('kaman-support.ws_cashier_mall'),
                        'lite' => __('kaman-support.ws_lite'),
                        default => null,
                    };
                }
                $productLabels = array_values(array_filter($productLabels));
                $restaurantKey = in_array($card['id'] ?? '', ['_pending', 'unknown'], true)
                    ? ''
                    : app(\App\Services\KamanSupport\RestaurantSupport::class)->keyFor(auth()->user(), (string) $card['id']);
            @endphp
            <button type="button" class="sup-card" data-status="{{ $card['status'] }}" data-recent="{{ $recent ? '1' : '0' }}" data-name="{{ $card['name'] }}" data-seen="{{ $card['lastSeenAt'] }}" data-open="{{ $card['id'] }}" @if($restaurantKey !== '') data-act="open-support" data-id="{{ $restaurantKey }}" @endif data-online="{{ $card['online'] }}" data-total="{{ $card['total'] }}" @if(! $recent) hidden @endif>
                <div class="sup-card__top">
                    <span class="sup-mark" data-logo>
                        @if($card['logo'])
                            <img src="{{ $card['logo'] }}" alt="" loading="lazy">
                        @endif
                        <b>{{ mb_strtoupper(mb_substr((string) $card['name'], 0, 1)) }}</b>
                    </span>
                    <div class="sup-card__main">
                        <h2>{{ $card['name'] }}</h2>
                        <p class="sup-products" data-products dir="ltr" @if($productLabels === []) hidden @endif>{{ implode(' · ', $productLabels) }}</p>
                    </div>
                    <i class="sup-dot is-{{ $card['status'] }}" title="{{ __('kaman-support.ws_'.$card['status']) }}"></i>
                </div>
                <div class="sup-card__meta">
                    <span data-count-line>{{ trans('kaman-support.ws_device_count', ['online' => $card['online'], 'total' => $card['total']]) }}</span>
                    <span data-seen-line>{{ $card['lastSeen'] !== '' ? $card['lastSeen'] : __('kaman-support.never_seen') }}</span>
                </div>
                <p class="sup-error" data-error @if(! $card['error']) hidden @endif>{{ $card['error'] }}</p>
            </button>
        @empty
            <div class="sup-empty">{{ __('kaman-support.ws_none') }}</div>
        @endforelse
    </div>

    <div class="sup-modal" id="sup-modal" hidden>
        <div class="sup-modal__panel" role="dialog" aria-modal="true" aria-labelledby="sup-modal-title">
            <div class="sup-modal__head">
                <span class="sup-mark" id="sup-modal-logo" hidden></span>
                <h2 id="sup-modal-title"></h2>
                <button type="button" class="sup-x" data-sup-close aria-label="{{ __('kaman-support.ws_close') }}">×</button>
            </div>
            <div class="sup-seg" role="tablist">
                <button type="button" class="is-active" data-tab="overview">{{ __('kaman-support.ws_overview') }}</button>
                <button type="button" data-tab="devices">{{ __('kaman-support.ws_devices') }}</button>
                <button type="button" data-tab="logs">{{ __('kaman-support.ws_recent') }}</button>
                <button type="button" data-tab="tickets">{{ __('kaman-support.nav_tickets') }}</button>
            </div>
            <div id="sup-modal-body"></div>
        </div>
    </div>

    {{-- A dialog so the printer tools open above the restaurant modal. --}}
    <dialog class="sup-printer-layer" data-sup-printer-layer>
    <div class="sup-modal" id="sup-printers" hidden>
        <div class="sup-modal__panel" role="dialog" aria-modal="true" aria-labelledby="sup-printer-title">
            <div class="sup-modal__head">
                <button type="button" class="sup-back" data-show-printers hidden>{{ __('kaman-support.ws_back') }}</button>
                <h2 id="sup-printer-title"></h2>
                <button type="button" class="sup-ghost" data-show-settings>{{ __('kaman-support.ws_settings') }}</button>
                <button type="button" class="sup-ghost" data-show-map>{{ __('kaman-support.ws_lan_map') }}</button>
                <button type="button" class="sup-x" data-printer-close aria-label="{{ __('kaman-support.ws_close') }}">×</button>
            </div>
            <div id="sup-printer-body"></div>
            <div class="sup-assure" data-assure hidden>
                <div class="sup-assure__card">
                    <p>{{ __('kaman-support.ws_sync_sure') }}</p>
                    <div>
                        <button type="button" class="sup-ghost" data-assure-no>{{ __('kaman-support.ws_cancel') }}</button>
                        <button type="button" class="sup-primary" data-assure-yes>{{ __('kaman-support.ws_sync_api') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    </dialog>

    @php
        $openSlug = request()->query('open');
        $openKey = is_string($openSlug) && ! in_array($openSlug, ['_pending', 'unknown'], true)
            ? app(\App\Services\KamanSupport\RestaurantSupport::class)->keyFor(auth()->user(), $openSlug)
            : '';
    @endphp
    @include('workspace.partials.restaurant-modal', ['surface' => 'support', 'open' => $openKey !== '' ? ['id' => $openKey, 'tab' => 'support'] : null])

    <script type="application/json" id="sup-restaurants">@json($restaurants)</script>
    <script>
        window.SUP_BOARD = {
            open: @json(($openKey ?? '') === '' ? request()->query('open') : null),
            feed: @json($feedUrl),
            logos: @json(route('kaman-support.logos')),
            logs: @json(route('kaman-support.logs')),
            deviceFeed: @json(route('kaman-support.device.feed', ['device' => 0])),
            command: @json(route('kaman-support.device.commands', ['device' => 0])),
            inbox: @json(route('kaman-support.inbox')),
            labels: {
                last: @json(__('kaman-support.ws_last')),
                never: @json(__('kaman-support.never_seen')),
                devicesOnline: @json(__('kaman-support.ws_device_count', ['online' => ':online', 'total' => ':total'])),
                openDevice: @json(__('kaman-support.ws_open_device')),
                logs: @json(__('kaman-support.nav_logs')),
                viewAllLogs: @json(__('kaman-support.view_all_logs')),
                empty: @json(__('kaman-support.ws_empty_logs')),
                healthy: @json(__('kaman-support.ws_healthy')),
                warning: @json(__('kaman-support.ws_warning')),
                offline: @json(__('kaman-support.ws_offline')),
                unknown: @json(__('kaman-support.ws_unknown')),
                online: @json(__('kaman-support.ws_online')),
                mall: @json(__('kaman-support.ws_cashier_mall')),
                lite: @json(__('kaman-support.ws_lite')),
                printers: @json(__('kaman-support.printers')),
                settings: @json(__('kaman-support.ws_settings')),
                settingsWait: @json(__('kaman-support.ws_settings_wait')),
                settingsUnreported: @json(__('kaman-support.ws_settings_unreported')),
                printKitchenCancel: @json(__('kaman-support.ws_print_kitchen_cancel')),
                printKitchenCancelHint: @json(__('kaman-support.ws_print_kitchen_cancel_hint')),
                localZ: @json(__('kaman-support.ws_local_z')),
                localZHint: @json(__('kaman-support.ws_local_z_hint')),
                printReceipt: @json(__('kaman-support.ws_print_receipt')),
                kitchenReprint: @json(__('kaman-support.ws_kitchen_reprint')),
                kitchenReprintHint: @json(__('kaman-support.ws_kitchen_reprint_hint')),
                reprintPin: @json(__('kaman-support.ws_reprint_pin')),
                autoPrint: @json(__('kaman-support.ws_auto_print')),
                autoPrintHint: @json(__('kaman-support.ws_auto_print_hint')),
                pauseOrder: @json(__('kaman-support.ws_pause_order')),
                pauseOrderHint: @json(__('kaman-support.ws_pause_order_hint')),
                builtinKeyboard: @json(__('kaman-support.ws_builtin_keyboard')),
                builtinKeyboardHint: @json(__('kaman-support.ws_builtin_keyboard_hint')),
                pizza: @json(__('kaman-support.ws_pizza')),
                pizzaHint: @json(__('kaman-support.ws_pizza_hint')),
                pizzaMeals: @json(__('kaman-support.ws_pizza_meals')),
                pizzaMealsEmpty: @json(__('kaman-support.ws_pizza_meals_empty')),
                pizzaSearch: @json(__('kaman-support.ws_pizza_search')),
                findPrinters: @json(__('kaman-support.ws_find_printers')),
                lookingPrinters: @json(__('kaman-support.ws_looking_printers')),
                loadKitchens: @json(__('kaman-support.ws_load_kitchens')),
                savedPrinters: @json(__('kaman-support.ws_your_printers')),
                foundPrinters: @json(__('kaman-support.ws_new_printers')),
                nonePrinters: @json(__('kaman-support.ws_no_printers')),
                noneFound: @json(__('kaman-support.ws_none_found')),
                newPrinter: @json(__('kaman-support.ws_new_printer')),
                receipt: @json(__('kaman-support.ws_receipt')),
                sticker: @json(__('kaman-support.ws_sticker')),
                kitchen: @json(__('kaman-support.ws_kitchen')),
                testPrint: @json(__('kaman-support.ws_test_print')),
                remove: @json(__('kaman-support.ws_remove')),
                add: @json(__('kaman-support.ws_add')),
                connected: @json(__('kaman-support.ws_connected')),
                disconnected: @json(__('kaman-support.ws_disconnected')),
                whichKitchen: @json(__('kaman-support.ws_which_kitchen')),
                lanMap: @json(__('kaman-support.ws_lan_map')),
                lanTitle: @json(__('kaman-support.ws_lan_title')),
                lanScan: @json(__('kaman-support.ws_lan_scan')),
                lanEmpty: @json(__('kaman-support.ws_lan_empty')),
                lanScanning: @json(__('kaman-support.ws_lan_scanning')),
                back: @json(__('kaman-support.ws_back')),
                syncApi: @json(__('kaman-support.ws_sync_api')),
                moreChecks: @json(__('kaman-support.ws_more_checks')),
                pressureTest: @json(__('kaman-support.ws_pressure_test')),
                pressureHint: @json(__('kaman-support.ws_pressure_hint')),
                pressureCopies: @json(__('kaman-support.ws_pressure_copies')),
                pressureSeconds: @json(__('kaman-support.ws_pressure_seconds')),
                pressureStart: @json(__('kaman-support.ws_pressure_start')),
                pressureStop: @json(__('kaman-support.ws_pressure_stop')),
                pressureClear: @json(__('kaman-support.ws_pressure_clear')),
                pressureLog: @json(__('kaman-support.ws_pressure_log')),
                pressureIssue: @json(__('kaman-support.ws_pressure_issue')),
                pressureStats: @json(__('kaman-support.ws_pressure_stats')),
                roleTablet: @json(__('kaman-support.ws_role_tablet')),
                roleGateway: @json(__('kaman-support.ws_role_gateway')),
                roleRouter: @json(__('kaman-support.ws_role_router')),
                roleSwitch: @json(__('kaman-support.ws_role_switch')),
                roleAp: @json(__('kaman-support.ws_role_ap')),
                rolePrinter: @json(__('kaman-support.ws_role_printer')),
                roleCamera: @json(__('kaman-support.ws_role_camera')),
                roleComputer: @json(__('kaman-support.ws_role_computer')),
                roleWeb: @json(__('kaman-support.ws_role_web')),
                roleOther: @json(__('kaman-support.ws_role_other')),
                stOnline: @json(__('kaman-support.ws_st_online')),
                stPartial: @json(__('kaman-support.ws_st_partial')),
                stSaved: @json(__('kaman-support.ws_st_saved')),
                stRoute: @json(__('kaman-support.ws_st_route')),
                stInferred: @json(__('kaman-support.ws_st_inferred')),
                stOffline: @json(__('kaman-support.ws_st_offline')),
                queued: @json(__('kaman-support.flash.command_queued')),
                busy: @json(__('kaman-support.flash.command_busy')),
            }
        };
    </script>
@endsection

@push('scripts')
    <script src="{{ asset('js/support-workspace.js') }}?v={{ filemtime(public_path('js/support-workspace.js')) }}" defer></script>
@endpush
