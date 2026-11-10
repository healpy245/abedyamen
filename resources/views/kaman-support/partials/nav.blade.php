@php
    $nav = $nav ?? 'tickets';
@endphp
<div class="flex flex-wrap items-center gap-2">
    @unless(auth()->user()?->limitedSupportConsole())
        <a href="{{ route('kaman-support.index') }}" class="kaman-button-ghost kaman-button--sm {{ $nav === 'tickets' ? 'ring-1 ring-[#e8b086]' : '' }}">{{ __('kaman-support.nav.tickets') }}</a>
    @endunless
    <a href="{{ route('kaman-support.live') }}" class="kaman-button-ghost kaman-button--sm {{ $nav === 'live' ? 'ring-1 ring-[#e8b086]' : '' }}">{{ auth()->user()?->supportConnectionsOnly() ? __('kaman-support.connections_title') : __('kaman-support.nav.live') }}</a>
    @unless(auth()->user()?->limitedSupportConsole())
        <a href="{{ route('kaman-support.failed') }}" class="kaman-button-ghost kaman-button--sm {{ $nav === 'failed' ? 'ring-1 ring-[#e8b086]' : '' }}">{{ __('kaman-support.nav.failed') }}</a>
        <a href="{{ route('kaman-support.printer-issues') }}" class="kaman-button-ghost kaman-button--sm {{ $nav === 'printer-issues' ? 'ring-1 ring-[#e8b086]' : '' }}">{{ __('kaman-support.nav.printer_issues') }}</a>
    @endunless
    @unless(auth()->user()?->supportConnectionsOnly())
    <a href="{{ route('downloads.kaman-support') }}?v=1.0.11"
       class="kaman-button kaman-button--sm"
       download="KamanSupport-1.0.11.apk">
        @include('app-development.partials.icon', ['name' => 'download'])
        {{ __('kaman-support.nav.download') }}
    </a>
    @endunless
</div>
