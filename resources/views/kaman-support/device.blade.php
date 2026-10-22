@php
    $nav = 'live';
    $activeProject = \App\Enums\Project::KamanSupport->value;
    $kind = $device->deviceKind();
    $view = $filters['view'] ?? 'all';
    $queryBase = array_filter([
        'q' => $filters['q'] ?? '',
    ], static fn ($value) => $value !== '' && $value !== null);
    $i18n = [
        'online' => __('kaman-support.online'),
        'offline' => __('kaman-support.offline'),
        'ready' => __('kaman-support.printer_ready'),
        'issue' => __('kaman-support.printer_issue'),
        'role_receipt' => __('kaman-support.role_receipt'),
        'role_sticker' => __('kaman-support.role_sticker'),
        'role_kitchen' => __('kaman-support.role_kitchen'),
        'print_test' => __('kaman-support.print_test'),
        'remove' => __('kaman-support.remove_printer'),
        'add' => __('kaman-support.add_printer'),
        'no_printers' => __('kaman-support.no_tablet_printers'),
        'no_printers_found' => __('kaman-support.no_printers_found'),
        'new_printer' => __('kaman-support.new_printer'),
        'printer_untitled' => __('kaman-support.printer_untitled'),
        'choose_kitchen' => __('kaman-support.choose_kitchen_type'),
        'connected' => __('kaman-support.printer_connected'),
        'disconnected' => __('kaman-support.printer_disconnected'),
        'not_detected' => __('kaman-support.printer_not_detected'),
        'sending' => __('kaman-support.live_sending'),
        'queued' => __('kaman-support.live_queued'),
        'busy' => __('kaman-support.flash.command_busy'),
        'send_failed' => __('kaman-support.live_send_failed'),
        'no_logs' => __('kaman-support.no_logs'),
        'failed_requests' => __('kaman-support.failed_requests'),
        'quick_filters' => __('kaman-support.quick_filters'),
        'view_api' => __('kaman-support.view_api'),
        'kind_tablet' => __('kaman-support.kind_tablet'),
        'kind_desktop' => __('kaman-support.kind_desktop'),
        'kind_web' => __('kaman-support.kind_web'),
        'kind_phone' => __('kaman-support.kind_phone'),
        'lan_empty' => __('kaman-support.lan_empty'),
        'lan_explainer' => __('kaman-support.lan_explainer'),
        'lan_subnet' => __('kaman-support.lan_subnet'),
        'lan_gateway' => __('kaman-support.lan_gateway'),
        'lan_devices' => __('kaman-support.lan_devices'),
        'lan_offline' => __('kaman-support.lan_offline'),
        'lan_role_tablet' => __('kaman-support.lan_role_tablet'),
        'lan_role_gateway' => __('kaman-support.lan_role_gateway'),
        'lan_role_printer' => __('kaman-support.lan_role_printer'),
        'lan_role_camera' => __('kaman-support.lan_role_camera'),
        'lan_role_computer' => __('kaman-support.lan_role_computer'),
        'lan_role_web' => __('kaman-support.lan_role_web'),
        'lan_role_other' => __('kaman-support.lan_role_other'),
    ];
@endphp

@extends('layouts.support')

@section('title', $device->displayRestaurant().' · '.__('kaman-support.live_title'))
@section('tag', __('kaman-support.tag'))

