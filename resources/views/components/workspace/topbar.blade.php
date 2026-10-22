@props(['door' => 'overview', 'search' => true])
@php
    $initials = collect(preg_split('/\s+/', trim((string) auth()->user()?->name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp
<header class="ws-top">
    @if($search)
        <label class="ws-search">
            @include('components.workspace.icons', ['name' => 'search'])
            <input type="search" data-ws-search @if($door === 'overview') data-hub-search @endif @if($door === 'agents') data-ag-search @endif placeholder="{{ $door === 'support' ? __('kaman-support.ws_search') : __('app.hub_search') }}" autocomplete="off">
        </label>
    @else
        <span></span>
    @endif
    <div class="ws-tools">
        @include('partials.lang-switcher')
        @include('partials.theme-toggle')
        {{ $tools ?? '' }}
        <details class="ws-user">
            <summary>
                <span class="ws-avatar" aria-hidden="true">{{ $initials !== '' ? $initials : 'K' }}</span>
                <span class="ws-identity">
                    <small>Workspace</small>
                    <strong>{{ auth()->user()->name }}</strong>
                </span>
            </summary>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">{{ __('app.sign_out') }}</button>
            </form>
        </details>
    </div>
</header>
