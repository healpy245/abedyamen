@php
    $nav = $nav ?? 'tickets';
@endphp
<div class="flex flex-wrap items-center gap-2">
    @unless(auth()->user()?->posLiveLogsOnly())
        <a href="{{ route('kaman-support.index') }}" class="kaman-button-ghost kaman-button--sm {{ $nav === 'tickets' ? 'ring-1 ring-[#e8b086]' : '' }}">{{ __('kaman-support.nav.tickets') }}</a>
    @endunless
    <a href="{{ route('kaman-support.live') }}" class="kaman-button-ghost kaman-button--sm {{ $nav === 'live' ? 'ring-1 ring-[#e8b086]' : '' }}">{{ __('kaman-support.nav.live') }}</a>
    @unless(auth()->user()?->posLiveLogsOnly())
        <a href="{{ route('kaman-support.failed') }}" class="kaman-button-ghost kaman-button--sm {{ $nav === 'failed' ? 'ring-1 ring-[#e8b086]' : '' }}">{{ __('kaman-support.nav.failed') }}</a>
    @endunless
</div>
