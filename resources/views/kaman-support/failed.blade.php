@php
    $nav = 'logs';
    $activeProject = \App\Enums\Project::KamanSupport->value;
    $type = $filters['type'] ?? '';
    $queryBase = array_filter([
        'restaurant' => $filters['restaurant'] ?? '',
        'type' => $type,
        'q' => $filters['q'] ?? '',
        'from' => $filters['from'] ?? '',
        'to' => $filters['to'] ?? '',
    ], static fn ($value) => $value !== '' && $value !== null);
    $i18n = [
        'no_logs' => __('kaman-support.failed_empty'),
        'failed_requests' => __('kaman-support.failed_requests'),
        'failed_count' => __('kaman-support.failed_count'),
        'quick_filters' => __('kaman-support.quick_filters'),
    ];
@endphp

@extends('layouts.support')

@section('title', __('kaman-support.failed_title'))
@section('tag', __('kaman-support.tag'))

@section('content')
    <div class="page-container"
         data-failed-board
         data-feed="{{ route('kaman-support.failed.feed', $queryBase) }}"
         data-device-url="{{ url('/kaman-support/live/__ID__') }}"
         data-i18n='@json($i18n)'>
        <div class="mx-auto w-full max-w-6xl space-y-4">
            <section class="hero-panel hero-panel--compact">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">{{ __('kaman-support.failed_title') }}</h1>
                        <p class="mt-1 text-sm text-[#7c6a56]">{{ __('kaman-support.failed_subtitle') }}</p>
                    </div>
                    @include('kaman-support.partials.nav', ['nav' => 'failed'])
                </div>
            </section>

            <form method="get" data-clean-get class="kaman-card kaman-card--compact flex flex-wrap items-end gap-2">
                <label class="flex w-64 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.filter_restaurant') }}
                    <select name="restaurant" data-failed-restaurant class="kaman-input" data-ds-select>
                        <option value="">{{ __('kaman-support.all_restaurants') }}</option>
                        @foreach($restaurants as $restaurant)
                            <option value="{{ $restaurant['value'] }}" @selected($filters['restaurant'] === $restaurant['value'])>{{ $restaurant['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                @if($type !== '')
                    <input type="hidden" name="type" value="{{ $type }}" data-failed-type>
                @endif
                <label class="flex w-40 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.log_from') }}
                    <input type="date" name="from" value="{{ substr((string) ($filters['from'] ?? ''), 0, 10) }}" data-log-from class="kaman-input">
                </label>
                <label class="flex w-40 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.log_to') }}
                    <input type="date" name="to" value="{{ substr((string) ($filters['to'] ?? ''), 0, 10) }}" data-log-to class="kaman-input">
                </label>
                <label class="flex min-w-[12rem] flex-1 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                    {{ __('kaman-support.log_search') }}
                    <input type="search" name="q" value="{{ $filters['q'] }}" data-log-search class="kaman-input" placeholder="{{ __('kaman-support.log_search') }}">
                </label>
                <button type="submit" class="kaman-button">{{ __('kaman-support.filter') }}</button>
                <p class="ms-auto text-xs text-[#a78a6c]">
                    <span class="inline-block h-2 w-2 rounded-full bg-rose-500 align-middle"></span>
                    {{ __('kaman-support.live_channel') }} ·
                    <span data-failed-total>{{ $logs->count() }}</span>
                    {{ __('kaman-support.failed_requests') }}
                </p>
            </form>

            <nav class="flex flex-wrap gap-2">
                <a href="{{ route('kaman-support.failed', array_filter(['restaurant' => $filters['restaurant'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'q' => $filters['q'] ?? ''])) }}"
                   class="kaman-button-ghost kaman-button--sm {{ $type === '' ? 'ring-1 ring-[#e8b086]' : '' }}">
                    {{ __('kaman-support.all_types') }}
                </a>
                <a href="{{ route('kaman-support.failed', array_filter(['restaurant' => $filters['restaurant'] ?? '', 'type' => 'api', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'q' => $filters['q'] ?? ''])) }}"
                   class="kaman-button-ghost kaman-button--sm {{ $type === 'api' ? 'ring-1 ring-[#e8b086]' : '' }}">
                    {{ __('kaman-support.type_api') }}
                </a>
                <a href="{{ route('kaman-support.failed', array_filter(['restaurant' => $filters['restaurant'] ?? '', 'type' => 'flutter', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'q' => $filters['q'] ?? ''])) }}"
                   class="kaman-button-ghost kaman-button--sm {{ $type === 'flutter' ? 'ring-1 ring-[#e8b086]' : '' }}">
                    {{ __('kaman-support.type_flutter') }}
                </a>
            </nav>

            <section class="kaman-card">
                <h2 class="mb-2 text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.failed_title') }}</h2>
                <p data-logs-empty class="py-12 text-center text-sm text-[#7c6a56]" @if($logs->isNotEmpty()) hidden @endif>
                    {{ __('kaman-support.failed_empty') }}
                </p>
                <ol data-logs class="space-y-2">
                    @foreach($logs as $log)
                        @include('kaman-support.partials.log-row', ['log' => $log, 'showDevice' => true])
                    @endforeach
                </ol>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/kaman-support-live.js') }}?v={{ @filemtime(public_path('js/kaman-support-live.js')) ?: 1 }}"></script>
@endpush
