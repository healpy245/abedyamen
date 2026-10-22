@php
    $nav = 'live';
    $activeProject = \App\Enums\Project::KamanSupport->value;
    $view = $filters['view'] ?? 'devices';
    $connectionsOnly = auth()->user()?->supportConnectionsOnly();
    $logTitle = match ($view) {
        'bugs' => __('kaman-support.view_bugs'),
        'api' => __('kaman-support.view_api'),
        'flutter' => __('kaman-support.source_flutter'),
        default => __('kaman-support.view_all_logs'),
    };
    $kind = $filters['kind'] ?? '';
    $queryBase = array_filter([
        'restaurant' => $filters['restaurant'] ?? '',
        'kind' => $kind,
        'q' => $view === 'devices' ? '' : ($filters['q'] ?? ''),
    ], static fn ($value) => $value !== '' && $value !== null);
    $feedQuery = $queryBase + ['view' => $view];
    $i18n = [
        'online' => __('kaman-support.online'),
        'offline' => __('kaman-support.offline'),
        'kind_tablet' => __('kaman-support.kind_tablet'),
        'kind_desktop' => __('kaman-support.kind_desktop'),
        'kind_web' => __('kaman-support.kind_web'),
        'kind_phone' => __('kaman-support.kind_phone'),
        'printers' => __('kaman-support.printers'),
        'no_logs' => __('kaman-support.no_logs'),
        'failed_requests' => __('kaman-support.failed_requests'),
        'quick_filters' => __('kaman-support.quick_filters'),
        'view_api' => __('kaman-support.view_api'),
        'view_all_logs' => __('kaman-support.view_all_logs'),
        'failed_on_device' => __('kaman-support.failed_on_device'),
    ];
@endphp

@extends('layouts.support')

@section('title', __('kaman-support.live_title'))
@section('tag', __('kaman-support.tag'))

@section('content')
    <div class="page-container page-container--tight"
         data-live-board
         data-feed="{{ route('kaman-support.live.feed', $feedQuery) }}"
         data-device-url="{{ url('/kaman-support/live/__ID__') }}"
         data-view="{{ $view }}"
         data-i18n='@json($i18n)'>
        <div class="mx-auto w-full max-w-6xl space-y-4">
            <section class="hero-panel hero-panel--compact">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">{{ $connectionsOnly ? __('kaman-support.connections_title') : __('kaman-support.live_title') }}</h1>
                        <p class="mt-1 text-sm text-[#7c6a56]">{{ $connectionsOnly ? __('kaman-support.connections_subtitle') : __('kaman-support.live_subtitle') }}</p>
                    </div>
                    @include('kaman-support.partials.nav', ['nav' => 'live'])
                </div>
            </section>

            <form method="get" data-clean-get class="kaman-card kaman-card--compact flex flex-wrap items-end gap-2">
                <input type="hidden" name="view" value="{{ $view }}">
                <label class="flex w-56 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.filter_restaurant') }}
                    <select name="restaurant" class="kaman-input">
                        <option value="">{{ __('kaman-support.all_restaurants') }}</option>
                        @foreach($restaurants as $restaurant)
                            @php
                                $restaurantValue = is_array($restaurant) ? ($restaurant['value'] ?? '') : $restaurant;
                                $restaurantLabel = is_array($restaurant) ? ($restaurant['label'] ?? $restaurantValue) : $restaurant;
                            @endphp
                            <option value="{{ $restaurantValue }}" @selected($filters['restaurant'] === $restaurantValue)>{{ $restaurantLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex w-44 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.filter_kind') }}
                    <select name="kind" class="kaman-input">
                        <option value="">{{ __('kaman-support.all_kinds') }}</option>
                        <option value="tablet" @selected($kind === 'tablet')>{{ __('kaman-support.kind_tablet') }}</option>
                        <option value="desktop" @selected($kind === 'desktop')>{{ __('kaman-support.kind_desktop') }}</option>
                        <option value="web" @selected($kind === 'web')>{{ __('kaman-support.kind_web') }}</option>
                        <option value="phone" @selected($kind === 'phone')>{{ __('kaman-support.kind_phone') }}</option>
                    </select>
                </label>
                @if($view !== 'devices')
                    <label class="flex min-w-[12rem] flex-1 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                        {{ __('kaman-support.log_search') }}
                        <input type="search" name="q" value="{{ $filters['q'] }}" data-log-search class="kaman-input" placeholder="{{ __('kaman-support.log_search') }}">
                    </label>
                @endif
                <button type="submit" class="kaman-button">{{ __('kaman-support.filter') }}</button>
                <p class="ms-auto text-xs text-[#a78a6c]">
                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-500 align-middle"></span>
                    {{ __('kaman-support.live_channel') }} ·
                    <span data-live-count>{{ $liveDevices->count() }}</span>
                    {{ __('kaman-support.live_open_count') }}
                </p>
            </form>

            @unless($connectionsOnly)
            <nav class="flex flex-wrap gap-2">
                @foreach(['devices' => 'view_devices', 'all' => 'view_all_logs', 'bugs' => 'view_bugs', 'api' => 'view_api', 'flutter' => 'source_flutter'] as $key => $label)
                    <a href="{{ route('kaman-support.live', $queryBase + ['view' => $key]) }}"
                       class="kaman-button-ghost kaman-button--sm {{ $view === $key ? 'ring-1 ring-[#e8b086]' : '' }}">
                        {{ __('kaman-support.'.$label) }}
                    </a>
                @endforeach
            </nav>
            @endunless

            <section data-devices-pane class="space-y-4" @if($view !== 'devices') hidden @endif>
                <div class="space-y-3">
                    <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.live_open') }}</h2>
                    <p data-live-empty class="kaman-card kaman-card--compact py-8 text-center text-sm text-[#7c6a56]" @if($liveDevices->isNotEmpty()) hidden @endif>
                        {{ __('kaman-support.live_empty') }}
                    </p>
                    <div data-live-grid class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($liveDevices as $device)
                            @include('kaman-support.partials.device-card', ['device' => $device])
                        @endforeach
                    </div>
                </div>

                <div class="space-y-3">
                    <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.live_recent') }}</h2>
                    <p data-recent-empty class="kaman-card kaman-card--compact py-6 text-center text-sm text-[#7c6a56]" @if($recentDevices->isNotEmpty()) hidden @endif>
                        {{ __('kaman-support.live_recent_empty') }}
                    </p>
                    <div data-recent-grid class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($recentDevices as $device)
                            @include('kaman-support.partials.device-card', ['device' => $device])
                        @endforeach
                    </div>
                </div>
            </section>

            @unless($connectionsOnly)
            <section class="kaman-card kaman-card--compact" @if($view === 'devices') hidden @endif>
                <h2 class="mb-2 text-sm font-semibold text-[#2b1e11]">{{ $logTitle }}</h2>
                <p data-logs-empty class="py-8 text-center text-sm text-[#7c6a56]" @if($feedLogs->isNotEmpty()) hidden @endif>
                    {{ __('kaman-support.no_logs') }}
                </p>
                <ol data-logs class="space-y-2">
                    @foreach($feedLogs as $log)
                        @include('kaman-support.partials.log-row', ['log' => $log, 'showDevice' => true])
                    @endforeach
                </ol>
            </section>
            @endunless
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/kaman-support-live.js') }}?v={{ @filemtime(public_path('js/kaman-support-live.js')) ?: 1 }}"></script>
@endpush
