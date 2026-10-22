@php
    $user = auth()->user();
    $waiting = $navWaitingForQa ?? 0;
    $returned = $navReturnedFromQa ?? 0;
    $ticketBadge = $waiting > 0 ? $waiting : ($returned > 0 ? $returned : null);
    $ticketsActive = request()->routeIs('app-development.index')
        || (request()->routeIs('app-development.tickets.*') && ! request()->routeIs('app-development.tickets.create'));
    $tasksActive = request()->routeIs('app-development.tasks.*');
    $releasesActive = request()->routeIs('app-development.releases.*');
    $reportsActive = request()->routeIs('app-development.reports.*');
    $links = [];
    if ($user?->is_admin) {
        $links[] = ['app-development.reports.index', 'chart', __('app-development.nav.reports'), $reportsActive, null];
    }
    $links[] = ['app-development.tickets.index', 'ticket', __('app-development.nav.tickets'), $ticketsActive, $ticketBadge];
    $links[] = ['app-development.tasks.index', 'tasks', __('app-development.nav.tasks'), $tasksActive, null];
    $links[] = ['app-development.releases.index', 'apk', __('app-development.nav.releases'), $releasesActive, null];
@endphp

<nav class="ws-float" data-ws-float aria-label="{{ __('app-development.tag') }}">
    <span class="ws-float__indicator" data-ws-indicator></span>
    @foreach($links as [$routeName, $icon, $label, $active, $badge])
        <a href="{{ route($routeName) }}"
           @class(['is-selected' => $active])
           @if($active) aria-current="page" @endif>
            @include('app-development.partials.icon', ['name' => $icon])
            <span>{{ $label }}</span>
            @if($badge)
                <em class="dev-nav-badge">{{ $badge }}</em>
            @endif
        </a>
    @endforeach
</nav>
