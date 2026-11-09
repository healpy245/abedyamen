@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $overviewOpen = fn (string $key): bool => auth()->user()->canOpenSubDoor(\App\Enums\Door::Overview, $key);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="kaman-booting" data-door="overview">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('app.hub_title') }} — {{ __('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/agents-workspace.css') }}?v={{ filemtime(public_path('css/agents-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-hub.css') }}?v={{ filemtime(public_path('css/workspace-hub.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    @include('partials.workspace-ui')
    <link rel="stylesheet" href="{{ asset('css/ds.css') }}?v={{ filemtime(public_path('css/ds.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/ag-ds.css') }}?v={{ filemtime(public_path('css/ag-ds.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/boot-splash.css') }}?v={{ filemtime(public_path('css/boot-splash.css')) }}">
</head>
<body class="ag ws-page ds">
@include('partials.boot-splash')
    <x-workspace.shell door="overview">
        <x-slot:nav>
            <nav class="ws-float" data-ws-float aria-label="{{ __('app.hub_overview') }}">
                <span class="ws-float__indicator" data-ws-indicator></span>
                {{-- A locked tab has no data-ag-nav, so it goes to the server and gets the locked page. --}}
                <a href="{{ route('workspace.overview', ['page' => 'dashboard']) }}" @if($overviewOpen('dashboard')) data-ag-nav="dashboard" @endif @class(['is-selected' => $page === 'dashboard', 'is-locked' => ! $overviewOpen('dashboard')]) @if($page === 'dashboard') aria-current="page" @endif>@include('components.workspace.icons', ['name' => 'grid']){{ __('app.overview_dashboard') }}@unless($overviewOpen('dashboard'))<x-workspace.lock-mark />@endunless</a>
                <a href="{{ route('workspace.overview', ['page' => 'restaurants']) }}" @if($overviewOpen('restaurants')) data-ag-nav="restaurants" @endif @class(['is-selected' => $page === 'restaurants', 'is-locked' => ! $overviewOpen('restaurants')]) @if($page === 'restaurants') aria-current="page" @endif>@include('components.workspace.icons', ['name' => 'store']){{ __('app.overview_restaurants') }}@unless($overviewOpen('restaurants'))<x-workspace.lock-mark />@endunless</a>
            </nav>
        </x-slot:nav>
        @include('workspace.overview.hub')
        <div id="ag-view" class="ag-view" @if($page === 'dashboard') hidden @endif></div>
    </x-workspace.shell>
    <dialog id="ag-portal" class="ag-portal"></dialog>
    <div id="ag-toast" class="ag-toast" hidden></div>
    <script>
        window.AGENTS_BOOT = {
            page: @json($page),
            surface: 'overview',
            user: @json(auth()->user()->name),
            locale: @json($locale),
            images: @json(asset('images/agents')),
            companies: @json($companies ?? []),
            cities: @json($cities ?? []),
            finance: @json($finance ?? (object) []),
            financeLinks: @json(\App\Services\Finance\RestaurantFinance::links(auth()->user())),
            supportBoot: @json($supportBoot ?? null),
            stats: @json($stats),
            activity: @json($activity),
            links: {
                agents: @json($agentsHref),
                support: @json($supportHref),
                development: @json($devHref),
                restaurants: @json(route('workspace.overview', ['page' => 'restaurants'])),
                boarding: 'https://boarding.mfitgroup.com'
            },
            copy: {
                eyebrow: @json(__('app.overview_eyebrow')),
                title: @json(__('app.hub_title')),
                lead: @json(__('app.overview_lead')),
                browse: @json(__('app.browse_restaurants')),
                restaurantsEyebrow: @json(__('app.restaurants_eyebrow')),
                restaurants: @json(__('app.overview_restaurants')),
                restaurantsLead: @json(__('workspace.restaurants_lead')),
                allStatuses: @json(__('workspace.all_statuses')),
                add: @json(__('app.add_restaurant')),
                statRestaurants: @json(__('app.stat_restaurants')),
                statLeads: @json(__('app.stat_leads')),
                statTickets: @json(__('app.stat_tickets')),
                statTasks: @json(__('app.stat_tasks')),
                activity: @json(__('app.recent_activity')),
                quick: @json(__('app.quick_access')),
                viewAll: @json(__('app.view_all')),
                empty: @json(__('app.no_activity')),
                sales: @json(__('app.agents_leads')),
                support: @json(__('app.hub_support')),
                development: @json(__('app.hub_dev'))
            }
        };
    </script>
    <script src="{{ asset('js/ds.js') }}?v={{ filemtime(public_path('js/ds.js')) }}" defer></script>
    <script src="{{ asset('js/agents-workspace.js') }}?v={{ filemtime(public_path('js/agents-workspace.js')) }}" defer></script>
    <script src="{{ asset('js/workspace-hub.js') }}?v={{ filemtime(public_path('js/workspace-hub.js')) }}" defer></script>
</body>
</html>