@section('content')
    <div class="page-container page-container--tight"
         data-live-console
         data-feed="{{ route('kaman-support.device.feed', $device) }}"
         @unless(auth()->user()?->limitedSupportConsole())
         data-command="{{ route('kaman-support.device.commands', $device) }}"
         @endunless
         data-csrf="{{ csrf_token() }}"
         data-view="{{ $view }}"
         data-i18n='@json($i18n)'>
        <div class="mx-auto w-full max-w-6xl space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <a href="{{ route('kaman-support.live') }}" class="kaman-button-ghost kaman-button--sm">{{ __('kaman-support.back_live') }}</a>
                @include('kaman-support.partials.nav', ['nav' => 'live'])
            </div>

            <section class="hero-panel hero-panel--compact space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-semibold text-[#2b1e11]">{{ $device->displayRestaurant() }}</h1>
                    <span data-online></span>
                    <span class="kaman-badge bg-[#fff4ea] text-[#9a3412] border-[#f3d2b5]">{{ __('kaman-support.kind_'.$kind) }}</span>
                </div>
                <p class="text-sm text-[#7c6a56]">
                    {{ $device->cashier_name ?: '—' }}
                    @if($device->role) · {{ $device->role }} @endif
                    · {{ $device->hostname ?: $device->device_id }}
                    · <span data-last-seen>{{ $device->last_seen_at?->diffForHumans() }}</span>
                </p>
                @unless(auth()->user()?->supportConnectionsOnly())
                <p data-live-status class="text-sm text-[#7c6a56]">{{ __('kaman-support.live_channel_hint') }}</p>
                @endunless
            </section>

            @unless(auth()->user()?->limitedSupportConsole())
            <section class="kaman-card kaman-card--compact space-y-3">
                <p class="text-sm text-[#7c6a56]">{{ __('kaman-support.printers_hint') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" data-scan-lan class="kaman-button">{{ __('kaman-support.search_printers') }}</button>
                    <button type="button" data-sync-api class="kaman-button-ghost">{{ __('kaman-support.sync_kitchen_api') }}</button>
                    <button type="button" data-retry class="kaman-button-ghost">{{ __('kaman-support.command.retry_failed_prints') }}</button>
                </div>
                <progress data-scan hidden class="h-2 w-full overflow-hidden rounded bg-[#f4eadc]"></progress>
                <p data-printers-empty class="py-6 text-center text-sm text-[#7c6a56]">{{ __('kaman-support.no_tablet_printers') }}</p>
                <div data-saved-block hidden class="space-y-2">
                    <h3 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.saved_printers') }}</h3>
                    <div data-printers class="overflow-hidden rounded-xl border border-[#eadfce] bg-white"></div>
                </div>
                <div data-found-block hidden class="space-y-2">
                    <h3 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.found_on_network') }}</h3>
                    <div data-discovered class="overflow-hidden rounded-xl border border-[#eadfce] bg-white"></div>
                </div>
            </section>

            <section class="kaman-card kaman-card--compact space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.lan_title') }}</h2>
                    <button type="button" data-map-lan class="kaman-button">{{ __('kaman-support.map_lan_button') }}</button>
                </div>
                <p class="text-sm text-[#7c6a56]">{{ __('kaman-support.lan_explainer') }}</p>
                <p data-lan-facts class="text-xs text-[#7c6a56]"></p>
                <progress data-lan-scan hidden class="h-2 w-full overflow-hidden rounded bg-[#f4eadc]"></progress>
                <div data-lan-map hidden class="relative overflow-x-auto rounded-xl border border-[#eadfce] bg-[#fffaf3]"></div>
                <div data-lan-detail class="text-sm text-[#2b1e11]"></div>
            </section>

            <section data-jobs-card class="kaman-card kaman-card--compact space-y-2" @if($commands->isEmpty()) hidden @endif>
                <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.jobs_title') }}</h2>
                <ol data-jobs class="space-y-2">
                    @foreach($commands as $command)
                        <li class="rounded-md border border-[#eadfce] px-3 py-2 text-sm">
                            <div class="flex flex-wrap gap-2 text-xs text-[#a78a6c]">
                                <span class="font-semibold text-[#c2410c]">{{ $command->name?->label() ?? $command->name }}</span>
                                <span>{{ $command->status?->value }}</span>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
            @endunless

            @unless(auth()->user()?->supportConnectionsOnly())
            <section class="kaman-card kaman-card--compact space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('kaman-support.device_live_logs') }}</h2>
                    <nav class="flex flex-wrap gap-2">
                        @foreach(['all' => 'view_all_logs', 'bugs' => 'view_bugs', 'api' => 'view_api'] as $key => $label)
                            <a href="{{ route('kaman-support.device', [$device, 'view' => $key] + $queryBase) }}"
                               class="kaman-button-ghost kaman-button--sm {{ $view === $key ? 'ring-1 ring-[#e8b086]' : '' }}">
                                {{ __('kaman-support.'.$label) }}
                            </a>
                        @endforeach
                    </nav>
                </div>
                <form method="get" data-clean-get class="flex flex-wrap items-end gap-2">
                    <input type="hidden" name="view" value="{{ $view }}">
                    <label class="flex min-w-[12rem] flex-1 flex-col gap-1 text-xs font-medium text-[#7c6a56]">
                        {{ __('kaman-support.log_search') }}
                        <input type="search" name="q" value="{{ $filters['q'] }}" data-log-search class="kaman-input" placeholder="{{ __('kaman-support.log_search') }}">
                    </label>
                    <button type="submit" class="kaman-button">{{ __('kaman-support.filter') }}</button>
                </form>
                <p data-logs-empty class="py-6 text-center text-sm text-[#7c6a56]" @if($logs->isNotEmpty()) hidden @endif>
                    {{ __('kaman-support.no_logs') }}
                </p>
                <ol data-logs class="space-y-2">
                    @foreach($logs as $log)
                        @include('kaman-support.partials.log-row', ['log' => $log])
                    @endforeach
                </ol>
                @if($logs->hasPages())
                    <div>{{ $logs->appends(array_filter(['view' => $view !== 'all' ? $view : '', 'q' => $filters['q'] ?? '']))->links() }}</div>
                @endif
            </section>
            @endunless
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/kaman-support-live.js') }}?v={{ @filemtime(public_path('js/kaman-support-live.js')) ?: 1 }}"></script>
@endpush
