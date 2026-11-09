@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $initials = collect(preg_split('/\s+/', trim(auth()->user()->name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $agentsOpen = fn (string $key): bool => auth()->user()->canOpenSubDoor(\App\Enums\Door::Agents, $key);
    $crm = [
        'me' => auth()->id(),
        'csrf' => csrf_token(),
        'leads' => $agentsOpen('leads') ? route('mobile.agents.leads') : null,
        'calendar' => $agentsOpen('calendar') ? route('mobile.agents.calendar') : null,
        'leadWrites' => route('workspace.agents.leads.store'),
        'calendarWrites' => route('workspace.agents.calendar.store'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="kaman-booting" data-door="agents">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('app.agents_title') }} — {{ __('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/agents-workspace.css') }}?v={{ filemtime(public_path('css/agents-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    @include('partials.workspace-ui')
    <link rel="stylesheet" href="{{ asset('css/ds.css') }}?v={{ filemtime(public_path('css/ds.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/ag-ds.css') }}?v={{ filemtime(public_path('css/ag-ds.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/boot-splash.css') }}?v={{ filemtime(public_path('css/boot-splash.css')) }}">
</head>
<body class="ag ws-page ds">
@include('partials.boot-splash')
    <x-workspace.shell door="agents">
        <x-slot:tools>
            <button type="button" class="ws-icon" data-ag-bell aria-expanded="false" aria-label="{{ __('app.agents_title') }}">
                @include('components.workspace.icons', ['name' => 'bell'])
            </button>
            <div class="ag-bell-panel" data-ag-bell-panel hidden></div>
        </x-slot:tools>
        <x-slot:nav>
            <nav class="ws-float" data-ws-float aria-label="{{ __('app.agents_title') }}">
                <span class="ws-float__indicator" data-ws-indicator></span>
                {{-- A locked tab has no data-ag-nav, so it goes to the server and gets the locked page. --}}
                <a href="{{ route('workspace.agents', ['page' => 'leads']) }}" @if($agentsOpen('leads')) data-ag-nav="leads" @endif @class(['is-selected' => $page === 'leads', 'is-locked' => ! $agentsOpen('leads')]) @if($page === 'leads') aria-current="page" @endif>@include('components.workspace.icons', ['name' => 'target']){{ __('app.agents_leads') }}@unless($agentsOpen('leads'))<x-workspace.lock-mark />@endunless</a>
                <a href="{{ route('workspace.agents', ['page' => 'calendar']) }}" @if($agentsOpen('calendar')) data-ag-nav="calendar" @endif @class(['is-selected' => $page === 'calendar', 'is-locked' => ! $agentsOpen('calendar')]) @if($page === 'calendar') aria-current="page" @endif>@include('components.workspace.icons', ['name' => 'calendar']){{ __('app.agents_calendar') }}@unless($agentsOpen('calendar'))<x-workspace.lock-mark />@endunless</a>
                <a href="{{ route('workspace.agents.sales') }}" @class(['is-locked' => ! $agentsOpen('sales')])>@include('components.workspace.icons', ['name' => 'receipt']){{ __('app.agents_sales') }}@unless($agentsOpen('sales'))<x-workspace.lock-mark />@endunless</a>
                <a href="{{ route('workspace.agents.packages') }}" @class(['is-locked' => ! $agentsOpen('packages')])>@include('components.workspace.icons', ['name' => 'box']){{ __('app.agents_packages') }}@unless($agentsOpen('packages'))<x-workspace.lock-mark />@endunless</a>
            </nav>
        </x-slot:nav>
        <div id="ag-view" class="ag-view"></div>
    </x-workspace.shell>
    <dialog id="ag-portal" class="ag-portal"></dialog>
    <div id="ag-toast" class="ag-toast" hidden></div>
    <script>
        window.AGENTS_BOOT = {
            page: @json($page),
            surface: 'agents',
            user: @json(auth()->user()->name),
            locale: @json($locale),
            images: @json(asset('images/agents')),
            companies: @json($companies ?? []),
            cities: @json($cities ?? []),
            finance: @json($finance ?? (object) []),
            financeLinks: @json(\App\Services\Finance\RestaurantFinance::links(auth()->user())),
            supportBoot: @json($supportBoot ?? null),
            crm: @json($crm),
        };
    </script>
    <script src="{{ asset('js/ds.js') }}?v={{ filemtime(public_path('js/ds.js')) }}" defer></script>
    <script src="{{ asset('js/agents-workspace.js') }}?v={{ filemtime(public_path('js/agents-workspace.js')) }}" defer></script>
</body>
</html>
