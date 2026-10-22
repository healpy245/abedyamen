@php
    $doors = [
        ['key' => 'support', 'href' => $supportHref, 'name' => __('app.hub_support_nav'), 'desc' => __('workspace.support_desc'), 'icon' => 'headset'],
        ['key' => 'development', 'href' => $devHref, 'name' => __('app.hub_dev'), 'desc' => __('workspace.development_desc'), 'icon' => 'code'],
        ['key' => 'agents', 'href' => $agentsHref, 'name' => __('app.hub_agents_nav'), 'desc' => __('workspace.agents_desc'), 'icon' => 'users'],
        ['key' => 'finance', 'href' => $financeHref, 'name' => __('app.hub_finance'), 'desc' => __('workspace.finance_desc'), 'icon' => 'chart'],
    ];
@endphp
<section class="cycle" id="hub-scene" @if($page !== 'dashboard') hidden @endif aria-label="{{ __('workspace.headline') }}">
    <div class="cycle-intro">
        <span class="cycle-eyebrow">{{ __('workspace.welcome') }}</span>
        <h1>{{ __('workspace.headline') }}</h1>
        <p>{{ __('workspace.subtitle') }}</p>
    </div>
    <div class="cycle-board">
        <div class="cycle-ring" aria-hidden="true"></div>
        <svg class="cycle-links" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <line class="cycle-link cycle-link--support" x1="50" y1="50" x2="18" y2="22"/>
            <line class="cycle-link cycle-link--development" x1="50" y1="50" x2="82" y2="22"/>
            <line class="cycle-link cycle-link--agents" x1="50" y1="50" x2="18" y2="78"/>
            <line class="cycle-link cycle-link--finance" x1="50" y1="50" x2="82" y2="78"/>
        </svg>
        <div class="cycle-hub">
            <img src="{{ asset('kaman.png') }}" alt="Kaman">
            <b>{{ __('workspace.all_in_one') }}</b>
        </div>
        @foreach ($doors as $door)
            <a class="cycle-door cycle-door--{{ $door['key'] }}" href="{{ $door['href'] }}" data-door="{{ $door['key'] }}" aria-label="{{ __('workspace.open') }} {{ $door['name'] }}">
                <span class="cycle-door__icon" aria-hidden="true">
                    @if ($door['icon'] === 'headset')
                        <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 16 0v5a2 2 0 0 1-2 2h-2v-6h4M4 17h4v-6H4v6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    @elseif ($door['icon'] === 'code')
                        <svg viewBox="0 0 24 24"><path d="m8 8-5 4 5 4M16 8l5 4-5 4M13 5l-2 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    @elseif ($door['icon'] === 'users')
                        <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    @else
                        <svg viewBox="0 0 24 24"><path d="M4 19V9M10 19V5M16 19v-7M22 19V8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    @endif
                </span>
                <span class="cycle-door__copy">
                    <strong>{{ $door['name'] }}</strong>
                    <small>{{ $door['desc'] }}</small>
                </span>
                <span class="cycle-door__arr" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M7 17 17 7M9 7h8v8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
            </a>
        @endforeach
    </div>
    <div class="cycle-foot">
        <a class="cycle-browse" data-ag-nav="restaurants" href="{{ route('workspace.overview', ['page' => 'restaurants']) }}">
            {{ __('app.browse_restaurants') }}
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
    </div>
</section>
