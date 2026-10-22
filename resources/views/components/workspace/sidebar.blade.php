@props(['door' => 'overview'])
@php
    $items = [
        ['id' => 'overview', 'href' => route('workspace.overview'), 'label' => __('app.hub_overview'), 'icon' => 'grid'],
        ['id' => 'support', 'href' => route('kaman-support.index'), 'label' => __('app.hub_support_nav'), 'icon' => 'headset'],
        ['id' => 'development', 'href' => auth()->user()?->is_admin ? route('app-development.reports.index') : route('app-development.tickets.index'), 'label' => __('app.hub_dev'), 'icon' => 'code'],
        ['id' => 'agents', 'href' => route('workspace.agents'), 'label' => __('app.hub_agents_nav'), 'icon' => 'bot'],
        ['id' => 'finance', 'href' => route('workspace.placeholder', 'finance'), 'label' => __('app.hub_finance'), 'icon' => 'chart'],
    ];
@endphp
<aside class="ws-side" aria-label="{{ __('app.name') }}">
    <a class="ws-brand" href="{{ route('workspace.overview') }}">
        <span class="ws-brandmark">
            <img src="{{ asset('kaman.png') }}" alt="">
        </span>
        <span class="ws-brand-text">
            <strong>{{ __('app.name') }}</strong>
            <small>Workspace</small>
        </span>
    </a>
    <div>
        <p class="ws-navlabel">{{ __('app.hub_nav') }}</p>
        <nav class="ws-nav" aria-label="{{ __('app.workspace') }}">
            @foreach($items as $item)
                <a href="{{ $item['href'] }}" class="ws-navitem {{ $door === $item['id'] ? 'is-active' : '' }}" @if($door === $item['id']) aria-current="page" @endif>
                    @include('components.workspace.icons', ['name' => $item['icon']])
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>
    <div class="ws-sidefoot">
        <strong>{{ __('app.hub_status_title') }}</strong>
        <p>{{ __('app.hub_status_body') }}</p>
        <span class="ws-ok"><i></i>{{ __('app.hub_status_ok') }}</span>
    </div>
</aside>
