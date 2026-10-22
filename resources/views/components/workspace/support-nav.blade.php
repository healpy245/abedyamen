@props(['nav' => 'live', 'limited' => false])
<nav class="ws-float" data-ws-float aria-label="{{ __('kaman-support.ws_support') }}">
    <span class="ws-float__indicator" data-ws-indicator></span>
    <a href="{{ route('kaman-support.index') }}" class="{{ $nav === 'live' ? 'is-selected' : '' }}" @if($nav === 'live') aria-current="page" @endif>
        @include('components.workspace.icons', ['name' => 'monitor'])
        {{ __('kaman-support.nav_live') }}
    </a>
    @unless($limited)
        <a href="{{ route('kaman-support.logs') }}" class="{{ $nav === 'logs' ? 'is-selected' : '' }}" @if($nav === 'logs') aria-current="page" @endif>
            @include('components.workspace.icons', ['name' => 'logs'])
            {{ __('kaman-support.nav_logs') }}
        </a>
        <a href="{{ route('kaman-support.menu') }}" class="{{ $nav === 'menu' ? 'is-selected' : '' }}" @if($nav === 'menu') aria-current="page" @endif>
            @include('components.workspace.icons', ['name' => 'spark'])
            {{ __('kaman-support.nav_menu') }}
        </a>
        <a href="{{ route('kaman-support.inbox') }}" class="{{ $nav === 'tickets' ? 'is-selected' : '' }}" @if($nav === 'tickets') aria-current="page" @endif>
            @include('components.workspace.icons', ['name' => 'ticket'])
            {{ __('kaman-support.nav_tickets') }}
        </a>
    @endunless
</nav>
