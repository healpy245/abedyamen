@php
    $nav = 'logs';
    $keep = request()->except('lane');
    $rangeLabel = [
        '15m' => __('kaman-support.ws_last_15'),
        '1h' => __('kaman-support.ws_last_hour'),
        'today' => __('kaman-support.ws_today'),
        '7d' => __('kaman-support.ws_last_week'),
    ][$range] ?? '';
    $choiceLabels = [];
    foreach ($choices as $choice) {
        $choiceLabels[$choice['value']] = $choice['label'];
    }
    $restaurantLabel = __('kaman-support.ws_restaurants');
    if (count($picked) === 1) {
        $restaurantLabel = $choiceLabels[$picked[0]] ?? $picked[0];
    }
    $timeLabel = $rangeLabel !== '' ? $rangeLabel : (($filters['from'] !== '' || $filters['to'] !== '') ? trim($filters['from'].' – '.$filters['to']) : __('kaman-support.ws_time'));
    $fromRaw = $range === '' ? (string) $filters['from'] : '';
    $toRaw = $range === '' ? (string) $filters['to'] : '';
    $fromDate = strlen($fromRaw) >= 10 ? substr($fromRaw, 0, 10) : '';
    $toDate = strlen($toRaw) >= 10 ? substr($toRaw, 0, 10) : '';
    $fromHour = preg_match('/\b(\d{2}):\d{2}/', $fromRaw, $fromHourMatch) === 1 ? $fromHourMatch[1] : '';
    $toHour = preg_match('/\b(\d{2}):\d{2}/', $toRaw, $toHourMatch) === 1 ? $toHourMatch[1] : '';
    $advancedKeys = ($lane ?? 'device') === 'server'
        ? ['integration', 'http']
        : ['severity', 'category', 'device'];
    $advanced = count(array_filter(
        array_intersect_key($client, array_flip($advancedKeys)),
        static fn ($value) => $value !== '',
    ));
    $levelLabels = [
        'error' => __('kaman-support.ws_level_error'),
        'warn' => __('kaman-support.ws_warning'),
        'info' => __('kaman-support.ws_level_info'),
        'ok' => __('kaman-support.ws_healthy'),
    ];
    $categoryLabels = [
        'flutter' => __('kaman-support.ws_flutter_short'),
        'api' => 'API',
        'print' => __('kaman-support.ws_print_short'),
        'network' => __('kaman-support.ws_network'),
    ];
    $integrationLabels = ['alram' => 'Alram', 'pelecard' => 'Pelecard', 'haat' => 'HAAT', 'shoofi' => 'Shoofi'];
    $labels = [
        'overview' => __('kaman-support.ws_overview'),
        'details' => __('kaman-support.ws_inv_details'),
        'occurrences' => __('kaman-support.ws_inv_occurrences_tab'),
        'technical' => __('kaman-support.ws_inv_technical'),
        'first' => __('kaman-support.ws_inv_first_seen'),
        'last' => __('kaman-support.ws_inv_last_seen'),
        'affected' => __('kaman-support.ws_inv_affected'),
        'devices' => __('kaman-support.ws_inv_devices'),
        'occurrencesCount' => __('kaman-support.ws_inv_occurrences'),
        'http' => 'HTTP',
        'source' => __('kaman-support.ws_source'),
        'unavailable' => __('kaman-support.ws_inv_unavailable'),
        'message' => __('kaman-support.ws_inv_message'),
        'endpoint' => __('kaman-support.ws_inv_endpoint'),
        'body' => __('kaman-support.ws_inv_body'),
        'restaurant' => __('kaman-support.restaurant'),
        'device' => __('kaman-support.device'),
        'copy' => __('kaman-support.ws_inv_copy_json'),
        'copied' => __('kaman-support.ws_inv_copied'),
        'raw' => __('kaman-support.ws_inv_raw'),
        'stack' => __('kaman-support.ws_inv_stack'),
        'exchange' => __('kaman-support.ws_inv_request'),
        'meta' => __('kaman-support.ws_inv_meta'),
        'requestId' => __('kaman-support.ws_inv_request_id'),
        'select' => __('kaman-support.ws_inv_select'),
        'pending' => __('kaman-support.ws_inv_pending'),
        'related' => __('kaman-support.ws_inv_related'),
        'levels' => $levelLabels,
        'categories' => $categoryLabels,
        'integrations' => $integrationLabels,
        'mall' => __('kaman-support.ws_cashier_mall'),
        'lite' => __('kaman-support.ws_lite'),
        'severity' => __('kaman-support.ws_severity'),
        'category' => __('kaman-support.ws_inv_category'),
        'integration' => __('kaman-support.ws_inv_integration'),
        'status' => __('kaman-support.ws_inv_status'),
    ];
    $clientChips = [];
    if ($client['severity'] !== '') {
        $clientChips[] = ['key' => 'severity', 'label' => $levelLabels[$client['severity']] ?? $client['severity']];
    }
    if ($client['category'] !== '') {
        $clientChips[] = ['key' => 'category', 'label' => $categoryLabels[$client['category']] ?? $client['category']];
    }
    if ($client['integration'] !== '') {
        $clientChips[] = ['key' => 'integration', 'label' => $integrationLabels[$client['integration']] ?? $client['integration']];
    }
    if ($client['device'] !== '') {
        $clientChips[] = ['key' => 'device', 'label' => $client['device']];
    }
    if ($client['http'] !== '') {
        $clientChips[] = ['key' => 'http', 'label' => 'HTTP '.$client['http']];
    }
