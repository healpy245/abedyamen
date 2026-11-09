@props(['nav' => 'live', 'limited' => false])
@php
    $supportOpen = fn (string $key): bool => (bool) auth()->user()?->canOpenSubDoor(\App\Enums\Door::Support, $key);
@endphp
<nav class="ws-float" data-ws-float aria-label="{{ __('kaman-support.ws_support') }}">
    <span class="ws-float__indicator" data-ws-indicator></span>
    <a href="{{ route('kaman-support.index') }}" @class(['is-selected' => $nav === 'live', 'is-locked' => ! $supportOpen('live')]) @if($nav === 'live') aria-current="page" @endif>
        @include('components.workspace.icons', ['name' => 'monitor'])
        {{ __('kaman-support.nav_live') }}
        @unless($supportOpen('live'))<x-workspace.lock-mark />@endunless
    </a>
    @unless($limited)
        <a href="{{ route('kaman-support.logs') }}" @class(['is-selected' => $nav === 'logs', 'is-locked' => ! $supportOpen('logs')]) @if($nav === 'logs') aria-current="page" @endif>
            @include('components.workspace.icons', ['name' => 'logs'])
            {{ __('kaman-support.nav_logs') }}
            @unless($supportOpen('logs'))<x-workspace.lock-mark />@endunless
        </a>
        <a href="{{ route('kaman-support.menu') }}" @class(['is-selected' => $nav === 'menu', 'is-locked' => ! $supportOpen('menu')]) @if($nav === 'menu') aria-current="page" @endif>
            @include('components.workspace.icons', ['name' => 'spark'])
            {{ __('kaman-support.nav_menu') }}
            @unless($supportOpen('menu'))<x-workspace.lock-mark />@endunless
        </a>
        <a href="{{ route('kaman-support.inbox') }}" @class(['is-selected' => $nav === 'tickets', 'is-locked' => ! $supportOpen('tickets')]) @if($nav === 'tickets') aria-current="page" @endif>
            @include('components.workspace.icons', ['name' => 'ticket'])
            {{ __('kaman-support.nav_tickets') }}
            @unless($supportOpen('tickets'))<x-workspace.lock-mark />@endunless
        </a>
    @endunless
</nav>