@endphp
@extends('layouts.support')

@section('title', __('kaman-support.ws_logs_title'))

@push('head')
    <link rel="stylesheet" href="{{ asset('css/support-logs.css') }}?v={{ filemtime(public_path('css/support-logs.css')) }}">
@endpush

@section('content')
    <div class="sup-inv" data-inv data-logos="{{ route('kaman-support.logos') }}">
        <h1 class="sup-sr">{{ __('kaman-support.ws_logs_title') }}</h1>

        <form class="sup-inv__form" id="sup-inv-form" method="get" action="{{ route('kaman-support.logs') }}">
            <input type="hidden" name="lane" value="{{ $lane }}">
            @if($range !== '')
                <input type="hidden" name="range" value="{{ $range }}" data-range-field>
            @endif
            <input type="hidden" name="severity" value="{{ $client['severity'] }}" data-client="severity">
            <input type="hidden" name="category" value="{{ $client['category'] }}" data-client="category">
            <input type="hidden" name="integration" value="{{ $client['integration'] }}" data-client="integration">
            <input type="hidden" name="device" value="{{ $client['device'] }}" data-client="device">
            <input type="hidden" name="http" value="{{ $client['http'] }}" data-client="http">

            <div class="sup-inv__bar">
            <div class="sup-inv__seg" role="tablist" aria-label="{{ __('kaman-support.ws_logs_title') }}">
                <a href="{{ route('kaman-support.logs', array_merge($keep, ['lane' => 'device'])) }}" class="{{ $lane === 'device' ? 'is-active' : '' }}" @if($lane === 'device') aria-current="page" @endif>{{ __('kaman-support.ws_device_logs') }}</a>
                <a href="{{ route('kaman-support.logs', array_merge($keep, ['lane' => 'server'])) }}" class="{{ $lane === 'server' ? 'is-active' : '' }}" @if($lane === 'server') aria-current="page" @endif>{{ __('kaman-support.ws_server_logs') }}</a>
            </div>

            <input class="sup-inv__search" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('kaman-support.ws_search_logs') }}" aria-label="{{ __('kaman-support.ws_search_logs') }}" autocomplete="off" spellcheck="false">

            @if($lane === 'device')

            <div class="sup-inv__pop" data-pop>
                <button type="button" class="sup-inv__btn {{ count($picked) ? 'is-set' : '' }}" data-pop-btn aria-expanded="false" aria-haspopup="listbox">
                    @if(count($picked) === 1)
                        @include('kaman-support.partials.restaurant-logo', ['slug' => $picked[0], 'name' => $restaurantLabel])
                    @endif
                    <span dir="auto">{{ $restaurantLabel }}</span>
                    @if(count($picked) > 1)<em>{{ count($picked) }}</em>@endif
                </button>
                <div class="sup-inv__menu" data-pop-menu role="listbox" aria-multiselectable="true" hidden>
                    <input type="search" data-pop-search placeholder="{{ __('kaman-support.ws_search') }}" aria-label="{{ __('kaman-support.ws_search') }}" autocomplete="off">
                    <div class="sup-inv__options">
                        @foreach($choices as $choice)
                            <label class="sup-inv__opt" tabindex="0" data-label="{{ mb_strtolower($choice['label'].' '.$choice['value']) }}" data-menu-item>
                                <input type="checkbox" name="restaurants[]" value="{{ $choice['value'] }}" @checked(in_array($choice['value'], $picked, true))>
                                <i aria-hidden="true"></i>
                                @include('kaman-support.partials.restaurant-logo', ['slug' => $choice['value'], 'name' => $choice['label']])
                                <span dir="auto">{{ $choice['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <button class="sup-inv__apply" type="submit">{{ __('kaman-support.filter') }}</button>
                </div>
            </div>

            @endif

            <div class="sup-inv__pop" data-pop>
                <button type="button" class="sup-inv__btn {{ $rangeLabel !== '' || $filters['from'] !== '' || $filters['to'] !== '' ? 'is-set' : '' }}" data-pop-btn aria-expanded="false">
                    <span dir="auto">{{ $timeLabel }}</span>
                </button>
                <div class="sup-inv__menu sup-inv__menu--time" data-pop-menu hidden>
                    @foreach(['15m' => __('kaman-support.ws_last_15'), '1h' => __('kaman-support.ws_last_hour'), 'today' => __('kaman-support.ws_today'), '7d' => __('kaman-support.ws_last_week')] as $key => $label)
                        <button type="submit" name="range" value="{{ $key }}" class="{{ $range === $key ? 'is-active' : '' }}" data-menu-item>{{ $label }}</button>
                    @endforeach
                    <div class="sup-inv__dates">
                        <label class="sup-inv__when">
                            <span>{{ __('kaman-support.ws_range') }}</span>
                            <input type="date" data-when-date="from" value="{{ $fromDate }}" aria-label="{{ __('kaman-support.ws_range') }}">
                            <select data-when-hour="from" aria-label="{{ __('kaman-support.ws_hour') }}">
                                <option value="">{{ __('kaman-support.ws_all_day') }}</option>
                                @for ($hour = 0; $hour < 24; $hour++)
                                    @php $clock = str_pad((string) $hour, 2, '0', STR_PAD_LEFT); @endphp
                                    <option value="{{ $clock }}" @selected($fromHour === $clock)>{{ $clock }}:00</option>
                                @endfor
                            </select>
                        </label>
                        <label class="sup-inv__when">
                            <span>{{ __('kaman-support.ws_to') }}</span>
                            <input type="date" data-when-date="to" value="{{ $toDate }}" aria-label="{{ __('kaman-support.ws_to') }}">
                            <select data-when-hour="to" aria-label="{{ __('kaman-support.ws_hour') }}">
                                <option value="">{{ __('kaman-support.ws_all_day') }}</option>
                                @for ($hour = 0; $hour < 24; $hour++)
                                    @php $clock = str_pad((string) $hour, 2, '0', STR_PAD_LEFT); @endphp
                                    <option value="{{ $clock }}" @selected($toHour === $clock)>{{ $clock }}:00</option>
                                @endfor
                            </select>
                        </label>
                        <input type="hidden" name="from" value="" data-when-value="from">
                        <input type="hidden" name="to" value="" data-when-value="to">
                        <button class="sup-inv__apply" type="submit" name="range" value="" data-clear-range>{{ __('kaman-support.filter') }}</button>
                    </div>
                </div>
            </div>

            <button type="button" class="sup-inv__icon" data-refresh aria-label="{{ __('kaman-support.ws_inv_refresh') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg>
            </button>
            </div>

            <div class="sup-inv__filters" data-filter-row>
                <span class="sup-inv__filters-mark {{ $advanced ? 'is-set' : '' }}" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                    <em data-filter-badge @if($advanced === 0) hidden @endif>{{ $advanced ?: '' }}</em>
                </span>
                @if($lane === 'device')
                    <section>
                        <h2>{{ __('kaman-support.ws_severity') }}</h2>
                        <div class="sup-inv__picks" data-pick="severity">
                            @foreach(['error' => __('kaman-support.ws_level_error'), 'warn' => __('kaman-support.ws_warning'), 'info' => __('kaman-support.ws_level_info')] as $value => $label)
                                <button type="button" data-value="{{ $value }}" class="{{ $client['severity'] === $value ? 'is-active' : '' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </section>
                    <section>
                        <h2>{{ __('kaman-support.ws_inv_category') }}</h2>
                        <div class="sup-inv__picks" data-pick="category">
                            @foreach($categoryLabels as $value => $label)
                                <button type="button" data-value="{{ $value }}" class="{{ $client['category'] === $value ? 'is-active' : '' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </section>
                    <section class="sup-inv__filter-device">
                        <h2>{{ __('kaman-support.device') }}</h2>
                        <input type="search" data-device-input value="{{ $client['device'] }}" placeholder="{{ __('kaman-support.ws_inv_search_device') }}" aria-label="{{ __('kaman-support.ws_inv_search_device') }}" autocomplete="off">
                        @if($deviceOptions !== [])
                            <div class="sup-inv__devices" data-device-list>
                                @foreach($deviceOptions as $name)
                                    <button type="button" data-device-option="{{ $name }}" class="{{ $client['device'] === $name ? 'is-active' : '' }}" dir="auto">{{ $name }}</button>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @else
                    <section>
                        <h2>{{ __('kaman-support.ws_inv_integration') }}</h2>
                        <div class="sup-inv__picks" data-pick="integration">
                            @foreach($integrationLabels as $value => $label)
                                <button type="button" data-value="{{ $value }}" class="{{ $client['integration'] === $value ? 'is-active' : '' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </section>
                    <section>
                        <h2>{{ __('kaman-support.ws_inv_status') }}</h2>
                        <div class="sup-inv__picks" data-pick="http">
                            @foreach(($httpOptions !== [] ? $httpOptions : ['400', '401', '403', '404', '500', '502']) as $code)
                                <button type="button" data-value="{{ $code }}" class="{{ $client['http'] === $code ? 'is-active' : '' }}" dir="ltr">{{ $code }}</button>
                            @endforeach
                        </div>
                    </section>
                @endif
                <button type="button" class="sup-inv__clear" data-clear-filters>{{ __('kaman-support.ws_reset') }}</button>
            </div>
        </form>

        @if($lane === 'server')
            <section class="sup-inv__server">
                <span class="sup-inv__server-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/></svg>
                </span>
                <h2>{{ __('kaman-support.ws_inv_server_title') }}</h2>
                <p>{{ __('kaman-support.ws_inv_server_sub') }}</p>
            </section>
        @else
        <div class="sup-inv__chips" data-chips>
            @if($filters['q'] !== '')
                <a class="sup-inv__chip" href="{{ route('kaman-support.logs', request()->except('q')) }}"><span dir="auto">{{ $filters['q'] }}</span><i aria-hidden="true">×</i></a>
            @endif
            @foreach($picked as $pickedId)
                @php
                    $next = array_values(array_diff($picked, [$pickedId]));
                    $params = request()->except('restaurants');
                    if ($next !== []) {
                        $params['restaurants'] = $next;
                    }
                @endphp
                <a class="sup-inv__chip" href="{{ route('kaman-support.logs', $params) }}">@include('kaman-support.partials.restaurant-logo', ['slug' => $pickedId, 'name' => $choiceLabels[$pickedId] ?? $pickedId])<span dir="auto">{{ $choiceLabels[$pickedId] ?? $pickedId }}</span><i aria-hidden="true">×</i></a>
            @endforeach
            @if($rangeLabel !== '')
                <a class="sup-inv__chip" href="{{ route('kaman-support.logs', request()->except('range')) }}">{{ $rangeLabel }}<i aria-hidden="true">×</i></a>
            @elseif($filters['from'] !== '' || $filters['to'] !== '')
                <a class="sup-inv__chip" href="{{ route('kaman-support.logs', request()->except(['from', 'to'])) }}"><span dir="ltr">{{ trim($filters['from'].' – '.$filters['to']) }}</span><i aria-hidden="true">×</i></a>
            @endif
            <span data-client-chips>
                @foreach($clientChips as $chip)
                    <button type="button" class="sup-inv__chip" data-clear="{{ $chip['key'] }}"><span dir="auto">{{ $chip['label'] }}</span><i aria-hidden="true">×</i></button>
                @endforeach
            </span>
        </div>

        <div class="sup-inv__stage">
            <section class="sup-inv__stream" aria-label="{{ __('kaman-support.ws_inv_issues') }}">
                <header class="sup-inv__stream-head">
                    <p><strong data-visible-count>{{ $visibleCount }}</strong> {{ __('kaman-support.ws_inv_issues') }}</p>
                    <span class="sup-inv__scope" title="{{ __('kaman-support.ws_inv_scope_note') }}">{{ __('kaman-support.ws_inv_scope', ['count' => $loaded]) }}</span>
                </header>
                <div class="sup-inv__skels" aria-hidden="true">
                    @for($i = 0; $i < 6; $i++)
                        <div class="sup-inv__skel"></div>
                    @endfor
                </div>
                <div class="sup-inv__list" data-stream role="listbox">
                    @php $marked = false; @endphp
                    @forelse($issues as $issue)
                        @php
                            $place = $issue['restaurant'] !== '' ? $issue['restaurant'] : ($issue['device'] !== '' ? $issue['device'] : __('kaman-support.ws_inv_pending'));
                            $tone = $issue['tone'] ?? 'info';
                            $stamp = $issue['lastClock'];
                            if (preg_match('/(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2})/', (string) ($issue['lastAt'] ?? ''), $when) === 1) {
                                $stamp = $when[3].'/'.$when[2].' '.$when[4];
                            }
                            $http = preg_match('/^\d{3}$/', (string) ($issue['code'] ?? '')) === 1 ? (string) $issue['code'] : '';
                            $httpBand = $http !== '' ? $http[0] : '';
                            $selected = ! $marked && $issue['visible'];
                            if ($selected) {
                                $marked = true;
                            }
                        @endphp
                        <button
                            type="button"
                            class="sup-inv__issue {{ $selected ? 'is-selected' : '' }}"
                            role="option"
                            data-key="{{ $issue['key'] }}"
                            aria-selected="{{ $selected ? 'true' : 'false' }}"
                            @if(! $issue['visible']) hidden @endif
                        >
                            <span class="sup-inv__mark is-{{ $tone }}" aria-hidden="true">
                                @if($tone === 'warn')
                                    <svg viewBox="0 0 24 24"><path d="M12 3 2.5 20h19z"/><path d="M12 9v4M12 17h.01"/></svg>
                                @elseif($tone === 'ok')
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                                @elseif($tone === 'info')
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                                @else
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                                @endif
                            </span>
                            <strong><span dir="auto">{{ $issue['title'] }}</span></strong>
                            <span class="sup-inv__http {{ $httpBand !== '' ? 'is-'.$httpBand : 'is-empty' }}" dir="ltr">{{ $http }}</span>
                            <span class="sup-inv__count" dir="ltr">×{{ $issue['count'] }}</span>
                            <span class="sup-inv__line">
                                <span class="sup-inv__place">
                                    @if($issue['restaurant'] !== '')
                                        @include('kaman-support.partials.restaurant-logo', ['slug' => $issue['restaurantId'], 'name' => $issue['restaurant']])
                                    @endif
                                    <span class="sup-inv__place-copy">
                                        <span dir="auto">{{ $place }}</span>
                                        @php
                                            $productNames = array_values(array_filter(array_map(
                                                static fn (string $key): string => match ($key) {
                                                    'mall' => __('kaman-support.ws_cashier_mall'),
                                                    'lite' => __('kaman-support.ws_lite'),
                                                    default => '',
                                                },
                                                is_array($issue['products'] ?? null) ? $issue['products'] : [],
                                            )));
                                        @endphp
                                        @if($productNames !== [])
                                            <span class="sup-inv__product" dir="ltr">{{ implode(' · ', $productNames) }}</span>
                                        @endif
                                    </span>
                                </span>
                                <time><span dir="ltr">{{ $stamp }}</span></time>
                            </span>
                        </button>
                    @empty
                        <div class="sup-inv__empty">{{ __('kaman-support.ws_empty_logs') }}</div>
                    @endforelse
                    <div class="sup-inv__empty" data-no-match @if($issues === [] || $visibleCount !== 0) hidden @endif>{{ __('kaman-support.ws_inv_no_match') }}</div>
                </div>
            </section>

            <section class="sup-inv__detail" data-detail aria-live="polite">
                <div class="sup-inv__placeholder" data-detail-skel>
                    <div class="sup-inv__skel sup-inv__skel--line"></div>
                    <div class="sup-inv__skel sup-inv__skel--line"></div>
                    <div class="sup-inv__skel sup-inv__skel--block"></div>
                </div>
            </section>
        </div>
        @endif
    </div>

    <script type="application/json" id="sup-inv-data">@json($issues)</script>
    <script type="application/json" id="sup-inv-labels">@json($labels)</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/support-logs.js') }}?v={{ filemtime(public_path('js/support-logs.js')) }}"></script>
@endpush
